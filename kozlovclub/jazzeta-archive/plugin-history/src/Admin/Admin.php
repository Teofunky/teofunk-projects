<?php

namespace Kozlov\Gazeta\Admin;

use Kozlov\Gazeta\Admin\Screens\AccessScreen;
use Kozlov\Gazeta\Admin\Screens\AppearanceScreen;
use Kozlov\Gazeta\Admin\Screens\ArticleScreen;
use Kozlov\Gazeta\Admin\Screens\DeskScreen;
use Kozlov\Gazeta\Admin\Screens\ImportScreen;
use Kozlov\Gazeta\Admin\Screens\IntegrityScreen;
use Kozlov\Gazeta\Admin\Screens\IssueScreen;
use Kozlov\Gazeta\Admin\Screens\MigrationScreen;
use Kozlov\Gazeta\Admin\Screens\ModeScreen;
use Kozlov\Gazeta\Admin\Screens\SourcesScreen;
use Kozlov\Gazeta\Front\View;
use Kozlov\Gazeta\Runtime\Guard;
use Kozlov\Gazeta\Runtime\Mode;
use Kozlov\Gazeta\Settings\Analytics;
use Kozlov\Gazeta\Settings\Appearance;

if (!defined('ABSPATH')) {
    exit;
}

/** wp-admin integration: menu, assets, settings registration and POST endpoints. */
final class Admin
{
    public const PAGES = ['es-newsroom', 'es-import', 'es-sources', 'es-runtime', 'es-appearance', 'es-access', 'es-integrity', 'es-migrate'];

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
        add_action('admin_post_es_action', Guard::wrap([Actions::class, 'handle']));
        add_action('admin_init', [self::class, 'settings']);
        add_action('admin_init', [self::class, 'searchIndex']);
        add_filter('admin_body_class', [self::class, 'bodyClass']);
    }

    /** The desk's own pages bring a stale search index up to date (after a rollback window). */
    public static function searchIndex(): void
    {
        $page = isset($_GET['page']) && is_string($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ($page === 'es-newsroom' && current_user_can('es_edit')) {
            \Kozlov\Gazeta\Data\Search::ensureFresh();
        }
    }

    /**
     * Recovery entry that works even when the newspaper is stopped or not yet upgraded:
     * the mode screen with fault details and the "finish update" action.
     */
    public static function registerRecovery(): void
    {
        add_action('admin_post_es_runtime_action', [Actions::class, 'runtime']);
        add_action('admin_notices', [self::class, 'notices']);
        if (!Mode::fault() && Mode::ready()) {
            return;
        }
        add_action('admin_menu', static function () {
            $cap = current_user_can('manage_options') ? 'manage_options' : 'es_edit';
            add_menu_page('Газета: восстановление', 'Газета: восстановление', $cap, 'es-runtime', [ModeScreen::class, 'render'], 'dashicons-shield', 29);
        });
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
    }

    public static function menu(): void
    {
        $icon = 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="#a7aaad" d="M3 3h11v2H3zm0 4h14v1.5H3zm0 3h6v6H3zm8 0h6v1.5h-6zm0 3h6v1.5h-6zm0 3h4v1.5h-4zM15 3h2v2h-2z"/></svg>');
        add_menu_page(Appearance::name(), 'Газета', 'es_edit', 'es-newsroom', [self::class, 'newsroom'], $icon, 29);
        add_submenu_page('es-newsroom', 'Пульт газеты', 'Пульт', 'es_edit', 'es-newsroom', [self::class, 'newsroom']);
        $importHook = add_submenu_page('es-newsroom', 'Импорт выпуска', 'Импорт JSON', 'es_import', 'es-import', [ImportScreen::class, 'render']);
        if ($importHook) {
            add_action('load-' . $importHook, Guard::wrap([ImportScreen::class, 'load']));
        }
        add_submenu_page('es-newsroom', 'Источники газеты', 'Источники', 'es_edit', 'es-sources', [SourcesScreen::class, 'render']);
        add_submenu_page('es-newsroom', 'Режим газеты', 'Режим', 'es_edit', 'es-runtime', [ModeScreen::class, 'render']);
        add_submenu_page('es-newsroom', 'Оформление газеты', 'Оформление', 'manage_options', 'es-appearance', [AppearanceScreen::class, 'render']);
        add_submenu_page('es-newsroom', 'Доступ к газете', 'Доступ', 'manage_options', 'es-access', [AccessScreen::class, 'render']);
        add_submenu_page('es-newsroom', 'Проверка данных газеты', 'Проверка данных', 'manage_options', 'es-integrity', [IntegrityScreen::class, 'render']);
        add_submenu_page('es-newsroom', 'Перенос выпусков 0.2.0', 'Перенос 0.2.0', 'manage_options', 'es-migrate', [MigrationScreen::class, 'render']);
        // An importer account sees only its own entry point.
        if (current_user_can('es_import') && !current_user_can('es_edit')) {
            $hook = add_menu_page('Импорт газеты', 'Импорт газеты', 'es_import', 'es-import', [ImportScreen::class, 'render'], 'dashicons-upload', 29);
            add_action('load-' . $hook, Guard::wrap([ImportScreen::class, 'load']));
        }
    }

    /** One menu slug dispatches to desk, issue or article screens. */
    public static function newsroom(): void
    {
        if (!current_user_can('es_edit')) {
            wp_die('Нет прав.', '', ['response' => 403]);
        }
        $issue = isset($_GET['issue']) ? absint($_GET['issue']) : 0;
        $article = isset($_GET['article']) && is_string($_GET['article']) ? sanitize_key(wp_unslash($_GET['article'])) : '';
        if ($issue && $article !== '') {
            ArticleScreen::render($issue, $article);
        } elseif ($issue) {
            IssueScreen::render($issue);
        } else {
            DeskScreen::render();
        }
    }

    public static function settings(): void
    {
        register_setting('es_appearance_group', Appearance::OPTION, ['type' => 'array', 'sanitize_callback' => [Appearance::class, 'sanitize'], 'show_in_rest' => false]);
        // A new default design changes the HTML of every newspaper page.
        add_action('update_option_' . Appearance::OPTION, static function () {
            try {
                \Kozlov\Gazeta\Runtime\Cache::purgeRecent();
            } catch (\Throwable $e) {
                error_log('Kozlov Gazeta cache purge failed: ' . get_class($e));
            }
        });
        register_setting('es_appearance_group', Analytics::OPTION, ['type' => 'array', 'sanitize_callback' => [Analytics::class, 'sanitize'], 'show_in_rest' => false]);
        register_setting('es_security_group', 'es_security', ['type' => 'array', 'sanitize_callback' => [AccessScreen::class, 'sanitize'], 'show_in_rest' => false]);
    }

    public static function isOurScreen(): bool
    {
        $page = isset($_GET['page']) && is_string($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        return in_array($page, self::PAGES, true);
    }

    public static function bodyClass($classes)
    {
        return self::isOurScreen() ? $classes . ' es-desk-screen' : $classes;
    }

    public static function assets(): void
    {
        if (!self::isOurScreen()) {
            return;
        }
        if (current_user_can('upload_files')) {
            wp_enqueue_media();
        }
        wp_enqueue_style('es-desk', View::versioned('css/desk.css'), [], null);
        wp_enqueue_script('es-desk', View::versioned('js/desk.js'), [], null, ['in_footer' => true, 'strategy' => 'defer']);
        wp_add_inline_script('es-desk', 'window.esDesk=' . wp_json_encode([
            'fontUrl' => View::asset('fonts/es-serif.woff'),
            'userId' => get_current_user_id(),
        ]) . ';', 'before');
    }

    public static function url(int $issue = 0, string $article = '', array $extra = []): string
    {
        $args = array_filter(['page' => 'es-newsroom', 'issue' => $issue ?: null, 'article' => $article !== '' ? $article : null]) + $extra;
        return add_query_arg($args, admin_url('admin.php'));
    }

    public static function notices(): void
    {
        if (!current_user_can('es_edit') && !current_user_can('manage_options')) {
            return;
        }
        if (Mode::fault() || !Mode::ready()) {
            echo '<div class="notice notice-error"><p><strong>Газета остановлена или ждёт завершения обновления до ' . esc_html(ES_VERSION) . '.</strong> Материалы сохранены. <a href="' . esc_url(admin_url('admin.php?page=es-runtime')) . '">Открыть «Режим и восстановление»</a></p></div>';
        }
    }
}
