<?php
namespace Kozlov\Standard;
if (!defined('ABSPATH')) { exit; }
/** Small gate loaded site-wide; editorial implementation is loaded only where needed. */
final class Runtime {
    private static $loaded=false;
    private static $reserve=null;
    private static $failing=false;
    public static function mode() {
        if (get_option('es_runtime_fault')) { return 'paused'; }
        $s=get_option('es_runtime',[]); $mode=is_array($s)?($s['mode']??'closed'):'closed';
        return in_array($mode,['closed','public','paused'],true)?$mode:'closed';
    }
    public static function owner() {
        $s=(array)get_option('es_security',[]);
        return current_user_can('es_edit')&&current_user_can('es_publish')&&get_current_user_id()===(int)($s['publisher_id']??0);
    }
    public static function can_read() { $mode=self::mode(); return $mode==='public'||($mode==='closed'&&current_user_can('es_edit')); }
    public static function ready() { return get_option('es_db_version')===ES_VERSION; }
    public static function path() {
        $path=rawurldecode((string)wp_parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH));
        $base=rtrim((string)wp_parse_url(home_url('/'),PHP_URL_PATH),'/');
        if ($base&&strpos($path,$base.'/')===0) { $path=substr($path,strlen($base)); }
        return $path;
    }
    public static function front_request() { return preg_match('~^/gazeta(?:/|$)~',self::path()) || isset($_GET['es_view']); }
    public static function rest_path($route) { return is_string($route)&&preg_match('~^/ezhednevny-standard/v1(?:/|$)~',$route); }
    public static function rest_request() {
        $prefix='/'.rest_get_url_prefix(); $path=self::path();
        return (strpos($path,$prefix.'/')===0&&self::rest_path(substr($path,strlen($prefix)))) || self::rest_path($_GET['rest_route']??'');
    }
    public static function boot() {
        if ((self::front_request()||self::rest_request())&&!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE',true); }
        add_action('init',[self::class,'rules']);
        add_filter('query_vars',static function($v){return array_merge($v,['es_view','es_key','es_page']);});
        add_action('parse_request',static function($wp){
            if (self::front_request()||!empty($wp->query_vars['es_view'])) {
                if (!self::can_read()) { self::deny(404); }
                if (!self::ready()) { self::deny(503); }
                if (self::mode()!=='public') { self::private_headers(); }
            }
        },0);
        add_filter('rest_pre_dispatch',static function($result,$server,$request){
            if (!self::rest_path($request->get_route())) { return $result; }
            if (self::mode()==='paused') { return new \WP_Error('es_unavailable','Страница недоступна.',['status'=>404]); }
            if (!self::ready()) { return new \WP_Error('es_unavailable','Газета временно недоступна.',['status'=>503]); }
            return $result;
        },1,3);
        add_filter('rest_post_dispatch',static function($response,$server,$request){
            if (self::rest_path($request->get_route())) {
                $response->header('Cache-Control','private, no-store, max-age=0');
                $response->header('Vary','Cookie'); $response->header('X-Robots-Tag','noindex, nofollow, noarchive');
            } return $response;
        },99,3);
        add_filter('robots_txt',static function($txt,$public){
            if (!$public||self::mode()!=='public'||!self::ready()) { return $txt; }
            $url=get_option('permalink_structure')?home_url('/gazeta/sitemap.xml'):add_query_arg('es_view','sitemap',home_url('/'));
            return $txt."\nSitemap: ".$url."\n";
        },10,2);
        add_shortcode('kozlov_jazzeta',static function(){
            if (!self::can_read()||!self::ready()) { return ''; }
            $s=(array)get_option('es_appearance',[]);$name=$s['name']??'Газета';
            $url=get_option('permalink_structure')?home_url('/gazeta/'):add_query_arg('es_view','latest',home_url('/'));
            return '<p class="es-entry"><a href="'.esc_url($url).'">Читать «'.esc_html($name).'» →</a></p>';
        });
        add_action('admin_post_es_runtime_action',[self::class,'handle']);
        add_action('admin_notices',[self::class,'notice']);
        add_action('plugins_loaded',[self::class,'start']);
    }
    public static function rules() {
        foreach ([
            '^gazeta/?$'=>'latest',
            '^gazeta/([0-9]{4}-[0-9]{2}-[0-9]{2})/?$'=>'issue&es_key=$matches[1]',
            '^gazeta/issue/([0-9]{4}-[0-9]{2}-[0-9]{2})/?$'=>'legacy_issue&es_key=$matches[1]',
            '^gazeta/material/([a-z0-9_-]{1,160})/?$'=>'article&es_key=$matches[1]',
            '^gazeta/archive/?$'=>'archive&es_page=1',
            '^gazeta/archive/page/([1-9][0-9]*)/?$'=>'archive&es_page=$matches[1]',
            '^gazeta/preview/([0-9]+)/?$'=>'preview&es_key=$matches[1]',
            '^gazeta/sitemap\.xml$'=>'sitemap',
            '^gazeta/sitemap-(issues|articles)-([1-9][0-9]*)\.xml$'=>'sitemap_part&es_key=$matches[1]&es_page=$matches[2]'
        ] as $pattern=>$query) { add_rewrite_rule($pattern,'index.php?es_view='.$query,'top'); }
    }
    public static function load() {
        if (self::$loaded) { return; }
        if (self::$reserve===null) { self::$reserve=str_repeat('x',65536); register_shutdown_function([self::class,'shutdown']); }
        foreach (['db','settings','validation','duplicates','repository','admin','rest','public','migration'] as $component) { require_once ES_DIR.'includes/class-'.$component.'.php'; }
        self::$loaded=true;
    }
    public static function start() {
        $admin=is_admin()&&!wp_doing_ajax();
        if (!$admin&&PHP_SAPI!=='cli'&&!self::front_request()&&!self::rest_request()) { return; }
        if (get_option('es_runtime_fault')) { self::recovery_menu(); return; }
        if (!$admin&&PHP_SAPI!=='cli'&&(self::mode()==='paused'||(self::front_request()&&!self::can_read()))) { return; }
        try {
            self::load();
            if (!self::ready()) { self::recovery_menu(); return; }
            Settings::init(); Admin::init(); Rest::init(); Front::init();
            add_action('admin_menu',static function(){add_submenu_page('es-newsroom','Режим и остановка','Режим и остановка','es_edit','es-runtime',[self::class,'screen']);},20);
        } catch (\Throwable $e) { self::fault($e); self::recovery_menu(); }
    }
    private static function recovery_menu() {
        add_action('admin_menu',static function(){add_menu_page('Газета: восстановление','Газета: восстановление',current_user_can('manage_options')?'manage_options':'es_edit','es-runtime',[self::class,'screen'],'dashicons-shield',29);});
    }
    public static function activate($network_wide) {
        try { self::load(); DB::activate($network_wide); }
        catch (\Throwable $e) { self::fault($e); wp_die('Газета не активирована: ошибка её модулей. Другие плагины не отключались. Проверьте журнал сервера.'); }
    }
    public static function guard($callback) {
        return static function()use($callback){
            $level=ob_get_level(); ob_start();
            try { call_user_func($callback); if (ob_get_level()>$level) { ob_end_flush(); } }
            catch (\Throwable $e) { while(ob_get_level()>$level) { ob_end_clean(); } self::fault($e); self::deny(503); }
        };
    }
    public static function fault($e) {
        if (self::$failing) { return; } self::$failing=true; self::$reserve=null;
        if (class_exists(DB::class,false)) { DB::rollback(); }
        $f=['at'=>gmdate('c'),'type'=>get_class($e),'file'=>basename($e->getFile()),'line'=>$e->getLine()];
        update_option('es_runtime_fault',$f,false);
        update_option('es_runtime',['mode'=>'paused','changed_by'=>0,'changed_at'=>gmdate('c')],false);
        error_log('Kozlov Gazeta stopped: '.$f['type'].' '.$f['file'].':'.$f['line']);
        self::$failing=false;
    }
    public static function shutdown() {
        $e=error_get_last();
        if (!$e||!in_array($e['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR],true)||strpos(wp_normalize_path($e['file']),wp_normalize_path(ES_DIR))!==0) { return; }
        self::$reserve=null;
        // Best effort only: no PHP handler can recover every OOM, server failure or main-file parse error.
        if (class_exists(DB::class,false)) { DB::rollback(); }
        update_option('es_runtime_fault',['at'=>gmdate('c'),'type'=>'fatal','file'=>basename($e['file']),'line'=>$e['line']],false);
        update_option('es_runtime',['mode'=>'paused','changed_by'=>0,'changed_at'=>gmdate('c')],false);
    }
    public static function private_headers() {
        if (headers_sent()) { return; }
        nocache_headers(); header('Cache-Control: private, no-store, max-age=0');
        header('X-Robots-Tag: noindex, nofollow, noarchive'); header('Vary: Cookie',false); header('Referrer-Policy: no-referrer');
    }
    public static function deny($code) {
        self::private_headers(); status_header($code); header('Content-Type: text/html; charset=UTF-8'); header('X-Content-Type-Options: nosniff');
        echo '<!doctype html><html lang="ru"><meta charset="utf-8"><meta name="robots" content="noindex,nofollow"><title>Страница недоступна</title><p>Страница недоступна.</p></html>';exit;
    }
    public static function notice() {
        if (!current_user_can('es_edit')&&!current_user_can('manage_options')) { return; }
        $mode=self::mode();$fault=get_option('es_runtime_fault');
        if (!self::ready()||$fault) { echo '<div class="notice notice-error"><p>Газета остановлена или требует активации версии 0.3.2. <a href="'.esc_url(admin_url('admin.php?page=es-runtime')).'">Режим и восстановление</a>. Материалы сохранены.</p></div>'; }
        elseif (isset($_GET['page'])&&is_string($_GET['page'])&&strpos($_GET['page'],'es-')===0&&$mode!=='public') { echo '<div class="notice notice-warning"><p>'.($mode==='paused'?'Газета остановлена.':'Закрытый режим: даже утверждённые выпуски видны только допущенной редакции.').' <a href="'.esc_url(admin_url('admin.php?page=es-runtime')).'">Режим и остановка</a></p></div>'; }
    }
    public static function screen() {
        if (!current_user_can('es_edit')&&!current_user_can('manage_options')) { wp_die('Нет прав.'); }
        $mode=self::mode();$fault=get_option('es_runtime_fault');$names=['closed'=>'Закрытый тест — только редакция','public'=>'Публичный доступ','paused'=>'Остановлено'];
        echo '<div class="wrap"><h1>Режим и остановка газеты</h1><h2>'.esc_html($names[$mode]).'</h2><p>Новая установка и переход с 0.3.1 начинаются в закрытом режиме. Доступ имеют только пользователи с правом es_edit. Обычный подписчик, автор и импортёр газету не видят.</p>';
        echo '<p>На рабочем сайте до проверки нужны резервная копия, доступ к файлам хостинга и исключение /gazeta/*, API газеты и query URL из серверного HTML-кэша/CDN. Заголовки плагина не могут остановить кэш, который ответил раньше WordPress.</p>';
        echo '<p>Медиатека WordPress и прямые URL её файлов остаются публичными. Для закрытого теста используйте уже открытые или неконфиденциальные изображения.</p>';
        if ($fault) { echo '<p><strong>Зафиксирована ошибка:</strong> '.esc_html(($fault['at']??'').' / '.($fault['type']??'').' / '.($fault['file']??'').':'.($fault['line']??'')).'. Подробности — в журнале сервера. Исправьте причину до возобновления.</p>'; }
        $can_stop=current_user_can('manage_options')||self::owner();
        if ($can_stop&&$mode!=='paused') { self::form('paused','Остановить газету',false); }
        if ($can_stop&&($mode!=='closed'||$fault)) { self::form('closed',$fault?'Проверить загрузку и вернуть закрытый режим':'Вернуть закрытый режим',(bool)$fault); }
        if (self::owner()&&$mode==='closed'&&!$fault&&self::ready()) { echo '<hr><h2>Открыть посетителям</h2><p>Станут доступны ВСЕ ранее утверждённые выпуски, включая тестовые. Открывайте только после их проверки и удаления/снятия тестовых номеров. Это отдельное действие от утверждения статьи или выпуска.</p>';self::form('public','Открыть газету посетителям',true); }
        echo '<hr><h2>Если админка не открывается</h2><ol><li>В файловом менеджере хостинга создайте пустой файл <code>wp-content/plugins/kozlov-jazzeta/DISABLE-GAZETA</code> (без расширения). Проверка стоит до загрузки модулей.</li><li>Если это не помогло, переименуйте только папку <code>kozlov-jazzeta</code> в <code>kozlov-jazzeta.off</code>. Не переименовывайте всю папку plugins.</li></ol><p>База газеты сохраняется. Перед возвратом удалите причину ошибки; возобновляйте закрытый режим. Файл DISABLE-GAZETA не переносится автоматически при переустановке ZIP.</p></div>';
    }
    private static function form($next,$label,$confirm) {
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="margin:18px 0"><input type="hidden" name="action" value="es_runtime_action"><input type="hidden" name="next" value="'.esc_attr($next).'">';
        wp_nonce_field('es-runtime-'.self::mode().'-'.$next);
        if ($confirm) { echo '<p><label><input type="checkbox" name="confirm" value="1" required> '.($next==='public'?'Я проверил резервную копию, исключения кэша и все утверждённые выпуски; разрешаю публичный доступ.':'Причина ошибки устранена; разрешаю проверить загрузку модулей и включить закрытый режим.').'</label></p>'; }
        submit_button($label,$next==='public'?'secondary':'primary','submit',false);echo '</form>';
    }
    public static function handle() {
        if (($_SERVER['REQUEST_METHOD']??'')!=='POST') { wp_die('Требуется POST.','',['response'=>405]); }
        $next=isset($_POST['next'])&&is_string($_POST['next'])?sanitize_key(wp_unslash($_POST['next'])):'';
        if (!in_array($next,['closed','public','paused'],true)||(!current_user_can('manage_options')&&!self::owner())||!get_current_user_id()||(int)wp_validate_auth_cookie('','logged_in')!==get_current_user_id()) { wp_die('Нет прав.','',['response'=>403]); }
        check_admin_referer('es-runtime-'.self::mode().'-'.$next);
        $fault=get_option('es_runtime_fault');
        if ($next==='public'&&(!self::owner()||self::mode()!=='closed'||$fault||!self::ready())) { wp_die('Публичное открытие недоступно.','',['response'=>403]); }
        if (($next==='public'||($next==='closed'&&$fault))&&($_POST['confirm']??'')!=='1') { wp_die('Требуется явное подтверждение.','',['response'=>403]); }
        if ($next==='closed'&&$fault) { try { self::load(); if (!self::ready()) { throw new \RuntimeException('Требуется повторная активация.'); } } catch (\Throwable $e) { self::fault($e); wp_die('Проверка не пройдена. Газета остаётся остановленной.','',['response'=>503]); } }
        if ($next==='closed') { delete_option('es_runtime_fault'); }
        update_option('es_runtime',['mode'=>$next,'changed_by'=>get_current_user_id(),'changed_at'=>gmdate('c')],false);
        wp_safe_redirect(admin_url('admin.php?page=es-runtime&saved=1'));exit;
    }
}
