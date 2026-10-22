<?php

namespace Kozlov\Gazeta\Data;

use Kozlov\Gazeta\Http\Router;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Activation and in-place code upgrade. Never drops, renames or rewrites data:
 * dbDelta only creates missing tables/columns of the unchanged 0.3.x schema.
 */
final class Installer
{
    public static function activate(bool $networkWide): void
    {
        if ($networkWide || is_multisite()) {
            wp_die('Газета рассчитана на одиночную установку WordPress. Multisite не поддерживается.');
        }
        if (version_compare(PHP_VERSION, '7.4', '<') || version_compare(get_bloginfo('version'), '6.4', '<')) {
            wp_die('Требуются PHP 7.4+ и WordPress 6.4+.');
        }
        $conflict = self::routeConflict();
        if ($conflict !== '') {
            wp_die(esc_html($conflict));
        }
        self::install();
        Router::registerRules();
        flush_rewrite_rules(false);
    }

    /** Explains why /gazeta/ cannot be claimed, or returns ''. Existing content is never changed. */
    public static function routeConflict(): string
    {
        if (get_page_by_path(Router::BASE, OBJECT, ['page', 'post'])) {
            return 'Адрес /' . Router::BASE . '/ уже занят страницей сайта. Существующая страница не изменена; активация остановлена.';
        }
        foreach (get_post_types([], 'objects') as $type) {
            $slug = is_array($type->rewrite) ? ($type->rewrite['slug'] ?? '') : '';
            if ($slug === Router::BASE || $type->has_archive === Router::BASE) {
                return 'Адрес /' . Router::BASE . '/ занят типом записей «' . $type->name . '». Активация остановлена.';
            }
        }
        foreach ((array) get_option('rewrite_rules', []) as $pattern => $target) {
            if (is_string($target) && strpos($target, 'es_view=') === false
                && preg_match('/^\^?' . preg_quote(Router::BASE, '/') . '(?:[\/($?]|$)/', (string) $pattern)) {
                return 'Адрес /' . Router::BASE . '/ занят другим правилом сайта. Активация остановлена.';
            }
        }
        return '';
    }

    /** Idempotent. Safe to call on every activation and from the recovery screen. */
    public static function install(): void
    {
        $wpdb = Db::wpdb();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $collate = $wpdb->get_charset_collate();
        foreach (Db::schema() as $name => $columns) {
            $table = Db::table($name);
            dbDelta("CREATE TABLE {$table} (\n{$columns}\n) ENGINE=InnoDB {$collate};");
            $engine = $wpdb->get_var($wpdb->prepare(
                'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
                $table
            ));
            if ($engine !== 'InnoDB') {
                wp_die('Не удалось подготовить транзакционную таблицу ' . esc_html($table) . '. Активация остановлена, данные не изменены.');
            }
        }
        Db::must($wpdb->query('INSERT IGNORE INTO ' . Db::table('state') . " (name,value) VALUES ('number',0),('catalog',1),('write',0),('search',0)"));

        add_role('es_importer', 'Газета — импортёр', ['read' => true, 'es_import' => true]);
        add_role('es_editor', 'Газета — редактор', ['read' => true, 'es_edit' => true]);
        $admin = get_role('administrator');
        if ($admin) {
            foreach (['es_edit', 'es_import', 'es_publish'] as $cap) {
                $admin->add_cap($cap);
            }
        }
        // add_option never overwrites: an existing owner and mode survive upgrades.
        add_option('es_runtime', ['mode' => 'closed', 'changed_by' => get_current_user_id(), 'changed_at' => gmdate('c')], '', false);
        add_option('es_security', ['publisher_id' => get_current_user_id()], '', false);
        update_option('es_db_version', ES_VERSION, false);
        // The search index is derived data: rebuilt here, never a reason to stop the upgrade.
        Search::ensureFresh();
    }
}
