<?php

namespace Kozlov\Gazeta\Data;

use Kozlov\Gazeta\Domain\Crossword;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Editorial properties that the JSON exchange format does not carry: the issue cover and,
 * per article, the blocks «Почему важно / Контекст / Что дальше» and the reader-visible list
 * of checked facts, (0.5.1) the focus point of a photo and (0.5.2) the crossword of the week.
 * Stored in the additive table wp_es_meta, in two layers: `draft` is what
 * the desk edits, `public` is a copy taken inside the publish transaction.
 *
 * Versions 0.3.x and 0.4.0 do not know this table: after a rollback they ignore it, and the
 * data reappears unchanged when 0.5 is installed again.
 */
final class Meta
{
    public const LAYERS = ['draft', 'public'];
    public const BRIEF_KEYS = ['why', 'context', 'next'];
    public const BRIEF_MAX = 600;
    public const COVER_KINDS = ['lead', 'photo', 'plate', 'none'];
    /** Default focus: the centre of the picture. Stored only when it differs. */
    public const FOCUS_CENTER = ['x' => 50, 'y' => 50];

    /** @var array<string,array> per-request cache: "issueId:layer" => result of forIssue() */
    private static $cache = [];

    /**
     * Everything the presenter needs for one issue, keyed by article external id.
     * A missing table (upgrade not finished) reads as "no extras", never as an error page.
     * @return array{cover:?array,briefs:array<string,array>,facts:array<string,array>,facts_visible:array<string,bool>,focus:array<string,array>,crossword:?array}
     */
    public static function forIssue(int $issueId, string $layer): array
    {
        $key = $issueId . ':' . $layer;
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }
        $empty = ['cover' => null, 'briefs' => [], 'facts' => [], 'facts_visible' => [], 'focus' => [], 'crossword' => null];
        if ($issueId <= 0 || !in_array($layer, self::LAYERS, true)) {
            return $empty;
        }
        $wpdb = Db::wpdb();
        $meta = Db::table('meta');
        $articles = Db::table('articles');
        $suppress = $wpdb->suppress_errors(true);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT m.object_type, m.meta_key, m.meta_value, a.external_id
             FROM {$meta} m LEFT JOIN {$articles} a ON m.object_type='article' AND a.id=m.object_id
             WHERE m.layer=%s AND ((m.object_type='issue' AND m.object_id=%d) OR (m.object_type='article' AND a.issue_id=%d))",
            $layer,
            $issueId,
            $issueId
        ), ARRAY_A);
        $wpdb->suppress_errors($suppress);
        $out = $empty;
        foreach ((array) $rows as $row) {
            $value = json_decode((string) $row['meta_value'], true);
            if ($row['object_type'] === 'issue' && $row['meta_key'] === 'cover') {
                $out['cover'] = self::cleanCover($value);
                continue;
            }
            if ($row['object_type'] === 'issue' && $row['meta_key'] === 'crossword') {
                $out['crossword'] = Crossword::clean($value);
                continue;
            }
            $id = (string) $row['external_id'];
            if ($id === '') {
                continue;
            }
            if ($row['meta_key'] === 'brief') {
                $brief = self::cleanBrief($value);
                if ($brief) {
                    $out['briefs'][$id] = $brief;
                }
            } elseif ($row['meta_key'] === 'facts') {
                $out['facts'][$id] = self::cleanFacts($value);
            } elseif ($row['meta_key'] === 'facts_visible') {
                $out['facts_visible'][$id] = $value === true || $value === 1 || $value === '1';
            } elseif ($row['meta_key'] === 'focus') {
                $focus = self::cleanFocus($value);
                if ($focus) {
                    $out['focus'][$id] = $focus;
                }
            }
        }
        self::$cache[$key] = $out;
        return $out;
    }

    /** Drafts carry no stored facts: the preview builds them from the claims, exactly as publish will. */
    public static function draftFacts(array $meta, array $articles): array
    {
        $facts = [];
        foreach ($articles as $article) {
            $id = (string) ($article['id'] ?? '');
            if ($id !== '' && !empty($meta['facts_visible'][$id])) {
                $facts[$id] = self::factsFromClaims((array) ($article['claims'] ?? []));
            }
        }
        return $facts;
    }

    public static function forget(): void
    {
        self::$cache = [];
    }

    // ---------------------------------------------------------------- writes (inside the caller's transaction)

    /** Writes or deletes (null) one draft value. Must run inside Issues' write transaction. */
    public static function setDraft(string $type, int $objectId, string $key, $value): void
    {
        self::put($type, $objectId, 'draft', $key, $value);
    }

    /** Copies the draft layer of the issue and its active articles into the public layer. */
    public static function publish(int $issueId, array $activeRows): void
    {
        $wpdb = Db::wpdb();
        $table = Db::table('meta');
        $objects = [['issue', $issueId]];
        foreach ($activeRows as $row) {
            $objects[] = ['article', (int) $row['id']];
        }
        foreach ($objects as [$type, $id]) {
            Db::must($wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE object_type=%s AND object_id=%d AND layer='public'", $type, $id)));
            $drafts = $wpdb->get_results($wpdb->prepare("SELECT meta_key, meta_value FROM {$table} WHERE object_type=%s AND object_id=%d AND layer='draft'", $type, $id), ARRAY_A);
            foreach ((array) $drafts as $draft) {
                $value = $draft['meta_value'];
                if ($type === 'issue' && $draft['meta_key'] === 'crossword') {
                    // Editor notes and build warnings never reach the public layer.
                    $crossword = Crossword::clean(json_decode((string) $value, true));
                    if (!$crossword) {
                        continue;
                    }
                    $value = Db::json(Crossword::forPublic($crossword));
                }
                self::insert($type, $id, 'public', $draft['meta_key'], $value);
            }
        }
        // Facts become public only for articles where the editor asked for them.
        foreach ($activeRows as $row) {
            $visible = $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$table} WHERE object_type='article' AND object_id=%d AND layer='draft' AND meta_key='facts_visible'", (int) $row['id']));
            if ($visible === '1' || $visible === 'true') {
                $draft = Db::decode($row['draft_json']);
                self::insert('article', (int) $row['id'], 'public', 'facts', Db::json(self::factsFromClaims((array) ($draft['claims'] ?? []))));
            }
        }
        self::forget();
    }

    /** Draft values of the issue for a revision snapshot, keyed by article external id. */
    public static function snapshot(int $issueId): array
    {
        $data = self::forIssue($issueId, 'draft');
        self::forget();
        return ['cover' => $data['cover'], 'briefs' => $data['briefs'], 'facts_visible' => $data['facts_visible'], 'focus' => $data['focus'], 'crossword' => $data['crossword']];
    }

    /** Puts a snapshot back into the draft layer (restore of an old version). */
    public static function restoreSnapshot(int $issueId, array $snapshot, array $rowsByExternal): void
    {
        self::put('issue', $issueId, 'draft', 'cover', isset($snapshot['cover']) ? self::cleanCover($snapshot['cover']) : null);
        // Snapshots older than 0.5.2 carry no crossword key: the current one stays as it is.
        if (array_key_exists('crossword', $snapshot)) {
            self::put('issue', $issueId, 'draft', 'crossword', Crossword::clean($snapshot['crossword']));
        }
        foreach ($rowsByExternal as $externalId => $row) {
            $brief = isset($snapshot['briefs'][$externalId]) ? self::cleanBrief($snapshot['briefs'][$externalId]) : [];
            self::put('article', (int) $row['id'], 'draft', 'brief', $brief ?: null);
            self::put('article', (int) $row['id'], 'draft', 'facts_visible', !empty($snapshot['facts_visible'][$externalId]) ? 1 : null);
            self::put('article', (int) $row['id'], 'draft', 'focus', isset($snapshot['focus'][$externalId]) ? self::cleanFocus($snapshot['focus'][$externalId]) : null);
        }
        self::forget();
    }

    // ---------------------------------------------------------------- sanitizing

    /** @return array<string,string> only non-empty blocks, plain text */
    public static function cleanBrief($value): array
    {
        $out = [];
        if (!is_array($value)) {
            return $out;
        }
        foreach (self::BRIEF_KEYS as $key) {
            if (!isset($value[$key]) || !is_scalar($value[$key])) {
                continue;
            }
            $text = trim(sanitize_textarea_field((string) $value[$key]));
            $length = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
            if ($text !== '' && $length <= self::BRIEF_MAX) {
                $out[$key] = $text;
            }
        }
        return $out;
    }

    public static function cleanCover($value): ?array
    {
        if (!is_array($value) || !in_array($value['kind'] ?? '', self::COVER_KINDS, true)) {
            return null;
        }
        $kind = $value['kind'];
        $cover = ['kind' => $kind, 'attachment_id' => 0, 'alt' => '', 'credit' => '', 'plate' => ''];
        if ($kind === 'photo') {
            $id = isset($value['attachment_id']) && is_scalar($value['attachment_id']) ? absint($value['attachment_id']) : 0;
            if (!$id) {
                return null;
            }
            $cover['attachment_id'] = $id;
            $cover['alt'] = self::clip(sanitize_text_field((string) ($value['alt'] ?? '')), 300);
            $cover['credit'] = self::clip(sanitize_text_field((string) ($value['credit'] ?? '')), 300);
            $focus = self::cleanFocus($value['focus'] ?? null);
            if ($focus) {
                $cover['focus'] = $focus;
            }
        } elseif ($kind === 'plate') {
            $plate = sanitize_key((string) ($value['plate'] ?? ''));
            if ($plate === '') {
                return null;
            }
            $cover['plate'] = $plate;
        }
        return $cover;
    }

    /**
     * Focus point of a photo in per cent from the top-left corner: the part of the picture
     * that must stay visible when a frame of another shape crops it. The centre returns null.
     * @return array{x:int,y:int}|null
     */
    public static function cleanFocus($value): ?array
    {
        if (!is_array($value) || !isset($value['x'], $value['y']) || !is_numeric($value['x']) || !is_numeric($value['y'])) {
            return null;
        }
        $focus = [
            'x' => (int) max(0, min(100, round((float) $value['x']))),
            'y' => (int) max(0, min(100, round((float) $value['y']))),
        ];
        return $focus === self::FOCUS_CENTER ? null : $focus;
    }

    private static function cleanFacts($value): array
    {
        $out = ['confirmed' => [], 'single' => []];
        foreach (['confirmed', 'single'] as $group) {
            foreach ((array) ($value[$group] ?? []) as $statement) {
                if (is_string($statement) && trim($statement) !== '') {
                    $out[$group][] = trim($statement);
                }
            }
        }
        return $out;
    }

    /** Readers see confirmed facts and single-source ones, never unverified claims or notes. */
    public static function factsFromClaims(array $claims): array
    {
        $out = ['confirmed' => [], 'single' => []];
        foreach ($claims as $claim) {
            if (!is_array($claim) || !isset($claim['statement'], $claim['verification'])) {
                continue;
            }
            $statement = trim(sanitize_text_field((string) $claim['statement']));
            if ($statement === '') {
                continue;
            }
            if (in_array($claim['verification'], ['verified_primary', 'verified_independent'], true)) {
                $out['confirmed'][] = $statement;
            } elseif ($claim['verification'] === 'attributed_single') {
                $out['single'][] = $statement;
            }
        }
        return $out;
    }

    private static function clip(string $text, int $max): string
    {
        return function_exists('mb_substr') ? mb_substr($text, 0, $max) : substr($text, 0, $max);
    }

    // ---------------------------------------------------------------- storage

    private static function put(string $type, int $objectId, string $layer, string $key, $value): void
    {
        $wpdb = Db::wpdb();
        $table = Db::table('meta');
        if ($value === null || $value === [] || $value === '') {
            Db::must($wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE object_type=%s AND object_id=%d AND layer=%s AND meta_key=%s", $type, $objectId, $layer, $key)));
            return;
        }
        $json = Db::json($value);
        Db::must($wpdb->query($wpdb->prepare(
            "INSERT INTO {$table} (object_type, object_id, layer, meta_key, meta_value, updated_at, updated_by) VALUES (%s, %d, %s, %s, %s, %s, %d)
             ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value), updated_at=VALUES(updated_at), updated_by=VALUES(updated_by)",
            $type,
            $objectId,
            $layer,
            $key,
            $json,
            Db::now(),
            get_current_user_id()
        )));
        self::forget();
    }

    private static function insert(string $type, int $objectId, string $layer, string $key, string $json): void
    {
        Db::must(Db::wpdb()->insert(Db::table('meta'), [
            'object_type' => $type,
            'object_id' => $objectId,
            'layer' => $layer,
            'meta_key' => $key,
            'meta_value' => $json,
            'updated_at' => Db::now(),
            'updated_by' => get_current_user_id(),
        ]));
    }
}
