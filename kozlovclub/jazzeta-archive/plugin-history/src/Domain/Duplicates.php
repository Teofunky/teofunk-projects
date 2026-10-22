<?php

namespace Kozlov\Gazeta\Domain;

use Kozlov\Gazeta\Data\Db;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Exact duplicate detection: same event, same text, same headline, same source publication.
 * No network requests and no claim of semantic understanding (that belongs to the collector).
 */
final class Duplicates
{
    public const REASONS = [
        'exact_text' => 'полностью совпадает текст',
        'event_id' => 'то же событие',
        'headline' => 'такой же заголовок',
        'source_identity' => 'та же публикация источника',
    ];

    /** URL without fragment, tracking parameters and default port; query keys sorted. */
    public static function canonicalUrl(string $url): string
    {
        $parts = wp_parse_url($url);
        if (!$parts) {
            return '';
        }
        $pairs = [];
        foreach (explode('&', $parts['query'] ?? '') as $pair) {
            if ($pair === '') {
                continue;
            }
            $key = rawurldecode(explode('=', $pair, 2)[0]);
            if (preg_match('/^(utm_.+|fbclid|gclid|yclid|mc_cid|mc_eid)$/i', $key)) {
                continue;
            }
            $pairs[] = $pair;
        }
        sort($pairs, SORT_STRING);
        $path = $parts['path'] ?? '/';
        if ($path === '') {
            $path = '/';
        }
        $port = $parts['port'] ?? 443;
        return strtolower((string) ($parts['scheme'] ?? '')) . '://' . strtolower((string) ($parts['host'] ?? ''))
            . ((int) $port !== 443 ? ':' . $port : '') . $path . ($pairs ? '?' . implode('&', $pairs) : '');
    }

    public static function fingerprint(string $text): string
    {
        $text = html_entity_decode(wp_strip_all_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/\s+/u', ' ', trim($text));
        $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
        return hash('sha256', $text);
    }

    public static function externalKey(array $source): string
    {
        if (empty($source['publication_id'])) {
            return '';
        }
        return hash('sha256', strtolower((string) wp_parse_url($source['url'], PHP_URL_HOST)) . '|' . $source['publication_id']);
    }

    private static function eventOf(array $article): string
    {
        return (string) ($article['event_id'] ?? $article['story_group_id']);
    }

    /** Rebuilds derived identity rows for one layer of an issue. Called inside a transaction. */
    public static function sync(int $issueId, string $layer = 'draft'): void
    {
        $wpdb = Db::wpdb();
        foreach (\Kozlov\Gazeta\Data\Issues::articleRows($issueId, false) as $row) {
            foreach (['article_sources', 'identities', 'relations'] as $table) {
                Db::must($wpdb->delete(Db::table($table), ['article_id' => $row['id'], 'layer' => $layer]));
            }
            if (!(int) $row['active']) {
                continue;
            }
            $article = Db::decode($row['draft_json']);
            $event = self::eventOf($article);
            Db::must($wpdb->query($wpdb->prepare(
                'INSERT IGNORE INTO ' . Db::table('events') . ' (event_id,title,created_at) VALUES (%s,%s,%s)',
                $event,
                $article['headline'],
                Db::now()
            )));
            Db::must($wpdb->insert(Db::table('identities'), [
                'article_id' => $row['id'],
                'layer' => $layer,
                'event_id' => $event,
                'text_hash' => self::fingerprint(Validator::renderBody($article['body_paragraphs'])),
                'title_hash' => self::fingerprint($article['headline']),
            ]));
            $publications = Db::table('publications');
            foreach ($article['sources'] as $source) {
                $url = self::canonicalUrl($source['url']);
                $hash = hash('sha256', $url);
                Db::must($wpdb->query($wpdb->prepare(
                    "INSERT IGNORE INTO {$publications} (url_hash,canonical_url,publisher,title,external_key,content_hash,created_at) VALUES (%s,%s,%s,%s,%s,%s,%s)",
                    $hash,
                    $url,
                    $source['publisher'],
                    $source['title'],
                    self::externalKey($source),
                    $source['content_hash'] ?? '',
                    Db::now()
                )));
                $publicationId = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$publications} WHERE url_hash=%s", $hash));
                // Evidence is stored per appearance: editing a source never rewrites archived identities.
                Db::must($wpdb->insert(Db::table('article_sources'), [
                    'article_id' => $row['id'],
                    'source_id' => $source['id'],
                    'publication_id' => $publicationId,
                    'external_key' => self::externalKey($source),
                    'content_hash' => $source['content_hash'] ?? '',
                    'layer' => $layer,
                ]));
            }
            foreach ($article['previous_article_ids'] ?? [] as $slug) {
                $prior = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . Db::table('articles') . ' WHERE slug=%s', $slug));
                if ($prior && $prior !== (int) $row['id']) {
                    Db::must($wpdb->insert(Db::table('relations'), [
                        'article_id' => $row['id'],
                        'related_article_id' => $prior,
                        'layer' => $layer,
                        'relation_type' => 'development',
                        'reason' => $article['development_note'] ?? '',
                        'reviewed_by' => (int) ($row['reviewed_by'] ?? 0),
                    ]));
                }
            }
        }
    }

    /**
     * Published articles of other issues that match this article.
     * @return array<int,array{article_id:int,slug:string,date:string,reasons:string[]}>
     */
    public static function matches(array $article, int $issueId): array
    {
        $wpdb = Db::wpdb();
        $identities = Db::table('identities');
        $articles = Db::table('articles');
        $issues = Db::table('issues');
        $event = self::eventOf($article);
        $text = self::fingerprint(Validator::renderBody($article['body_paragraphs']));
        $title = self::fingerprint($article['headline']);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT a.id,a.slug,i.edition_date,x.event_id,x.text_hash,x.title_hash FROM {$identities} x
             JOIN {$articles} a ON a.id=x.article_id JOIN {$issues} i ON i.id=a.issue_id
             WHERE x.layer='public' AND i.state='published' AND i.id<>%d AND (x.event_id=%s OR x.text_hash=%s OR x.title_hash=%s)
             ORDER BY i.edition_date DESC LIMIT 51",
            $issueId,
            $event,
            $text,
            $title
        ), ARRAY_A) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $reason = $row['text_hash'] === $text ? 'exact_text' : ($row['event_id'] === $event ? 'event_id' : 'headline');
            $out[$row['id']] = ['article_id' => (int) $row['id'], 'slug' => $row['slug'], 'date' => $row['edition_date'], 'reasons' => [$reason]];
        }
        $publications = Db::table('publications');
        $links = Db::table('article_sources');
        foreach ($article['sources'] as $source) {
            $where = $wpdb->prepare('p.url_hash=%s', hash('sha256', self::canonicalUrl($source['url'])));
            $external = self::externalKey($source);
            if ($external !== '') {
                $where .= $wpdb->prepare(' OR l.external_key=%s', $external);
            }
            if (!empty($source['content_hash'])) {
                $where .= $wpdb->prepare(' OR l.content_hash=%s', $source['content_hash']);
            }
            $found = $wpdb->get_results($wpdb->prepare(
                "SELECT DISTINCT a.id,a.slug,i.edition_date FROM {$publications} p
                 JOIN {$links} l ON l.publication_id=p.id JOIN {$articles} a ON a.id=l.article_id JOIN {$issues} i ON i.id=a.issue_id
                 WHERE l.layer='public' AND i.state='published' AND i.id<>%d AND ({$where})
                 ORDER BY i.edition_date DESC LIMIT 51",
                $issueId
            ), ARRAY_A) ?: [];
            foreach ($found as $row) {
                if (!isset($out[$row['id']])) {
                    $out[$row['id']] = ['article_id' => (int) $row['id'], 'slug' => $row['slug'], 'date' => $row['edition_date'], 'reasons' => []];
                }
                $out[$row['id']]['reasons'][] = 'source_identity';
            }
        }
        return array_values($out);
    }

    /** Issue-level duplicate problems (inside the issue and against the published archive). */
    public static function blockers(array $issue): array
    {
        $wpdb = Db::wpdb();
        $errors = [];
        $seenEvents = [];
        $seenUrls = [];
        $seenText = [];
        $seenExternal = [];
        $seenHashes = [];
        foreach (\Kozlov\Gazeta\Data\Issues::articleRows((int) $issue['id']) as $row) {
            $article = Db::decode($row['draft_json']);
            $event = self::eventOf($article);
            $text = self::fingerprint(Validator::renderBody($article['body_paragraphs']));
            if (isset($seenEvents[$event])) {
                $errors[] = 'Два материала одного события внутри выпуска: ' . $event . '. Объедините их.';
            }
            $seenEvents[$event] = true;
            if (isset($seenText[$text])) {
                $errors[] = 'Дословно повторён текст внутри выпуска: ' . $article['headline'];
            }
            $seenText[$text] = true;
            foreach ($article['sources'] as $source) {
                $url = self::canonicalUrl($source['url']);
                if (isset($seenUrls[$url]) && $seenUrls[$url] !== $article['id']) {
                    $errors[] = 'Одна публикация источника используется в разных новостях номера: ' . $url;
                }
                $seenUrls[$url] = $article['id'];
                $external = self::externalKey($source);
                $hash = $source['content_hash'] ?? '';
                if (($external !== '' && isset($seenExternal[$external]) && $seenExternal[$external] !== $article['id'])
                    || ($hash !== '' && isset($seenHashes[$hash]) && $seenHashes[$hash] !== $article['id'])) {
                    $errors[] = 'Одинаковый ID или текст публикации источника в разных новостях: ' . $article['headline'];
                }
                if ($external !== '') {
                    $seenExternal[$external] = $article['id'];
                }
                if ($hash !== '') {
                    $seenHashes[$hash] = $article['id'];
                }
            }
            $matches = self::matches($article, (int) $issue['id']);
            if (count($matches) > 50) {
                $errors[] = 'Слишком много совпадений: уточните событие и источники ' . $article['headline'];
            }
            foreach ($matches as $match) {
                if (in_array('exact_text', $match['reasons'], true)) {
                    $errors[] = 'Повтор полного текста из выпуска ' . $match['date'] . ': «' . $article['headline'] . '».';
                    continue;
                }
                if (trim($article['development_note'] ?? '') === '' || !in_array($match['slug'], $article['previous_article_ids'] ?? [], true)) {
                    $errors[] = 'Возможный повтор: «' . $article['headline'] . '» → ' . $match['slug'] . '. Свяжите публикации и объясните существенный новый факт.';
                }
            }
            foreach ($article['previous_article_ids'] ?? [] as $slug) {
                $prior = $wpdb->get_row($wpdb->prepare(
                    'SELECT i.edition_date,i.state FROM ' . Db::table('articles') . ' a JOIN ' . Db::table('issues') . ' i ON i.id=a.issue_id
                     WHERE a.slug=%s AND a.public_json IS NOT NULL AND i.id<>%d',
                    $slug,
                    (int) $issue['id']
                ), ARRAY_A);
                if (!$prior || $prior['state'] !== 'published' || $prior['edition_date'] > $issue['edition_date']) {
                    $errors[] = 'Некорректная связь с предыдущей публикацией: ' . $slug;
                }
                if (trim($article['development_note'] ?? '') === '') {
                    $errors[] = 'Для связи с предыдущей публикацией нужен новый факт.';
                }
            }
        }
        return array_values(array_unique($errors));
    }
}
