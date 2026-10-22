<?php

namespace Kozlov\Gazeta\Admin\Screens;

use Kozlov\Gazeta\Admin\Actions;
use Kozlov\Gazeta\Admin\Admin;
use Kozlov\Gazeta\Admin\Diff;
use Kozlov\Gazeta\Admin\Ui;
use Kozlov\Gazeta\Data\Db;
use Kozlov\Gazeta\Data\Issues;
use Kozlov\Gazeta\Domain\Labels;
use Kozlov\Gazeta\Domain\Validator;
use Kozlov\Gazeta\Front\View;
use Kozlov\Gazeta\Runtime\Mode;
use Kozlov\Gazeta\Runtime\Request;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * JSON import. Two paths:
 *  - ordinary import (form or REST): creates/updates a draft that no human has touched;
 *  - owner's manual replacement of an existing never-published draft: check → readable diff →
 *    explicit consent; a backup revision is written in the same transaction.
 */
final class ImportScreen
{
    /** @var array|null Validated candidate kept between load-hook and render. */
    private static $candidate = null;

    /** Handles the "check and show changes" POST before the page renders. */
    public static function load(): void
    {
        $id = isset($_GET['issue']) ? absint($_GET['issue']) : 0;
        if (!$id) {
            return;
        }
        if (!Mode::isOwner() || !current_user_can('es_import') || !Request::isInteractiveSession()) {
            wp_die('Ручная замена доступна только выбранному владельцу в авторизованном браузере.', '', ['response' => 403]);
        }
        if (Request::method() !== 'POST') {
            return;
        }
        if (Mode::current() === Mode::PAUSED) {
            wp_die('Газета остановлена.', '', ['response' => 503]);
        }
        $version = isset($_POST['version']) ? absint($_POST['version']) : 0;
        if (($_POST['operation'] ?? '') !== 'manual_preview' || absint($_POST['issue_id'] ?? 0) !== $id) {
            wp_die('Неверная форма.', '', ['response' => 400]);
        }
        check_admin_referer(Actions::nonceAction('manual_preview', $id, $version));
        $raw = isset($_POST['raw']) && is_string($_POST['raw']) ? (string) wp_unslash($_POST['raw']) : '';
        $candidate = Issues::manualImportCandidate($id, $version, $raw);
        if (is_wp_error($candidate)) {
            Actions::fail($candidate->get_error_message(), add_query_arg(['page' => 'es-import', 'issue' => $id], admin_url('admin.php')));
        }
        self::$candidate = $candidate;
    }

    public static function render(): void
    {
        if (!current_user_can('es_import')) {
            wp_die('Нет прав.', '', ['response' => 403]);
        }
        if (self::$candidate !== null) {
            self::confirmation(self::$candidate);
            return;
        }
        $id = isset($_GET['issue']) ? absint($_GET['issue']) : 0;
        if ($id) {
            self::replaceForm($id);
            return;
        }
        Ui::open('Импорт выпуска', 'es-import', ['subtitle' => 'JSON 1.1 · только черновики · до 256 КиБ']);
        View::render('admin/import', [
            'endpoint' => rest_url('ezhednevny-standard/v1/import'),
            'schema' => plugins_url('schemas/issue.schema.json', ES_FILE),
            'can_edit' => current_user_can('es_edit'),
        ]);
        Ui::close();
    }

    private static function replaceForm(int $id): void
    {
        $row = Issues::find($id);
        Ui::open('Заменить черновик из JSON', 'es-import', ['back' => [Admin::url($id), 'К выпуску']]);
        if (!$row) {
            echo '<p class="es-empty">Черновик не найден.</p>';
        } elseif (Issues::everPublished($row)) {
            echo '<div class="es-alert es-alert--warn">Этот выпуск публиковался или не является черновиком. Полная замена запрещена — правьте материалы по одному.</div>';
        } else {
            View::render('admin/import-replace', ['row' => $row, 'articles' => count(Issues::articleRows($id))]);
        }
        Ui::close();
    }

    private static function confirmation(array $candidate): void
    {
        $row = $candidate['issue'];
        $doc = $candidate['document'];
        $id = (int) $row['id'];
        $old = Issues::document($row);
        $before = array_column($old['articles'], null, 'id');
        $after = array_column($doc['articles'], null, 'id');
        $changes = [];
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $key) {
            $was = $before[$key] ?? null;
            $will = $after[$key] ?? null;
            if (!$will) {
                $kind = 'removed';
            } elseif (!$was) {
                $kind = 'added';
            } elseif (Issues::articleHash($was) === Issues::articleHash($will)) {
                $kind = 'same';
            } else {
                $kind = 'changed';
            }
            $changes[] = [
                'id' => $key,
                'kind' => $kind,
                'headline_was' => $was['headline'] ?? '',
                'headline' => $will['headline'] ?? ($was['headline'] ?? ''),
                'fields' => $was && $will ? Diff::fields($was, $will) : [],
                'diff_headline' => $kind === 'changed' ? Diff::words($was['headline'], $will['headline']) : '',
                'diff_lead' => $kind === 'changed' ? Diff::words($was['lead'], $will['lead']) : '',
                'diff_body' => $kind === 'changed' ? Diff::words(Validator::renderBody($was['body_paragraphs']), Validator::renderBody($will['body_paragraphs'])) : '',
            ];
        }
        $raw = Db::json($doc);
        Ui::open('Проверьте замену черновика', 'es-import', [
            'back' => [Admin::url($id), 'К выпуску'],
            'subtitle' => esc_html(Labels::longDate($row['edition_date'])) . ' · версия ' . (int) $row['version'],
        ]);
        View::render('admin/import-confirm', [
            'row' => $row,
            'changes' => $changes,
            'counts' => array_count_values(array_column($changes, 'kind')),
            'raw' => $raw,
            'pretty' => wp_json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'payload_nonce' => wp_create_nonce('es-import-payload-' . $id . '-' . (int) $row['version'] . '-' . hash('sha256', $raw)),
            'social_before' => count($old['social']),
            'social_after' => count($doc['social']),
        ]);
        Ui::close();
    }
}
