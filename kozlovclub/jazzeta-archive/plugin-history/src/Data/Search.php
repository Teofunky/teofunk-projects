<?php

namespace Kozlov\Gazeta\Data;

use Kozlov\Gazeta\Domain\Stemmer;
use Kozlov\Gazeta\Domain\Topics;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Search over published materials (0.5.1).
 *
 * The table wp_es_search is a derived index: one row per published article, its headline and
 * text normalised for matching (lower case, «ё» → «е», letters and digits only). It can be
 * emptied and rebuilt from wp_es_articles at any moment and never decides what readers see:
 * every query joins the current publication state, so a withdrawn issue never appears even
 * while the index is stale.
 *
 * Freshness follows the catalog counter that every version since 0.3.0 increments on publish
 * and withdraw. The state row `search` remembers the counter the index matches. Publishing in
 * 0.5.1 updates the one issue in the same transaction; a mismatch (for example after a rollback
 * to an older version) leads to a full rebuild: on upgrade, on the next desk page or by WP-Cron.
 *
 * 0.5.5: the column `topics` holds the rubric and the tags of each article (Domain\Topics),
 * space-padded, so «тема: Релизы» is one LIKE on the index. Every activation and «Завершить
 * обновление» rebuild the index, because the tags are computed from the text when indexing.
 */
final class Search
{
    public const PER_PAGE = 20;
    public const MAX_QUERY = 100;
    public const MAX_TERMS = 6;
    public const MAX_PAGES = 50;
    public const CRON_HOOK = 'es_search_rebuild';
    private const BATCH = 100;
    private const COUNTS_KEY = 'es_topic_counts';

    /** Words that would match almost every text as a prefix. */
    private const STOP = [
        'и', 'в', 'во', 'на', 'с', 'со', 'к', 'ко', 'по', 'о', 'об', 'от', 'до', 'за', 'из', 'у', 'не', 'ни',
        'а', 'но', 'для', 'при', 'про', 'же', 'ли', 'бы', 'что', 'как', 'это', 'the', 'a', 'an', 'of', 'and', 'in', 'on',
    ];

    /** Search needs the PHP mbstring module (WordPress polyfills only part of it). */
    public static function available(): bool
    {
        return function_exists('mb_strtolower') && function_exists('mb_strpos') && function_exists('mb_strrpos');
    }

    // ---------------------------------------------------------------- text

    /** Lower case with «ё» folded; keeps the character count of the input (used for highlighting). */
    public static function fold(string $text): string
    {
        return strtr(mb_strtolower($text, 'UTF-8'), ['ё' => 'е']);
    }

    /** Visible text of a stored fragment (body paragraphs keep a few inline tags). */
    public static function plain(string $html): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    /** Matching form: folded, letters and digits only, single spaces, padded so ' term' finds word starts. */
    public static function normalize(string $text): string
    {
        $text = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', self::fold($text));
        return ' ' . trim($text) . ' ';
    }

    /** Stems of the query words, without stop words and duplicates. @return string[] */
    public static function terms(string $query): array
    {
        $words = explode(' ', trim(self::normalize(mb_substr($query, 0, self::MAX_QUERY))));
        $terms = [];
        foreach ($words as $word) {
            if ($word === '') {
                continue;
            }
            $stem = Stemmer::stem($word);
            $stem = mb_strlen($stem) >= 2 || preg_match('/^\p{N}+$/u', $word) ? $stem : $word;
            if (in_array($word, self::STOP, true) || (mb_strlen($stem) < 2 && !preg_match('/^\p{N}+$/u', $stem))) {
                continue;
            }
            $terms[$stem] = true;
            if (count($terms) >= self::MAX_TERMS) {
                break;
            }
        }
        return array_keys($terms);
    }

    // ---------------------------------------------------------------- index

    public static function isFresh(): bool
    {
        $state = self::state();
        return $state !== null && $state === Db::catalogVersion();
    }

    /** Index rows currently stored (for the integrity screen). */
    public static function size(): int
    {
        $wpdb = Db::wpdb();
        $suppress = $wpdb->suppress_errors(true);
        $count = $wpdb->get_var('SELECT COUNT(*) FROM ' . Db::table('search'));
        $wpdb->suppress_errors($suppress);
        return (int) $count;
    }

    /**
     * Called by publish and withdraw inside their transaction, right after the catalog counter
     * was incremented: a fresh index follows the one issue; a stale one waits for a rebuild.
     */
    public static function afterChange(int $issueId): void
    {
        if (!self::available()) {
            return;
        }
        $catalog = Db::catalogVersion();
        if (self::state() !== $catalog - 1) {
            return;
        }
        self::syncIssue($issueId);
        self::setState($catalog);
    }

    /** Replaces the rows of one issue with its current public articles (none unless published). */
    public static function syncIssue(int $issueId): void
    {
        $wpdb = Db::wpdb();
        Db::must($wpdb->query($wpdb->prepare('DELETE FROM ' . Db::table('search') . ' WHERE issue_id=%d', $issueId)));
        self::insertRows(self::sourceRows('i.id=' . (int) $issueId));
    }

    /** Full rebuild from the published articles. Idempotent; returns the number of indexed articles. */
    public static function rebuild(): int
    {
        $wpdb = Db::wpdb();
        $own = !Db::inTransaction();
        $catalog = Db::catalogVersion();
        $count = 0;
        try {
            if ($own) {
                Db::begin();
            }
            Db::must($wpdb->query('DELETE FROM ' . Db::table('search')));
            $ids = array_map('intval', (array) $wpdb->get_col('SELECT id FROM ' . Db::table('issues') . " WHERE state='published' ORDER BY id"));
            foreach (array_chunk($ids, 20) as $chunk) {
                $rows = self::sourceRows('i.id IN (' . implode(',', $chunk) . ')');
                $count += count($rows);
                self::insertRows($rows);
            }
            self::setState($catalog);
            if ($own) {
                Db::commit();
            }
        } catch (\Throwable $e) {
            if ($own) {
                Db::rollback();
            }
            throw $e;
        }
        self::forgetCounts();
        return $count;
    }

    /**
     * Rebuilds now when stale (or always with $force: upgrades, new tagging rules); never throws —
     * the index is a convenience, not the newspaper.
     */
    public static function ensureFresh(bool $force = false): void
    {
        try {
            if (self::available() && ($force || !self::isFresh())) {
                self::rebuild();
            }
        } catch (\Throwable $e) {
            if (function_exists('error_log')) {
                error_log('Kozlov Gazeta: search index rebuild failed: ' . $e->getMessage());
            }
        }
    }

    /** WP-Cron hook callback. */
    public static function cron(): void
    {
        self::ensureFresh();
    }

    private static function scheduleRebuild(): void
    {
        if (function_exists('wp_next_scheduled') && !wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_single_event(time() + 5, self::CRON_HOOK);
        }
    }

    /** @return array<int,array> rows ready for the index */
    private static function sourceRows(string $issueCondition): array
    {
        $wpdb = Db::wpdb();
        $rows = (array) $wpdb->get_results(
            'SELECT a.id, a.issue_id, a.external_id, a.public_json, i.edition_date FROM ' . Db::table('articles') . ' a JOIN '
            . Db::table('issues') . " i ON i.id=a.issue_id WHERE i.state='published' AND a.public_json IS NOT NULL AND " . $issueCondition,
            ARRAY_A
        );
        $out = [];
        $briefs = [];
        $manual = [];
        foreach ($rows as $row) {
            $issueId = (int) $row['issue_id'];
            if (!isset($briefs[$issueId])) {
                $meta = Meta::forIssue($issueId, 'public');
                $briefs[$issueId] = $meta['briefs'];
                $manual[$issueId] = $meta['tags'] ?? [];
            }
            $article = Db::decode($row['public_json']);
            $tags = Topics::effective($article, $manual[$issueId][(string) $row['external_id']] ?? null);
            $topics = Topics::topics(is_string($article['category'] ?? null) ? $article['category'] : '', $tags);
            $parts = [(string) ($article['lead'] ?? ''), (string) ($article['summary'] ?? '')];
            foreach ((array) ($article['body_paragraphs'] ?? []) as $paragraph) {
                $parts[] = is_scalar($paragraph) ? self::plain((string) $paragraph) : '';
            }
            foreach ((array) ($briefs[$issueId][(string) $row['external_id']] ?? []) as $text) {
                $parts[] = (string) $text;
            }
            // 0.5.6: news sources only; the picture's page and licence are not what the material is about.
            foreach (\Kozlov\Gazeta\Domain\Credit::split((array) ($article['sources'] ?? []))[0] as $source) {
                $parts[] = is_array($source) ? (string) ($source['publisher'] ?? '') : '';
            }
            $out[] = [
                'article_id' => (int) $row['id'],
                'issue_id' => $issueId,
                'edition_date' => (string) $row['edition_date'],
                'title' => self::normalize((string) ($article['headline'] ?? '')),
                'body' => self::normalize(implode(' ', $parts)),
                'topics' => $topics ? ' ' . implode(' ', $topics) . ' ' : '',
            ];
        }
        return $out;
    }

    private static function insertRows(array $rows): void
    {
        $wpdb = Db::wpdb();
        $table = Db::table('search');
        $now = Db::now();
        foreach (array_chunk($rows, self::BATCH) as $chunk) {
            $values = [];
            foreach ($chunk as $row) {
                $values[] = $wpdb->prepare('(%d,%d,%s,%s,%s,%s,%s)', $row['article_id'], $row['issue_id'], $row['edition_date'], $row['title'], $row['body'], substr($row['topics'], 0, 255), $now);
            }
            Db::must($wpdb->query("INSERT INTO {$table} (article_id,issue_id,edition_date,title,body,topics,indexed_at) VALUES " . implode(',', $values)));
        }
    }

    private static function state(): ?int
    {
        $wpdb = Db::wpdb();
        $value = $wpdb->get_var('SELECT value FROM ' . Db::table('state') . " WHERE name='search'");
        return $value === null ? null : (int) $value;
    }

    private static function setState(int $catalog): void
    {
        $wpdb = Db::wpdb();
        $table = Db::table('state');
        Db::must($wpdb->query($wpdb->prepare("INSERT INTO {$table} (name,value) VALUES ('search',%d) ON DUPLICATE KEY UPDATE value=VALUES(value)", $catalog)));
    }

    // ---------------------------------------------------------------- query

    /**
     * One page of results, best first: headline matches weigh three times a text match; equal
     * scores go newest first. Every query word (as a word prefix) must occur.
     * $topic (0.5.5): only materials of this rubric or tag; without words they go newest first.
     * @return array{query:string,terms:string[],topic:string,total:int,pages:int,page:int,rows:array<int,array>,fresh:bool}
     */
    public static function find(string $query, int $page, string $topic = ''): array
    {
        $page = max(1, min(self::MAX_PAGES, $page));
        $topic = Topics::isTopic($topic) ? $topic : '';
        if (!self::available()) {
            return ['query' => trim(substr($query, 0, self::MAX_QUERY)), 'terms' => [], 'topic' => $topic, 'total' => 0, 'pages' => 0, 'page' => $page, 'rows' => [], 'fresh' => false, 'unavailable' => true];
        }
        $query = trim(mb_substr($query, 0, self::MAX_QUERY));
        $terms = self::terms($query);
        $out = ['query' => $query, 'terms' => $terms, 'topic' => $topic, 'total' => 0, 'pages' => 0, 'page' => $page, 'rows' => [], 'fresh' => true];
        if (!$terms && $topic === '') {
            return $out;
        }
        $out['fresh'] = self::isFresh();
        if (!$out['fresh']) {
            self::scheduleRebuild();
        }
        $wpdb = Db::wpdb();
        $where = [];
        $whereArgs = [];
        $score = [];
        $scoreArgs = [];
        foreach ($terms as $term) {
            $like = '% ' . $wpdb->esc_like($term) . '%';
            $where[] = '(s.title LIKE %s OR s.body LIKE %s)';
            array_push($whereArgs, $like, $like);
            $score[] = '(s.title LIKE %s)*3 + (s.body LIKE %s)';
            array_push($scoreArgs, $like, $like);
        }
        if ($topic !== '') {
            $where[] = 's.topics LIKE %s';
            $whereArgs[] = '% ' . $wpdb->esc_like($topic) . ' %';
        }
        if (!$score) {
            $score[] = '0';
        }
        $from = ' FROM ' . Db::table('search') . ' s JOIN ' . Db::table('issues') . " i ON i.id=s.issue_id AND i.state='published' JOIN "
            . Db::table('articles') . ' a ON a.id=s.article_id AND a.public_json IS NOT NULL WHERE ' . implode(' AND ', $where);
        $suppress = $wpdb->suppress_errors(true);
        $total = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*)' . $from, $whereArgs));
        $out['total'] = $total;
        $out['pages'] = min(self::MAX_PAGES, (int) ceil($total / self::PER_PAGE));
        if ($total > 0 && $page <= $out['pages']) {
            $rows = $wpdb->get_results($wpdb->prepare(
                'SELECT a.id, a.slug, a.public_json, a.position, i.id AS issue_id, i.edition_date, i.number, s.topics, (' . implode(' + ', $score) . ') AS score'
                . $from . ' ORDER BY score DESC, i.edition_date DESC, a.position ASC, a.id ASC LIMIT %d OFFSET %d',
                array_merge($scoreArgs, $whereArgs, [self::PER_PAGE, ($page - 1) * self::PER_PAGE])
            ), ARRAY_A);
            foreach ((array) $rows as $row) {
                try {
                    $row['article'] = Db::decode($row['public_json']);
                } catch (\Throwable $e) {
                    continue;
                }
                unset($row['public_json']);
                $out['rows'][] = $row;
            }
        }
        $wpdb->suppress_errors($suppress);
        return $out;
    }

    /**
     * How many published materials carry each rubric and tag: [key => count], most first.
     * Cached per catalog version (a publication or withdrawal changes it). Never throws.
     * @return array<string,int>
     */
    public static function topicCounts(): array
    {
        if (!self::available()) {
            return [];
        }
        try {
            $catalog = Db::catalogVersion();
            $cached = function_exists('get_transient') ? get_transient(self::COUNTS_KEY) : false;
            if (is_array($cached) && ($cached['catalog'] ?? null) === $catalog && is_array($cached['counts'] ?? null)) {
                return $cached['counts'];
            }
            $wpdb = Db::wpdb();
            $suppress = $wpdb->suppress_errors(true);
            $rows = $wpdb->get_col('SELECT s.topics FROM ' . Db::table('search') . ' s JOIN ' . Db::table('issues') . " i ON i.id=s.issue_id AND i.state='published' JOIN "
                . Db::table('articles') . ' a ON a.id=s.article_id AND a.public_json IS NOT NULL');
            $wpdb->suppress_errors($suppress);
            $counts = [];
            foreach ((array) $rows as $topics) {
                foreach (explode(' ', trim((string) $topics)) as $key) {
                    if ($key !== '' && Topics::isTopic($key)) {
                        $counts[$key] = ($counts[$key] ?? 0) + 1;
                    }
                }
            }
            arsort($counts);
            if (function_exists('set_transient')) {
                set_transient(self::COUNTS_KEY, ['catalog' => $catalog, 'counts' => $counts], DAY_IN_SECONDS);
            }
            return $counts;
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function forgetCounts(): void
    {
        if (function_exists('delete_transient')) {
            delete_transient(self::COUNTS_KEY);
        }
    }

    // ---------------------------------------------------------------- display

    /** Escaped text with every word that starts with a query stem wrapped in <mark>. */
    public static function highlight(string $text, array $terms): string
    {
        if (!$terms) {
            return esc_html($text);
        }
        $folded = self::fold($text);
        if (mb_strlen($folded) !== mb_strlen($text)) {
            return esc_html($text);
        }
        $quoted = array_map(static function ($t) {
            return preg_quote((string) $t, '/');
        }, $terms);
        $pattern = '/(?<![\p{L}\p{N}])(?:' . implode('|', $quoted) . ')[\p{L}\p{N}]*/u';
        if (!preg_match_all($pattern, $folded, $matches, PREG_OFFSET_CAPTURE)) {
            return esc_html($text);
        }
        $html = '';
        $cursor = 0;
        foreach ($matches[0] as [$word, $byte]) {
            $start = mb_strlen(substr($folded, 0, $byte));
            $length = mb_strlen($word);
            $html .= esc_html(mb_substr($text, $cursor, $start - $cursor)) . '<mark>' . esc_html(mb_substr($text, $start, $length)) . '</mark>';
            $cursor = $start + $length;
        }
        return $html . esc_html(mb_substr($text, $cursor));
    }

    /** About $length characters around the first match (or the beginning), highlighted. */
    public static function snippet(string $text, array $terms, int $length = 220): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        $total = mb_strlen($text);
        $start = 0;
        if ($terms && $total > $length) {
            $quoted = array_map(static function ($t) {
                return preg_quote((string) $t, '/');
            }, $terms);
            $folded = self::fold($text);
            if (mb_strlen($folded) === $total && preg_match('/(?<![\p{L}\p{N}])(?:' . implode('|', $quoted) . ')/u', $folded, $m, PREG_OFFSET_CAPTURE)) {
                $hit = mb_strlen(substr($folded, 0, $m[0][1]));
                $start = max(0, min($hit - 60, $total - $length));
            }
        }
        if ($start > 0) {
            $space = mb_strpos($text, ' ', $start);
            $start = $space !== false && $space < $start + 20 ? $space + 1 : $start;
        }
        $cut = mb_substr($text, $start, $length);
        $end = $start + mb_strlen($cut) < $total;
        if ($end) {
            $space = mb_strrpos($cut, ' ');
            $cut = $space !== false && $space > $length - 40 ? mb_substr($cut, 0, $space) : $cut;
            $cut = rtrim($cut, " ,;:—-");
        }
        return ($start > 0 ? '…' : '') . self::highlight($cut, $terms) . ($end ? '…' : '');
    }
}
