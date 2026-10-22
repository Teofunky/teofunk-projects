<?php

namespace Kozlov\Gazeta\Data;

use Kozlov\Gazeta\Domain\Duplicates;
use Kozlov\Gazeta\Domain\Readiness;
use Kozlov\Gazeta\Domain\Validator;
use Kozlov\Gazeta\Runtime\Mode;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Issue repository. Every write runs in one InnoDB transaction behind the global
 * write lock row and an optimistic version check, then records a revision snapshot.
 * Semantics are those of 0.3.5; only the code is new.
 */
final class Issues
{
    public const ARCHIVE_PAGE_SIZE = 12;
    public const DESK_PAGE_SIZE = 20;

    // ---------------------------------------------------------------- reads

    public static function find(int $id, bool $lock = false): ?array
    {
        $wpdb = Db::wpdb();
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Db::table('issues') . ' WHERE id=%d' . ($lock ? ' FOR UPDATE' : ''), $id), ARRAY_A);
        return $row ?: null;
    }

    public static function findByDate(string $date): ?array
    {
        $wpdb = Db::wpdb();
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Db::table('issues') . ' WHERE edition_date=%s', $date), ARRAY_A);
        return $row ?: null;
    }

    public static function articleRows(int $issueId, bool $activeOnly = true): array
    {
        $wpdb = Db::wpdb();
        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . Db::table('articles') . ' WHERE issue_id=%d' . ($activeOnly ? ' AND active=1' : '') . ' ORDER BY position,id',
            $issueId
        ), ARRAY_A) ?: [];
    }

    /** Working document: issue JSON with its active articles in order. */
    public static function document(array $issue): array
    {
        $doc = Db::decode($issue['draft_json']);
        $doc['articles'] = array_map(static function ($row) {
            return Db::decode($row['draft_json']);
        }, self::articleRows((int) $issue['id']));
        return $doc;
    }

    public static function articleHash(array $article): string
    {
        return hash('sha256', Db::json($article));
    }

    /** True when the stored review covers exactly the current text of the article row. */
    public static function isReviewed(array $row): bool
    {
        return !empty($row['reviewed_by']) && $row['reviewed_hash'] !== ''
            && hash_equals((string) $row['reviewed_hash'], self::articleHash(Db::decode($row['draft_json'])));
    }

    public static function everPublished(array $issue): bool
    {
        return $issue['state'] !== 'draft' || $issue['public_json'] !== null || (int) $issue['public_version'] > 0
            || $issue['published_at'] !== null || $issue['number'] !== null;
    }

    public static function latestPublished(): ?array
    {
        $wpdb = Db::wpdb();
        $row = $wpdb->get_row('SELECT * FROM ' . Db::table('issues') . " WHERE state='published' ORDER BY edition_date DESC,id DESC LIMIT 1", ARRAY_A);
        return $row ?: null;
    }

    public static function publishedByDate(string $date): ?array
    {
        $wpdb = Db::wpdb();
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Db::table('issues') . " WHERE edition_date=%s AND state='published'", $date), ARRAY_A);
        return $row ?: null;
    }

    /** @return array{issue:array,article:array}|null */
    public static function publishedArticle(string $slug): ?array
    {
        $wpdb = Db::wpdb();
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT a.public_json,a.issue_id FROM ' . Db::table('articles') . ' a JOIN ' . Db::table('issues') . " i ON i.id=a.issue_id
             WHERE a.slug=%s AND i.state='published' AND a.public_json IS NOT NULL",
            $slug
        ), ARRAY_A);
        if (!$row) {
            return null;
        }
        $issue = self::find((int) $row['issue_id']);
        return $issue ? ['issue' => $issue, 'article' => Db::decode($row['public_json'])] : null;
    }

    /** Published issues adjacent to a date: [older, newer] (either may be null). */
    public static function neighbours(string $date): array
    {
        $wpdb = Db::wpdb();
        $table = Db::table('issues');
        $older = $wpdb->get_row($wpdb->prepare("SELECT id,edition_date,number FROM {$table} WHERE state='published' AND edition_date<%s ORDER BY edition_date DESC LIMIT 1", $date), ARRAY_A);
        $newer = $wpdb->get_row($wpdb->prepare("SELECT id,edition_date,number FROM {$table} WHERE state='published' AND edition_date>%s ORDER BY edition_date ASC LIMIT 1", $date), ARRAY_A);
        return [$older ?: null, $newer ?: null];
    }

    /**
     * Paginated list. Public listing is cached per catalog version and carries the lead headline.
     * @return array{items:array,pages:int,total:int}
     */
    public static function listing(int $page = 1, bool $public = false, string $date = ''): array
    {
        $wpdb = Db::wpdb();
        $page = max(1, $page);
        $size = $public ? self::ARCHIVE_PAGE_SIZE : self::DESK_PAGE_SIZE;
        $where = $public ? " WHERE state='published'" : ' WHERE 1=1';
        if ($date !== '') {
            $where .= $wpdb->prepare(' AND edition_date=%s', $date);
        }
        $cacheKey = 'es_archive_' . Db::catalogVersion() . '_' . $page;
        if ($public && $date === '') {
            $cached = get_transient($cacheKey);
            if (is_array($cached) && isset($cached['items'][0]['lead_headline']) || (is_array($cached) && ($cached['total'] ?? -1) === 0)) {
                return $cached;
            }
        }
        $table = Db::table('issues');
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}{$where}");
        $pages = (int) ceil($total / $size);
        $rows = [];
        if ($page <= max(1, $pages)) {
            $columns = 'id,edition_date,number,state,version,public_version,public_updated_at,human_touched,updated_at';
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT {$columns}" . ($public ? ',public_json' : '') . " FROM {$table}{$where} ORDER BY edition_date DESC,id DESC LIMIT %d OFFSET %d",
                $size,
                ($page - 1) * $size
            ), ARRAY_A) ?: [];
        }
        if ($public) {
            foreach ($rows as &$row) {
                $snapshot = Db::decode($row['public_json']);
                unset($row['public_json']);
                $articles = $snapshot['articles'] ?? [];
                $lead = null;
                foreach ($articles as $article) {
                    if (($article['placement'] ?? '') === 'lead') {
                        $lead = $article;
                        break;
                    }
                }
                $lead = $lead ?: ($articles[0] ?? null);
                $row['lead_headline'] = $lead['headline'] ?? '';
                $row['lead_slug'] = $lead['slug'] ?? '';
                $row['article_count'] = count($articles);
                $row['headlines'] = array_values(array_filter(array_map(static function ($a) use ($lead) {
                    return ($lead && $a['slug'] === $lead['slug']) ? null : $a['headline'];
                }, $articles)));
                $row['regions'] = array_count_values(array_column($articles, 'region'));
            }
            unset($row);
        }
        $result = ['items' => $rows, 'pages' => $pages, 'total' => $total];
        if ($public && $date === '' && $page <= max(1, $pages)) {
            set_transient($cacheKey, $result, 300);
        }
        return $result;
    }

    /** Issues within a date range, newest first; for the desk calendar strip. */
    public static function between(string $from, string $to): array
    {
        $wpdb = Db::wpdb();
        return $wpdb->get_results($wpdb->prepare(
            'SELECT id,edition_date,number,state,version,human_touched,updated_at FROM ' . Db::table('issues') . ' WHERE edition_date BETWEEN %s AND %s ORDER BY edition_date DESC',
            $from,
            $to
        ), ARRAY_A) ?: [];
    }

    public static function history(int $issueId, int $page = 1, int $size = 20): array
    {
        $wpdb = Db::wpdb();
        $table = Db::table('revisions');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id,version,actor_id,action,created_at FROM {$table} WHERE issue_id=%d ORDER BY id DESC LIMIT %d OFFSET %d",
            $issueId,
            $size,
            (max(1, $page) - 1) * $size
        ), ARRAY_A) ?: [];
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE issue_id=%d", $issueId));
        return ['items' => $rows, 'total' => $total, 'pages' => (int) ceil($total / $size)];
    }

    public static function revision(int $issueId, int $revisionId): ?array
    {
        $wpdb = Db::wpdb();
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . Db::table('revisions') . ' WHERE issue_id=%d AND id=%d',
            $issueId,
            $revisionId
        ), ARRAY_A);
        return $row ?: null;
    }

    public static function lastImport(): ?array
    {
        $wpdb = Db::wpdb();
        $row = $wpdb->get_row('SELECT issue_id,actor_id,created_at FROM ' . Db::table('imports') . ' ORDER BY id DESC LIMIT 1', ARRAY_A);
        return $row ?: null;
    }

    /** Published articles of the same story within 14 days before the edition date. */
    public static function recentStory(string $group, string $date, int $exceptIssue): array
    {
        $wpdb = Db::wpdb();
        $start = (new \DateTimeImmutable($date))->modify('-14 days')->format('Y-m-d');
        return $wpdb->get_results($wpdb->prepare(
            'SELECT i.edition_date,a.slug FROM ' . Db::table('articles') . ' a JOIN ' . Db::table('issues') . " i ON i.id=a.issue_id
             WHERE a.public_story_group=%s AND a.public_json IS NOT NULL AND i.state='published' AND i.id<>%d AND i.edition_date BETWEEN %s AND %s LIMIT 15",
            $group,
            $exceptIssue,
            $start,
            $date
        ), ARRAY_A) ?: [];
    }

    public static function blockers(array $issue): array
    {
        return Readiness::messages(Readiness::check($issue));
    }

    // ---------------------------------------------------------------- write helpers

    private static function beginWrite(): void
    {
        if (Mode::current() === Mode::PAUSED) {
            throw new \RuntimeException('Газета остановлена. Изменения временно запрещены.', 503);
        }
        $wpdb = Db::wpdb();
        Db::begin();
        // The row exists since activation. INSERT IGNORE here would take a shared lock and could deadlock.
        $lock = $wpdb->get_var('SELECT value FROM ' . Db::table('state') . " WHERE name='write' FOR UPDATE");
        if ($wpdb->last_error || $lock === null) {
            throw new \RuntimeException('Не удалось заблокировать запись. Проверьте таблицы газеты или повторно активируйте плагин.', 503);
        }
    }

    private static function fail(\Throwable $e): \WP_Error
    {
        Db::rollback();
        $code = (int) $e->getCode();
        if (!($e instanceof \RuntimeException) && !($e instanceof \InvalidArgumentException)) {
            error_log('Kozlov Gazeta write failed: ' . get_class($e) . ' ' . basename($e->getFile()) . ':' . $e->getLine());
            return new \WP_Error('es_operation', 'Внутренняя ошибка. Изменения не сохранены.', ['status' => 500]);
        }
        return new \WP_Error('es_operation', $e->getMessage(), ['status' => $code >= 400 && $code < 600 ? $code : 400]);
    }

    private static function assertVersion(?array $row, int $version): void
    {
        if (!$row) {
            throw new \RuntimeException('Выпуск не найден.', 404);
        }
        if ((int) $row['version'] !== $version) {
            throw new \RuntimeException('Пока форма была открыта, выпуск изменили в другой вкладке или другой пользователь. Изменения не сохранены.', 409);
        }
    }

    private static function log(int $issueId, string $action): void
    {
        $issue = self::find($issueId);
        $snapshot = [
            'document' => self::document($issue),
            'reviews' => array_map(static function ($row) {
                return array_intersect_key($row, array_flip(['external_id', 'reviewed_by', 'reviewed_at', 'reviewed_hash', 'review_note', 'new_fact']));
            }, self::articleRows($issueId)),
            // 0.5: draft cover and blocks; older versions ignore the key when restoring.
            'meta' => Meta::snapshot($issueId),
        ];
        Db::must(Db::wpdb()->insert(Db::table('revisions'), [
            'issue_id' => $issueId,
            'version' => $issue['version'],
            'actor_id' => get_current_user_id(),
            'action' => $action,
            'created_at' => Db::now(),
            'snapshot' => Db::json($snapshot),
        ]));
    }

    /** Writes the working document: articles by external id, positions, derived identities. */
    private static function putDocument(int $issueId, array $doc, bool $resetReviews = true): void
    {
        $wpdb = Db::wpdb();
        $table = Db::table('articles');
        Db::must($wpdb->update($table, ['active' => 0], ['issue_id' => $issueId]));
        foreach ($doc['articles'] as $position => $article) {
            $old = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE issue_id=%d AND external_id=%s", $issueId, $article['id']), ARRAY_A);
            $fields = [
                'position' => $position,
                'active' => 1,
                'story_group' => $article['story_group_id'],
                'draft_json' => Db::json($article),
            ];
            if ($resetReviews || !$old || self::articleHash($article) !== hash('sha256', $old['draft_json'])) {
                $fields += ['reviewed_hash' => '', 'reviewed_by' => null, 'reviewed_at' => null, 'review_note' => '', 'new_fact' => ''];
            }
            if ($old) {
                Db::must($wpdb->update($table, $fields, ['id' => $old['id']]));
            } else {
                $fields += [
                    'issue_id' => $issueId,
                    'external_id' => $article['id'],
                    // Permanent address. Never recomputed after the first save.
                    'slug' => $doc['edition_date'] . '-' . $article['id'],
                    'review_note' => '',
                    'new_fact' => '',
                ];
                Db::must($wpdb->insert($table, $fields));
            }
        }
        Duplicates::sync($issueId);
        unset($doc['articles']);
        Db::must($wpdb->update(Db::table('issues'), ['draft_json' => Db::json($doc), 'updated_at' => Db::now()], ['id' => $issueId]));
    }

    // ---------------------------------------------------------------- writes

    /**
     * Automatic or editor-initiated import of a JSON 1.1 draft. Never overwrites human edits
     * or anything that was ever published.
     * @return array{id:int,version:int,status:string,unchanged:bool}|\WP_Error
     */
    public static function import(string $raw, ?int $expected = null)
    {
        if (!current_user_can('es_import')) {
            return Validator::error('Нет права импорта.', 403);
        }
        $doc = Validator::parse($raw);
        if (is_wp_error($doc)) {
            return $doc;
        }
        $wpdb = Db::wpdb();
        $issues = Db::table('issues');
        $imports = Db::table('imports');
        $hash = hash('sha256', Db::json($doc));
        try {
            self::beginWrite();
            $request = isset($doc['import_id']) ? hash('sha256', $doc['import_id']) : null;
            $prior = $request
                ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$imports} WHERE payload_hash=%s OR request_key=%s", $hash, $request), ARRAY_A)
                : $wpdb->get_row($wpdb->prepare("SELECT * FROM {$imports} WHERE payload_hash=%s", $hash), ARRAY_A);
            if ($prior) {
                if (!hash_equals($prior['payload_hash'], $hash)) {
                    throw new \RuntimeException('import_id уже использован с другим содержимым.', 409);
                }
                $existing = self::find((int) $prior['issue_id']);
                if ((int) $prior['actor_id'] !== get_current_user_id() && !current_user_can('es_edit')) {
                    throw new \RuntimeException('Нет доступа к этому импорту.', 403);
                }
                Db::commit();
                return ['id' => (int) $existing['id'], 'version' => (int) $existing['version'], 'status' => $existing['state'], 'unchanged' => true];
            }
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$issues} WHERE edition_date=%s FOR UPDATE", $doc['edition_date']), ARRAY_A);
            if ($row) {
                if ((int) $row['created_by'] !== get_current_user_id() && !current_user_can('es_edit')) {
                    throw new \RuntimeException('Нельзя обновлять чужой черновик.', 403);
                }
                if ($row['public_json'] !== null || $row['state'] !== 'draft') {
                    throw new \RuntimeException('Импорт не меняет ранее опубликованные выпуски.', 409);
                }
                if ((int) $row['human_touched']) {
                    throw new \RuntimeException('Выпуск уже редактировался человеком. Автоматическая перезапись запрещена.', 409);
                }
                if (hash_equals((string) $row['import_hash'], $hash)) {
                    Db::commit();
                    return ['id' => (int) $row['id'], 'version' => (int) $row['version'], 'status' => 'draft', 'unchanged' => true];
                }
                if ($expected === null) {
                    throw new \RuntimeException('Для обновления нужен If-Match с текущей версией.', 428);
                }
                self::assertVersion($row, $expected);
                $id = (int) $row['id'];
                Db::must($wpdb->update($issues, ['version' => (int) $row['version'] + 1, 'import_hash' => $hash], ['id' => $id]));
            } else {
                if ($expected !== null && $expected !== 0) {
                    throw new \RuntimeException('Выпуска для указанной версии нет.', 409);
                }
                Db::must($wpdb->insert($issues, [
                    'edition_date' => $doc['edition_date'],
                    'draft_json' => '{}',
                    'import_hash' => $hash,
                    'created_by' => get_current_user_id(),
                    'created_at' => Db::now(),
                    'updated_at' => Db::now(),
                ]));
                $id = (int) $wpdb->insert_id;
            }
            self::putDocument($id, $doc);
            Db::must($wpdb->insert($imports, [
                'issue_id' => $id,
                'actor_id' => get_current_user_id(),
                'request_key' => $request,
                'payload_hash' => $hash,
                'created_at' => Db::now(),
            ]));
            self::log($id, 'import');
            $fresh = self::find($id);
            Db::commit();
            return ['id' => $id, 'version' => (int) $fresh['version'], 'status' => 'draft', 'unchanged' => false];
        } catch (\Throwable $e) {
            return self::fail($e);
        }
    }

    /** @return int|\WP_Error New issue id. */
    public static function create(string $date)
    {
        if (!current_user_can('es_edit')) {
            return Validator::error('Нет прав.', 403);
        }
        if (!Validator::isDate($date)) {
            return Validator::error('Неверная дата.');
        }
        $wpdb = Db::wpdb();
        try {
            self::beginWrite();
            if ($wpdb->get_var($wpdb->prepare('SELECT id FROM ' . Db::table('issues') . ' WHERE edition_date=%s', $date))) {
                throw new \RuntimeException('Выпуск на эту дату уже существует.', 409);
            }
            Db::must($wpdb->insert(Db::table('issues'), [
                'edition_date' => $date,
                'draft_json' => Db::json(self::emptyDocument($date)),
                'human_touched' => 1,
                'created_by' => get_current_user_id(),
                'created_at' => Db::now(),
                'updated_at' => Db::now(),
            ]));
            $id = (int) $wpdb->insert_id;
            self::log($id, 'create');
            Db::commit();
            return $id;
        } catch (\Throwable $e) {
            return self::fail($e);
        }
    }

    /**
     * Read-only validation shared by the owner's preview page and the locked write.
     * @return array{issue:array,document:array}|\WP_Error
     */
    public static function manualImportCandidate(int $id, int $version, string $raw, bool $lock = false)
    {
        if (!Mode::isOwner() || !current_user_can('es_import')) {
            return Validator::error('Ручная замена доступна только выбранному владельцу с правом импорта.', 403);
        }
        $doc = Validator::parse($raw);
        if (is_wp_error($doc)) {
            return $doc;
        }
        try {
            $row = self::find($id, $lock);
            self::assertVersion($row, $version);
            if (self::everPublished($row)) {
                throw new \RuntimeException('Ручной импорт разрешён только в никогда не публиковавшийся черновик.', 409);
            }
            foreach (self::articleRows($id, false) as $article) {
                if ($article['public_json'] !== null) {
                    throw new \RuntimeException('В выпуске есть опубликованные материалы. Замена запрещена.', 409);
                }
            }
            if ($doc['edition_date'] !== $row['edition_date']) {
                throw new \RuntimeException('Дата в JSON должна совпадать с датой выбранного черновика.', 400);
            }
            // An owner replacement is neither an automatic import receipt nor a review.
            unset($doc['import_id']);
            $doc['editorial']['ready_for_human_review'] = false;
            return ['issue' => $row, 'document' => $doc];
        } catch (\Throwable $e) {
            return new \WP_Error('es_manual_import', $e->getMessage(), ['status' => (int) $e->getCode() ?: 400]);
        }
    }

    /** @return int|\WP_Error */
    public static function manualImport(int $id, int $version, string $raw)
    {
        if (!Permit::holds('manual_import', [$id, $version, hash('sha256', $raw)]) || !Mode::isOwner() || !current_user_can('es_import')) {
            return Validator::error('Требуется подтверждённая форма ручного импорта владельца.', 403);
        }
        $wpdb = Db::wpdb();
        try {
            self::beginWrite();
            $candidate = self::manualImportCandidate($id, $version, $raw, true);
            if (is_wp_error($candidate)) {
                throw new \RuntimeException($candidate->get_error_message(), (int) ($candidate->get_error_data()['status'] ?? 400));
            }
            // Backup and replacement commit together: failure of either rolls back both.
            self::log($id, 'before_manual_import');
            self::putDocument($id, $candidate['document'], true);
            Db::must($wpdb->update(Db::table('issues'), ['version' => $version + 1, 'human_touched' => 1, 'import_hash' => ''], ['id' => $id]));
            self::log($id, 'manual_import');
            Db::commit();
            return $id;
        } catch (\Throwable $e) {
            return self::fail($e);
        }
    }

    public static function emptyDocument(string $date): array
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Moscow')))->format('c');
        return [
            'schema_version' => '1.1',
            'edition_date' => $date,
            'timezone' => 'Europe/Moscow',
            'edition_number' => null,
            'publication' => ['status' => 'draft'],
            'articles' => [],
            'social' => [],
            'editorial' => [
                'ready_for_human_review' => false,
                'blocking_issues' => [],
                'source_scan_window_start' => $now,
                'source_scan_window_end' => $now,
                'generation_notes' => [],
            ],
        ];
    }

    public static function blankArticle(): array
    {
        $token = static function (int $length): string {
            return strtolower(wp_generate_password($length, false, false));
        };
        return [
            'id' => 'story-' . $token(8),
            'region' => 'world',
            'category' => 'jazz',
            'placement' => 'regular',
            'headline' => 'Новый материал',
            'lead' => 'Краткое содержание материала.',
            'body_paragraphs' => ['Текст материала.'],
            'summary' => 'Краткая выжимка материала.',
            'event_date' => null,
            'story_group_id' => 'story-' . $token(10),
            'story_score' => 50,
            'event_id' => 'event-' . $token(10),
            'previous_article_ids' => [],
            'development_note' => '',
            'sources' => [[
                'id' => 's1', 'title' => 'Первоисточник', 'publisher' => 'Название источника', 'url' => 'https://example.com/source',
                'published_at' => null, 'type' => 'official', 'verification_checked_at' => null, 'dependency_group' => 'chain-1',
            ]],
            'claims' => [[
                'id' => 'f1', 'statement' => 'Существенный факт, требующий проверки.', 'verification' => 'unverified',
                'source_ids' => ['s1'], 'editorial_note' => '',
            ]],
            'quotes' => [],
            'conflict_disclosure' => null,
            'editorial_notes' => [],
            'media_rights' => ['image_url' => null, 'status' => 'none', 'credit' => ''],
        ];
    }

    /**
     * Saves one article (or removes it) inside the working document.
     * $meta (0.5): ['brief' => [why, context, next], 'facts_visible' => bool] — the blocks the JSON
     * format does not carry. Any save without a review mark resets the review, so an edited block
     * has to be confirmed again together with the text.
     * @return int|\WP_Error Issue id.
     */
    public static function saveArticle(int $id, int $version, array $article, bool $review, string $note, string $newFact, ?array $social = null, bool $delete = false, ?array $meta = null)
    {
        if (!current_user_can('es_edit')) {
            return Validator::error('Нет прав.', 403);
        }
        if (strlen($note) > 16000 || strlen($newFact) > 16000) {
            return Validator::error('Слишком длинное замечание проверки.');
        }
        $wpdb = Db::wpdb();
        try {
            self::beginWrite();
            $row = self::find($id, true);
            self::assertVersion($row, $version);
            $doc = self::document($row);
            $found = false;
            foreach ($doc['articles'] as $key => $old) {
                if ($old['id'] === $article['id']) {
                    $found = true;
                    if ($delete) {
                        unset($doc['articles'][$key]);
                    } else {
                        $doc['articles'][$key] = $article;
                    }
                    break;
                }
            }
            if (!$found && !$delete) {
                $doc['articles'][] = $article;
            }
            $doc['articles'] = array_values($doc['articles']);
            $doc['social'] = array_values(array_filter($doc['social'], static function ($item) use ($article) {
                return $item['article_id'] !== $article['id'];
            }));
            if (!$delete && $social !== null) {
                $doc['social'][] = $social;
            }
            $doc = Validator::parse(Db::json($doc));
            if (is_wp_error($doc)) {
                throw new \RuntimeException($doc->get_error_message(), 400);
            }
            $clean = null;
            if ($review && !$delete) {
                foreach ($doc['articles'] as $candidate) {
                    if ($candidate['id'] === $article['id']) {
                        $clean = $candidate;
                    }
                }
                $errors = Validator::articleBlockers($clean);
                if ($errors) {
                    throw new \RuntimeException(implode(' ', $errors), 400);
                }
                if (trim($note) === '') {
                    throw new \RuntimeException('Для подтверждения запишите результат проверки источников, фактов, атрибуции, цитат и прав.', 400);
                }
            }
            self::putDocument($id, $doc, false);
            if (!$delete) {
                $fields = [
                    'reviewed_hash' => '',
                    'reviewed_by' => null,
                    'reviewed_at' => null,
                    'review_note' => sanitize_textarea_field($note),
                    'new_fact' => sanitize_textarea_field($newFact),
                ];
                if ($review) {
                    $fields['reviewed_hash'] = self::articleHash($clean);
                    $fields['reviewed_by'] = get_current_user_id();
                    $fields['reviewed_at'] = Db::now();
                }
                Db::must($wpdb->update(Db::table('articles'), $fields, ['issue_id' => $id, 'external_id' => $article['id']]));
                if ($meta !== null) {
                    $rowId = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . Db::table('articles') . ' WHERE issue_id=%d AND external_id=%s', $id, $article['id']));
                    $brief = Meta::cleanBrief($meta['brief'] ?? []);
                    Meta::setDraft('article', $rowId, 'brief', $brief ?: null);
                    Meta::setDraft('article', $rowId, 'facts_visible', !empty($meta['facts_visible']) ? 1 : null);
                    if (array_key_exists('focus', $meta)) {
                        Meta::setDraft('article', $rowId, 'focus', Meta::cleanFocus($meta['focus']));
                    }
                }
            }
            Db::must($wpdb->update(Db::table('issues'), ['version' => $version + 1, 'human_touched' => 1], ['id' => $id]));
            self::log($id, $delete ? 'remove_article' : ($review ? 'review_article' : 'edit_article'));
            Db::commit();
            return $id;
        } catch (\Throwable $e) {
            return self::fail($e);
        }
    }

    /**
     * Saves only the focus point of a photo: an article's own photo ($target = its external id)
     * or the issue's own cover photo ($target = 'cover'). Presentation only: the human review of
     * the text stays valid; the working version changes, so readers see it after re-approval.
     * @return int|\WP_Error
     */
    public static function saveFocus(int $id, int $version, string $target, ?array $focus)
    {
        if (!current_user_can('es_edit')) {
            return Validator::error('Нет прав.', 403);
        }
        $wpdb = Db::wpdb();
        try {
            self::beginWrite();
            $row = self::find($id, true);
            self::assertVersion($row, $version);
            $clean = Meta::cleanFocus($focus);
            if ($target === 'cover') {
                $cover = Meta::forIssue($id, 'draft')['cover'];
                Meta::forget();
                if (!$cover || $cover['kind'] !== 'photo') {
                    throw new \RuntimeException('Кадр обложки задаётся только для своего фото обложки. Для фото главного материала — в его карточке.', 400);
                }
                unset($cover['focus']);
                if ($clean) {
                    $cover['focus'] = $clean;
                }
                Meta::setDraft('issue', $id, 'cover', $cover);
            } else {
                $rowId = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . Db::table('articles') . ' WHERE issue_id=%d AND external_id=%s AND active=1', $id, $target));
                if (!$rowId) {
                    throw new \RuntimeException('Материал не найден в этом выпуске.', 404);
                }
                Meta::setDraft('article', $rowId, 'focus', $clean);
            }
            Db::must($wpdb->update(Db::table('issues'), ['version' => $version + 1, 'human_touched' => 1], ['id' => $id]));
            Meta::forget();
            self::log($id, 'edit_focus');
            Db::commit();
            return $id;
        } catch (\Throwable $e) {
            return self::fail($e);
        }
    }

    /**
     * Saves issue-level editorial data and article order; $cover (0.5) is the issue cover:
     * ['kind' => lead|photo|plate|none, ...], null leaves it unchanged. @return int|\WP_Error
     */
    public static function saveIssue(int $id, int $version, array $editorial, array $order, ?array $cover = null)
    {
        if (!current_user_can('es_edit')) {
            return Validator::error('Нет прав.', 403);
        }
        $wpdb = Db::wpdb();
        try {
            self::beginWrite();
            $row = self::find($id, true);
            self::assertVersion($row, $version);
            $doc = self::document($row);
            $doc['editorial'] = $editorial;
            $ids = array_column($doc['articles'], 'id');
            if (count($order) !== count($ids) || count(array_unique($order)) !== count($ids) || array_diff($ids, $order)) {
                throw new \RuntimeException('Порядок должен содержать каждый материал ровно один раз.', 400);
            }
            $byId = array_column($doc['articles'], null, 'id');
            $doc['articles'] = array_map(static function ($key) use ($byId) {
                return $byId[$key];
            }, $order);
            $doc = Validator::parse(Db::json($doc));
            if (is_wp_error($doc)) {
                throw new \RuntimeException($doc->get_error_message(), 400);
            }
            self::putDocument($id, $doc, false);
            if ($cover !== null) {
                $clean = Meta::cleanCover($cover);
                // «Фото главного материала» is the default and needs no stored value.
                Meta::setDraft('issue', $id, 'cover', $clean && $clean['kind'] !== 'lead' ? $clean : null);
            }
            Db::must($wpdb->update(Db::table('issues'), ['version' => $version + 1, 'human_touched' => 1], ['id' => $id]));
            self::log($id, 'edit_issue');
            Db::commit();
            return $id;
        } catch (\Throwable $e) {
            return self::fail($e);
        }
    }

    /**
     * Publishes or re-approves an issue. Reachable only from the confirmed owner form.
     * @return int|\WP_Error
     */
    public static function publish(int $id, int $version)
    {
        if (!Permit::holds('publish', [$id, $version]) || !Mode::isOwner()) {
            return Validator::error('Публикация разрешена только через подтверждённую форму владельца.', 403);
        }
        $wpdb = Db::wpdb();
        try {
            self::beginWrite();
            $row = self::find($id, true);
            self::assertVersion($row, $version);
            $errors = self::blockers($row);
            if ($errors) {
                throw new \RuntimeException(implode(' ', $errors), 400);
            }
            $number = (int) $row['number'];
            $state = Db::table('state');
            if (!$number) {
                Db::must($wpdb->query("UPDATE {$state} SET value=value+1 WHERE name='number'"));
                $number = (int) $wpdb->get_var("SELECT value FROM {$state} WHERE name='number'");
            }
            $doc = self::document($row);
            $public = ['edition_date' => $doc['edition_date'], 'number' => $number, 'articles' => [], 'social' => $doc['social']];
            $activeRows = [];
            foreach (self::articleRows($id, false) as $article) {
                if ((int) $article['active']) {
                    $activeRows[] = $article;
                }
                if (!(int) $article['active']) {
                    Db::must($wpdb->update(Db::table('articles'), ['public_json' => null, 'public_story_group' => ''], ['id' => $article['id']]));
                    continue;
                }
                $draft = Db::decode($article['draft_json']);
                $projection = Validator::publicArticle($draft);
                $projection['slug'] = $article['slug'];
                $public['articles'][] = $projection;
                Db::must($wpdb->update(Db::table('articles'), [
                    'public_json' => Db::json($projection),
                    'public_story_group' => $draft['story_group_id'],
                ], ['id' => $article['id']]));
            }
            Db::must($wpdb->update(Db::table('issues'), [
                'number' => $number,
                'state' => 'published',
                'version' => $version + 1,
                'public_version' => $version + 1,
                'public_json' => Db::json($public),
                'published_at' => $row['published_at'] ?: Db::now(),
                'public_updated_at' => Db::now(),
                'updated_at' => Db::now(),
                'approved_by' => get_current_user_id(),
                'human_touched' => 1,
            ], ['id' => $id]));
            Duplicates::sync($id, 'public');
            // Cover and article blocks go public in the same transaction as the text.
            Meta::publish($id, $activeRows);
            self::log($id, 'publish');
            Db::invalidateCatalog();
            Search::afterChange($id);
            Db::commit();
            return $id;
        } catch (\Throwable $e) {
            return self::fail($e);
        }
    }

    /** @return int|\WP_Error */
    public static function withdraw(int $id, int $version)
    {
        if (!Mode::isOwner()) {
            return Validator::error('Только выбранный владелец может снять выпуск.', 403);
        }
        $wpdb = Db::wpdb();
        try {
            self::beginWrite();
            $row = self::find($id, true);
            self::assertVersion($row, $version);
            if ($row['state'] !== 'published') {
                throw new \RuntimeException('Снять можно только опубликованный выпуск.', 409);
            }
            Db::must($wpdb->update(Db::table('issues'), [
                'state' => 'withdrawn',
                'version' => $version + 1,
                'updated_at' => Db::now(),
                'human_touched' => 1,
            ], ['id' => $id]));
            self::log($id, 'withdraw');
            Db::invalidateCatalog();
            Search::afterChange($id);
            Db::commit();
            return $id;
        } catch (\Throwable $e) {
            return self::fail($e);
        }
    }

    /** Copies an old snapshot into the working draft. The public snapshot is untouched. @return int|\WP_Error */
    public static function restore(int $id, int $version, int $revisionId)
    {
        if (!current_user_can('es_edit')) {
            return Validator::error('Нет прав.', 403);
        }
        $wpdb = Db::wpdb();
        try {
            self::beginWrite();
            $row = self::find($id, true);
            self::assertVersion($row, $version);
            $revision = self::revision($id, $revisionId);
            $snapshot = $revision ? json_decode((string) $revision['snapshot'], true) : null;
            if (!is_array($snapshot) || !isset($snapshot['document'])) {
                throw new \RuntimeException('Версия не найдена.', 404);
            }
            $doc = Validator::parse(Db::json($snapshot['document']));
            if (is_wp_error($doc)) {
                throw new \RuntimeException($doc->get_error_message(), 400);
            }
            $doc['editorial']['ready_for_human_review'] = false;
            self::putDocument($id, $doc, true);
            if (isset($snapshot['meta']) && is_array($snapshot['meta'])) {
                $rows = [];
                foreach (self::articleRows($id) as $articleRow) {
                    $rows[$articleRow['external_id']] = $articleRow;
                }
                Meta::restoreSnapshot($id, $snapshot['meta'], $rows);
            }
            Db::must($wpdb->update(Db::table('issues'), ['version' => $version + 1, 'human_touched' => 1], ['id' => $id]));
            self::log($id, 'restore_draft');
            Db::commit();
            return $id;
        } catch (\Throwable $e) {
            return self::fail($e);
        }
    }
}
