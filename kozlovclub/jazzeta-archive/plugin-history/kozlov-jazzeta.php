<?php
/**
 * Plugin Name: Ежедневный стандарт — газета Клуба Козлова
 * Description: Изолированная редакция, закрытый запуск и ручное утверждение выпусков.
 * Version: 0.3.2
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * Text Domain: kozlov-jazzeta
 */
namespace Kozlov\Standard;
if (!defined('ABSPATH')) { exit; }
define('ES_VERSION','0.3.2');
define('ES_FILE',__FILE__);
define('ES_DIR',__DIR__.'/');
// These callbacks deliberately do not depend on the editorial implementation.
register_deactivation_hook(__FILE__,static function(){
    update_option('es_runtime',['mode'=>'closed','changed_by'=>get_current_user_id(),'changed_at'=>gmdate('c')],false);
    global $wp_rewrite;
    foreach ($wp_rewrite->extra_rules_top as $pattern=>$target) {
        if (strpos($pattern,'^gazeta')===0 && strpos($target,'es_view=')!==false) { unset($wp_rewrite->extra_rules_top[$pattern]); }
    }
    flush_rewrite_rules(false);
});
register_activation_hook(__FILE__,static function($network_wide=false){
    if ((defined('KOZLOV_GAZETA_DISABLED')&&KOZLOV_GAZETA_DISABLED)||is_file(__DIR__.'/DISABLE-GAZETA')||!class_exists(Runtime::class,false)) {
        wp_die('Газета аварийно отключена или её загрузчик недоступен. Проверьте DISABLE-GAZETA и файлы плагина.');
    }
    Runtime::activate($network_wide);
});
// Check before parsing or loading any module. No secret URL or remote kill endpoint.
if ((defined('KOZLOV_GAZETA_DISABLED')&&KOZLOV_GAZETA_DISABLED)||is_file(__DIR__.'/DISABLE-GAZETA')) {
    // Persist the stop so removing the emergency flag cannot reopen a formerly public edition.
    try {
        $state=get_option('es_runtime',[]);
        if (!is_array($state)||($state['mode']??'')!=='paused') { update_option('es_runtime',['mode'=>'paused','changed_by'=>0,'changed_at'=>gmdate('c')],false); }
    } catch (\Throwable $ignored) { error_log('Kozlov Gazeta emergency stop: database state could not be recorded.'); }
    add_shortcode('kozlov_jazzeta','__return_empty_string');
    add_action('admin_notices',static function(){if(current_user_can('activate_plugins'))echo '<div class="notice notice-warning"><p>Газета аварийно отключена: DISABLE-GAZETA или KOZLOV_GAZETA_DISABLED. Её модули не загружаются.</p></div>';});
    return;
}
try {
    require_once ES_DIR.'includes/class-runtime.php';
    Runtime::boot();
} catch (\Throwable $e) {
    // Even an invalid runtime module must not make the unrelated front page fatal.
    update_option('es_runtime',['mode'=>'paused','changed_by'=>0,'changed_at'=>gmdate('c')],false);
    update_option('es_runtime_fault',['at'=>gmdate('c'),'type'=>get_class($e),'file'=>basename($e->getFile()),'line'=>$e->getLine()],false);
    error_log('Kozlov Gazeta bootstrap stopped: '.get_class($e).' '.basename($e->getFile()).':'.$e->getLine());
    add_action('admin_notices',static function(){if(current_user_can('activate_plugins'))echo '<div class="notice notice-error"><p>Газета не загружена из-за ошибки. Отключите её в списке плагинов или переименуйте только папку kozlov-jazzeta через хостинг.</p></div>';});
}
