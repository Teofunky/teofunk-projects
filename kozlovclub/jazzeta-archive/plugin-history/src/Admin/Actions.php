<?php

namespace Kozlov\Gazeta\Admin;

use Kozlov\Gazeta\Data\Installer;
use Kozlov\Gazeta\Data\Issues;
use Kozlov\Gazeta\Data\Meta;
use Kozlov\Gazeta\Data\Permit;
use Kozlov\Gazeta\Migration\Legacy020;
use Kozlov\Gazeta\Runtime\Cache;
use Kozlov\Gazeta\Runtime\Guard;
use Kozlov\Gazeta\Runtime\Mode;
use Kozlov\Gazeta\Runtime\Request;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * POST endpoints of the desk (admin-post.php?action=es_action).
 * Every operation: capability check → nonce bound to operation, issue and version →
 * repository call in one transaction. Publication additionally requires the selected owner,
 * a browser session (no REST, cron or CLI) and an explicit confirmation tick.
 */
final class Actions
{
    private const CAPS = [
        'create' => 'es_edit',
        'import' => 'es_import',
        'manual_import' => 'es_import',
        'article' => 'es_edit',
        'issue' => 'es_edit',
        'restore' => 'es_edit',
        'publish' => 'es_publish',
        'withdraw' => 'es_publish',
        'migrate' => 'manage_options',
    ];

    public static function nonceAction(string $operation, int $issue = 0, int $version = 0): string
    {
        return 'es-' . $operation . '-' . $issue . '-' . $version;
    }

    /** Hidden fields shared by every desk form. */
    public static function fields(string $operation, int $issue = 0, int $version = 0): void
    {
        echo '<input type="hidden" name="action" value="es_action">';
        echo '<input type="hidden" name="operation" value="' . esc_attr($operation) . '">';
        echo '<input type="hidden" name="issue_id" value="' . (int) $issue . '">';
        echo '<input type="hidden" name="version" value="' . (int) $version . '">';
        wp_nonce_field(self::nonceAction($operation, $issue, $version), '_wpnonce', false);
    }

    private static function value(string $key, string $fallback = ''): string
    {
        return isset($_POST[$key]) && is_scalar($_POST[$key]) ? (string) wp_unslash($_POST[$key]) : $fallback;
    }

    private static function lines(string $value): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/u', $value) ?: []), static function ($line) {
            return $line !== '';
        }));
    }

    public static function handle(): void
    {
        if (Request::method() !== 'POST') {
            wp_die('Требуется POST.', '', ['response' => 405]);
        }
        if (Mode::current() === Mode::PAUSED) {
            self::fail('Газета остановлена: изменения временно запрещены.', admin_url('admin.php?page=es-runtime'));
        }
        $operation = sanitize_key(self::value('operation'));
        $id = absint(self::value('issue_id'));
        $version = absint(self::value('version'));
        $cap = self::CAPS[$operation] ?? '';
        if ($cap === '' || !current_user_can($cap)) {
            wp_die('Нет прав для этого действия.', '', ['response' => 403]);
        }
        check_admin_referer(self::nonceAction($operation, $id, $version));
        $back = wp_get_referer() ?: Admin::url($id);

        switch ($operation) {
            case 'create':
                $result = Issues::create(self::value('edition_date'));
                self::done($result, $back, static function ($newId) {
                    return Admin::url((int) $newId, '', ['es_notice' => 'created']);
                });
                return;

            case 'import':
                $expected = self::value('expected');
                $result = Issues::import(self::value('raw'), $expected === '' ? null : absint($expected));
                self::done($result, $back, static function ($r) {
                    return Admin::url((int) $r['id'], '', ['es_notice' => $r['unchanged'] ? 'import_unchanged' : 'imported']);
                });
                return;

            case 'manual_import':
                self::manualImport($id, $version, $back);
                return;

            case 'article':
                self::article($id, $version, $back);
                return;

            case 'issue':
                self::issue($id, $version, $back);
                return;

            case 'restore':
                $result = Issues::restore($id, $version, absint(self::value('revision_id')));
                self::done($result, $back, static function () use ($id) {
                    return Admin::url($id, '', ['es_notice' => 'restored']);
                });
                return;

            case 'publish':
                if (self::value('confirm') !== '1' || !current_user_can('es_edit') || !Request::isInteractiveSession()) {
                    self::fail('Публикация требует отметки подтверждения в браузерной сессии владельца.', $back);
                }
                Permit::grant('publish', [$id, $version]);
                try {
                    $result = Issues::publish($id, $version);
                } finally {
                    Permit::revoke('publish');
                }
                if (!is_wp_error($result)) {
                    self::purge(static function () use ($id) {
                        Cache::purgeIssue($id);
                    });
                }
                self::done($result, $back, static function () use ($id) {
                    return Admin::url($id, '', ['es_notice' => 'published']);
                });
                return;

            case 'withdraw':
                if (self::value('confirm') !== '1' || !Request::isInteractiveSession()) {
                    self::fail('Подтвердите снятие выпуска отметкой.', $back);
                }
                $result = Issues::withdraw($id, $version);
                if (!is_wp_error($result)) {
                    self::purge(static function () use ($id) {
                        Cache::purgeIssue($id);
                    });
                }
                self::done($result, $back, static function () use ($id) {
                    return Admin::url($id, '', ['es_notice' => 'withdrawn']);
                });
                return;

            case 'migrate':
                $result = Legacy020::copy(absint(self::value('legacy_id')));
                self::done($result, $back, static function ($newId) {
                    return Admin::url((int) $newId, '', ['es_notice' => 'migrated']);
                });
                return;
        }
        wp_die('Неизвестное действие.', '', ['response' => 400]);
    }

    private static function manualImport(int $id, int $version, string $back): void
    {
        if (!Mode::isOwner() || !Request::isInteractiveSession()) {
            wp_die('Ручная замена доступна только выбранному владельцу в авторизованном браузере.', '', ['response' => 403]);
        }
        $raw = self::value('raw');
        $payloadNonce = self::value('payload_nonce');
        if (self::value('confirm') !== '1' || !wp_verify_nonce($payloadNonce, 'es-import-payload-' . $id . '-' . $version . '-' . hash('sha256', $raw))) {
            self::fail('Подтвердите именно проверенный JSON: вернитесь к проверке импорта.', $back);
        }
        Permit::grant('manual_import', [$id, $version, hash('sha256', $raw)]);
        try {
            $result = Issues::manualImport($id, $version, $raw);
        } finally {
            Permit::revoke('manual_import');
        }
        self::done($result, $back, static function () use ($id) {
            return Admin::url($id, '', ['es_notice' => 'manual_import']);
        });
    }

    private static function article(int $id, int $version, string $back): void
    {
        // An explicit end marker detects max_input_vars truncation before anything is written.
        if (self::value('form_complete') !== 'yes') {
            self::fail('Форма дошла до сервера не полностью (ограничение max_input_vars). Ничего не сохранено.', $back);
        }
        $input = isset($_POST['article']) && is_array($_POST['article']) ? wp_unslash($_POST['article']) : [];
        try {
            $article = ArticleForm::fromPost($input, self::value('body'), self::value('editorial_notes'), self::value('previous_article_ids'), self::value('new_fact'));
        } catch (\InvalidArgumentException $e) {
            self::fail('Неверная структура формы: ' . $e->getMessage() . ' Правки не сохранены.', $back);
            return;
        }
        $social = null;
        if (self::value('social_enabled') === '1') {
            $social = [
                'article_id' => $article['id'],
                'telegram_sentence' => self::value('telegram_sentence'),
                'instagram_sentence' => self::value('instagram_sentence'),
                'visual_format' => '1080x1350',
            ];
        }
        $intent = self::value('intent', 'save');
        $delete = $intent === 'delete' && self::value('confirm_delete') === '1';
        $review = $intent === 'review' && self::value('reviewed') === '1';
        if ($intent === 'review' && !$review) {
            self::fail('Чтобы подтвердить проверку, отметьте «Я проверил текущую версию».', $back);
        }
        $brief = isset($_POST['brief']) && is_array($_POST['brief']) ? wp_unslash($_POST['brief']) : [];
        $meta = [
            'brief' => array_intersect_key(array_map(static function ($text) {
                return is_scalar($text) ? (string) $text : '';
            }, $brief), array_flip(Meta::BRIEF_KEYS)),
            'facts_visible' => self::value('facts_visible') === '1',
        ];
        foreach ($meta['brief'] as $text) {
            if ((function_exists('mb_strlen') ? mb_strlen(trim($text)) : strlen(trim($text))) > Meta::BRIEF_MAX) {
                self::fail('Блок «Почему важно / Контекст / Что дальше» длиннее ' . Meta::BRIEF_MAX . ' знаков. Ничего не сохранено.', $back);
            }
        }
        $result = Issues::saveArticle($id, $version, $article, $review, self::value('review_note'), self::value('new_fact'), $social, $delete, $meta);
        self::done($result, $back, static function () use ($id, $article, $delete, $review) {
            if ($delete) {
                return Admin::url($id, '', ['es_notice' => 'article_removed']);
            }
            return Admin::url($id, $article['id'], ['es_notice' => $review ? 'reviewed' : 'saved']);
        });
    }

    private static function issue(int $id, int $version, string $back): void
    {
        $row = Issues::find($id);
        if (!$row) {
            self::fail('Выпуск не найден.', Admin::url());
            return;
        }
        $editorial = Issues::document($row)['editorial'];
        $editorial['ready_for_human_review'] = self::value('ready') === '1';
        $editorial['blocking_issues'] = self::lines(self::value('blocking_issues'));
        $editorial['generation_notes'] = self::lines(self::value('generation_notes'));
        foreach (['scan_start' => 'source_scan_window_start', 'scan_end' => 'source_scan_window_end'] as $field => $key) {
            $value = trim(self::value($field));
            if ($value !== '') {
                $editorial[$key] = self::isoFromLocal($value);
            }
        }
        $order = array_values(array_filter(array_map('trim', explode(',', self::value('order')))));
        $cover = null;
        $kind = self::value('cover_kind');
        if (in_array($kind, Meta::COVER_KINDS, true)) {
            $cover = ['kind' => $kind];
            if ($kind === 'photo') {
                $attachment = absint(self::value('cover_attachment'));
                if (!$attachment || !wp_attachment_is_image($attachment) || !in_array(get_post_mime_type($attachment), ['image/jpeg', 'image/png', 'image/webp', 'image/avif'], true)) {
                    self::fail('Выберите фото обложки в медиатеке (JPEG, PNG, WebP или AVIF). Ничего не сохранено.', $back);
                }
                if (trim(self::value('cover_credit')) === '') {
                    self::fail('Укажите автора и лицензию фото обложки: без подтверждённых прав фото не публикуется. Ничего не сохранено.', $back);
                }
                $cover += ['attachment_id' => $attachment, 'alt' => self::value('cover_alt'), 'credit' => self::value('cover_credit')];
            } elseif ($kind === 'plate') {
                $cover['plate'] = self::value('cover_plate');
            }
        }
        $result = Issues::saveIssue($id, $version, $editorial, $order, $cover);
        self::done($result, $back, static function () use ($id) {
            return Admin::url($id, '', ['es_notice' => 'issue_saved']);
        });
    }

    /** "2026-10-21T08:30" from <input type=datetime-local> → ISO 8601 in Moscow time. Full ISO passes through. */
    private static function isoFromLocal(string $value): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?$/', $value)) {
            try {
                return (new \DateTimeImmutable($value, new \DateTimeZone('Europe/Moscow')))->format('c');
            } catch (\Exception $e) {
                return $value;
            }
        }
        return $value;
    }

    /** Mode changes and finishing an in-place upgrade. Works in recovery state too. */
    public static function runtime(): void
    {
        if (Request::method() !== 'POST') {
            wp_die('Требуется POST.', '', ['response' => 405]);
        }
        $next = sanitize_key(self::value('next'));
        $allowed = in_array($next, [Mode::CLOSED, Mode::OPEN, Mode::PAUSED, 'upgrade'], true);
        $canStop = current_user_can('manage_options') || Mode::isOwner();
        if (!$allowed || !$canStop || !Request::isInteractiveSession()) {
            wp_die('Нет прав.', '', ['response' => 403]);
        }
        check_admin_referer('es-runtime-' . Mode::current() . '-' . $next);
        $back = admin_url('admin.php?page=es-runtime');

        if ($next === 'upgrade') {
            if (!current_user_can('manage_options') || self::value('confirm') !== '1') {
                self::fail('Завершение обновления требует прав администратора и подтверждения.', $back);
            }
            try {
                Installer::install();
                \Kozlov\Gazeta\Http\Router::registerRules();
                flush_rewrite_rules(false);
            } catch (\Throwable $e) {
                Guard::fault($e);
                self::fail('Обновление не завершено, газета остаётся остановленной. Подробности — в журнале сервера.', $back);
            }
            wp_safe_redirect(add_query_arg('es_notice', 'upgraded', $back));
            exit;
        }

        $fault = Mode::fault();
        if ($next === Mode::OPEN && (!Mode::isOwner() || Mode::current() !== Mode::CLOSED || $fault || !Mode::ready())) {
            wp_die('Открыть газету читателям может только выбранный владелец из закрытого режима.', '', ['response' => 403]);
        }
        if (($next === Mode::OPEN || ($next === Mode::CLOSED && $fault)) && self::value('confirm') !== '1') {
            self::fail('Требуется явное подтверждение отметкой.', $back);
        }
        if ($next === Mode::CLOSED && $fault) {
            try {
                if (!Mode::ready()) {
                    throw new \RuntimeException('Требуется завершить обновление.');
                }
                // Load-test the modules before lifting the stop.
                class_exists(\Kozlov\Gazeta\Front\Controller::class);
                class_exists(\Kozlov\Gazeta\Data\Issues::class);
                class_exists(\Kozlov\Gazeta\Admin\Screens\IssueScreen::class);
            } catch (\Throwable $e) {
                Guard::fault($e);
                self::fail('Проверка не пройдена. Газета остаётся остановленной.', $back);
            }
            Mode::clearFault();
        }
        Mode::set($next, get_current_user_id());
        self::purge(static function () {
            Cache::purgeRecent();
        });
        wp_safe_redirect(add_query_arg('es_notice', 'mode_' . $next, $back));
        exit;
    }

    /** @param mixed $result */
    /** A cache layer that fails must never undo or hide a completed publication. */
    private static function purge(callable $purge): void
    {
        try {
            $purge();
        } catch (\Throwable $e) {
            error_log('Kozlov Gazeta cache purge failed: ' . get_class($e));
        }
    }

    private static function done($result, string $back, callable $success): void
    {
        if (is_wp_error($result)) {
            self::fail($result->get_error_message(), $back, (int) ($result->get_error_data()['status'] ?? 400));
            return;
        }
        wp_safe_redirect($success($result));
        exit;
    }

    /** Back to the form with the reason; the browser restores unsaved input from its local copy. */
    public static function fail(string $message, string $back, int $status = 400): void
    {
        set_transient('es_flash_' . get_current_user_id(), ['type' => 'error', 'message' => $message, 'status' => $status], 300);
        wp_safe_redirect(add_query_arg('es_error', '1', remove_query_arg(['es_notice', 'es_error'], $back)));
        exit;
    }

    /** One-time message for the current user, if any. */
    public static function flash(): ?array
    {
        $key = 'es_flash_' . get_current_user_id();
        $flash = get_transient($key);
        if ($flash) {
            delete_transient($key);
        }
        return is_array($flash) ? $flash : null;
    }
}
