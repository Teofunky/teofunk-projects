<?php
namespace Kozlov\Standard;
if (!defined('ABSPATH')) { exit; }
final class Rest {
    public static function init() { add_action('rest_api_init',[self::class,'routes']); }
    public static function permission() { if(Runtime::mode()==='paused')return new \WP_Error('es_unavailable','Газета остановлена.',['status'=>404]); return current_user_can('es_import')?true:new \WP_Error('es_forbidden','Нет права es_import.',['status'=>403]); }
    public static function routes() {
        register_rest_route('ezhednevny-standard/v1','/import',['methods'=>'POST','permission_callback'=>[self::class,'permission'],'callback'=>[self::class,'import']]);
        register_rest_route('ezhednevny-standard/v1','/latest',['methods'=>'GET','permission_callback'=>static function(){return Runtime::can_read()?true:new \WP_Error('es_unavailable','Страница недоступна.',['status'=>404]);},'callback'=>static function(){ $row=Front::latest(); if (!$row) { return new \WP_Error('es_empty','Нет опубликованных выпусков.',['status'=>404]); } return rest_ensure_response(json_decode($row['public_json'],true)); }]);
    }
    public static function import($request) {
        if (strpos(strtolower((string)$request->get_header('content-type')),'application/json')!==0) { return Validation::error('Content-Type должен быть application/json.',415); }
        if (strlen($request->get_body())>Validation::MAX_BYTES) { return Validation::error('Максимум 256 КиБ.',413); }
        global $wpdb; $t=DB::table('state'); $name='rate_'.get_current_user_id(); $window=(int)floor(time()/60); $base=$window*100;
        // One fixed row per account. Atomic quota, not an IP-based or race-prone transient.
        $ok=$wpdb->query($wpdb->prepare("INSERT INTO $t (name,value) VALUES (%s,%d) ON DUPLICATE KEY UPDATE value=IF(value<%d,%d,LEAST(value+1,%d))",$name,$base+1,$base,$base+1,$base+99));
        if ($ok===false) { return Validation::error('Не удалось проверить лимит импорта.',503); }
        $count=(int)$wpdb->get_var($wpdb->prepare("SELECT value FROM $t WHERE name=%s",$name))-$base;
        if ($count>10) { return Validation::error('Не более 10 запросов импорта в минуту на пользователя.',429); }
        $match=trim((string)$request->get_header('if-match'),'" ');
        if ($match!=='' && !ctype_digit($match)) { return Validation::error('If-Match должен содержать числовую версию.'); }
        $result=Repository::import($request->get_body(),$match===''?null:(int)$match);
        if (is_wp_error($result)) { return $result; }
        $response=new \WP_REST_Response($result,$result['unchanged']?200:201); $response->header('ETag','"'.$result['version'].'"'); $response->header('Cache-Control','no-store'); return $response;
    }
}
