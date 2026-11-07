<?php
/**
 * Plugin Name:       Ежедневный стандарт — газета Клуба Козлова
 * Description:       Музыкальная газета Клуба Алексея Козлова: редакция, ручное утверждение выпусков, архив по адресу /gazeta/.
 * Version:           0.5.11
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 * Text Domain:       kozlov-jazzeta
 *
 * Bootstrap only. Everything that can fail is loaded after the emergency switch
 * and inside a guard, so a broken newspaper module never takes down the club site.
 */

namespace Kozlov\Gazeta;

if (!defined('ABSPATH')) {
    exit;
}

define('ES_VERSION', '0.5.11');
define('ES_FILE', __FILE__);
define('ES_DIR', __DIR__ . '/');

/** True when DISABLE-GAZETA exists or KOZLOV_GAZETA_DISABLED is set. Checked before any module loads. */
function es_emergency_stop(): bool
{
    return (defined('KOZLOV_GAZETA_DISABLED') && KOZLOV_GAZETA_DISABLED) || is_file(__DIR__ . '/DISABLE-GAZETA');
}

register_deactivation_hook(__FILE__, static function () {
    // Closed again on next activation; nothing is deleted. Same behaviour as 0.3.x.
    update_option('es_runtime', ['mode' => 'closed', 'changed_by' => get_current_user_id(), 'changed_at' => gmdate('c')], false);
    global $wp_rewrite;
    if ($wp_rewrite instanceof \WP_Rewrite) {
        foreach ($wp_rewrite->extra_rules_top as $pattern => $target) {
            // Only our own rules (target es_view=...), exactly as 0.3.5 did.
            if (strpos($pattern, '^gazeta') === 0 && strpos((string) $target, 'es_view=') !== false) {
                unset($wp_rewrite->extra_rules_top[$pattern]);
            }
        }
    }
    flush_rewrite_rules(false);
});

register_activation_hook(__FILE__, static function ($network_wide = false) {
    if (es_emergency_stop()) {
        wp_die('Газета аварийно отключена файлом DISABLE-GAZETA или константой KOZLOV_GAZETA_DISABLED. Уберите флаг и повторите активацию.');
    }
    require_once ES_DIR . 'src/autoload.php';
    Plugin::activate((bool) $network_wide);
});

if (es_emergency_stop()) {
    // Persist the stop so that removing the flag can never silently reopen a public newspaper.
    try {
        $state = get_option('es_runtime', []);
        if (!is_array($state) || ($state['mode'] ?? '') !== 'paused') {
            update_option('es_runtime', ['mode' => 'paused', 'changed_by' => 0, 'changed_at' => gmdate('c')], false);
        }
    } catch (\Throwable $ignored) {
        error_log('Kozlov Gazeta emergency stop: state could not be recorded.');
    }
    add_shortcode('kozlov_jazzeta', '__return_empty_string');
    add_action('admin_notices', static function () {
        if (current_user_can('activate_plugins')) {
            echo '<div class="notice notice-warning"><p>Газета аварийно отключена (DISABLE-GAZETA или KOZLOV_GAZETA_DISABLED). Модули газеты не загружаются, материалы сохранены.</p></div>';
        }
    });
    return;
}

try {
    require_once ES_DIR . 'src/autoload.php';
    Plugin::boot();
} catch (\Throwable $e) {
    // Even an invalid module must not make the club's front page fatal.
    update_option('es_runtime', ['mode' => 'paused', 'changed_by' => 0, 'changed_at' => gmdate('c')], false);
    update_option('es_runtime_fault', [
        'at' => gmdate('c'),
        'type' => get_class($e),
        'file' => basename($e->getFile()),
        'line' => $e->getLine(),
    ], false);
    error_log('Kozlov Gazeta bootstrap stopped: ' . get_class($e) . ' ' . basename($e->getFile()) . ':' . $e->getLine());
    add_action('admin_notices', static function () {
        if (current_user_can('activate_plugins')) {
            echo '<div class="notice notice-error"><p>Газета не загружена из-за ошибки и остановлена. Материалы сохранены. Отключите плагин в списке или переименуйте только папку kozlov-jazzeta.</p></div>';
        }
    });
}
