<?php
/**
 * Plugin Name: Ежедневный стандарт — газета Клуба Козлова
 * Description: Изолированная редакция, постоянные адреса и ручное утверждение выпусков.
 * Version: 0.3.0
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * Text Domain: kozlov-jazzeta
 */
namespace Kozlov\Standard;
if (!defined('ABSPATH')) { exit; }
define('ES_VERSION', '0.3.0');
define('ES_FILE', __FILE__);
define('ES_DIR', __DIR__ . '/');
foreach (['db','settings','validation','duplicates','repository','admin','rest','public','migration'] as $component) {
    require_once ES_DIR . 'includes/class-' . $component . '.php';
}
register_activation_hook(__FILE__, [DB::class, 'activate']);
register_deactivation_hook(__FILE__, [DB::class, 'deactivate']);
add_action('plugins_loaded', static function () {
    if (get_option('es_db_version') !== ES_VERSION) {
        add_action('admin_notices', static function () {
            if (current_user_can('activate_plugins')) {
                echo '<div class="notice notice-error"><p>Газета: требуется деактивировать и вновь активировать плагин для подготовки таблиц 0.3.0. Данные сохраняются.</p></div>';
            }
        });
        return;
    }
    Settings::init(); Admin::init(); Rest::init(); Front::init();
});
