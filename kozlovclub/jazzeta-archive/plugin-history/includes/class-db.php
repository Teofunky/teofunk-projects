<?php
namespace Kozlov\Standard;
if (!defined('ABSPATH')) { exit; }
final class DB {
    private static $in_transaction=false;
    public static function table($name) { global $wpdb; return $wpdb->prefix . 'es_' . $name; }
    public static function activate($network_wide = false) {
        if ($network_wide || is_multisite()) { wp_die('0.3.2 предназначена для одиночной установки WordPress. Multisite пока не поддерживается.'); }
        global $wpdb;
        if (version_compare(PHP_VERSION, '7.4', '<') || version_compare(get_bloginfo('version'), '6.4', '<')) { wp_die('Требуются PHP 7.4+ и WordPress 6.4+.'); }
        if (get_page_by_path(Front::BASE,OBJECT,['page','post'])) { wp_die('Адрес /' . esc_html(Front::BASE) . '/ уже занят страницей. Существующая страница не изменена.'); }
        foreach (get_post_types([], 'objects') as $type) {
            if (($type->rewrite['slug']??'')===Front::BASE || $type->has_archive===Front::BASE) { wp_die('Адрес газеты занят существующим типом записей. Активация остановлена.'); }
        }
        foreach ((array)get_option('rewrite_rules',[]) as $pattern=>$target) {
            if (strpos($target,'es_view=')===false && preg_match('/^\^?'.preg_quote(Front::BASE,'/').'(?:[\/($?]|$)/',$pattern)) { wp_die('Адрес газеты занят другим правилом сайта. Активация остановлена.'); }
        }
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate();
        $i=self::table('issues'); $a=self::table('articles'); $r=self::table('revisions'); $s=self::table('state');
        dbDelta("CREATE TABLE $i (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 edition_date date NOT NULL,
 number bigint unsigned DEFAULT NULL,
 state varchar(16) NOT NULL DEFAULT 'draft',
 version bigint unsigned NOT NULL DEFAULT 1,
 public_version bigint unsigned NOT NULL DEFAULT 0,
 draft_json longtext NOT NULL,
 public_json longtext DEFAULT NULL,
 import_hash char(64) NOT NULL DEFAULT '',
 human_touched tinyint NOT NULL DEFAULT 0,
 created_by bigint unsigned NOT NULL,
 created_at datetime NOT NULL,
 updated_at datetime NOT NULL,
 published_at datetime DEFAULT NULL,
 public_updated_at datetime DEFAULT NULL,
 approved_by bigint unsigned DEFAULT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY edition_date (edition_date),
 UNIQUE KEY number (number),
 KEY public_archive (state,edition_date,id)
) ENGINE=InnoDB $c;");
        dbDelta("CREATE TABLE $a (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 issue_id bigint unsigned NOT NULL,
 external_id varchar(64) NOT NULL,
 slug varchar(160) NOT NULL,
 position smallint unsigned NOT NULL,
 active tinyint NOT NULL DEFAULT 1,
 story_group varchar(120) NOT NULL,
 public_story_group varchar(120) NOT NULL DEFAULT '',
 draft_json longtext NOT NULL,
 public_json longtext DEFAULT NULL,
 reviewed_hash char(64) NOT NULL DEFAULT '',
 reviewed_by bigint unsigned DEFAULT NULL,
 reviewed_at datetime DEFAULT NULL,
 review_note text NOT NULL,
 new_fact text NOT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY issue_external (issue_id,external_id),
 UNIQUE KEY slug (slug),
 KEY issue_position (issue_id,active,position),
 KEY public_story (public_story_group,issue_id)
) ENGINE=InnoDB $c;");
        dbDelta("CREATE TABLE $r (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 issue_id bigint unsigned NOT NULL,
 version bigint unsigned NOT NULL,
 actor_id bigint unsigned NOT NULL,
 action varchar(32) NOT NULL,
 created_at datetime NOT NULL,
 snapshot longtext NOT NULL,
 PRIMARY KEY  (id),
 KEY issue_history (issue_id,id)
) ENGINE=InnoDB $c;");
        dbDelta("CREATE TABLE $s (
 name varchar(64) NOT NULL,
 value bigint unsigned NOT NULL DEFAULT 0,
 PRIMARY KEY  (name)
) ENGINE=InnoDB $c;");
        foreach ([$i,$a,$r,$s] as $t) {
            $engine=$wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$t));
            if ($engine !== 'InnoDB') { wp_die('Не удалось создать транзакционные таблицы газеты. Активация остановлена.'); }
        }
        Duplicates::install();
        DB::must($wpdb->query("INSERT IGNORE INTO $s (name,value) VALUES ('number',0),('catalog',1),('write',0)"));
        add_role('es_importer','Газета — импортёр',['read'=>true,'es_import'=>true]);
        add_role('es_editor','Газета — редактор',['read'=>true,'es_edit'=>true]);
        $admin=get_role('administrator');
        if ($admin) { foreach (['es_edit','es_import','es_publish'] as $cap) { $admin->add_cap($cap); } }
        add_option('es_runtime',['mode'=>'closed','changed_by'=>get_current_user_id(),'changed_at'=>gmdate('c')],'',false);
        add_option('es_security',['publisher_id'=>get_current_user_id()],'',false);
        update_option('es_db_version', ES_VERSION, false);
        Front::rules(); flush_rewrite_rules(false);
    }
    public static function deactivate() {
        update_option('es_runtime',['mode'=>'closed','changed_by'=>get_current_user_id(),'changed_at'=>gmdate('c')],false);
        global $wp_rewrite;
        foreach (array_keys($wp_rewrite->extra_rules_top) as $rule) {
            if (strpos($rule, '^' . Front::BASE) === 0) { unset($wp_rewrite->extra_rules_top[$rule]); }
        }
        flush_rewrite_rules(false);
        // No schedules, external jobs or data deletion. Grants are plugin-specific and inert while disabled.
    }
    public static function begin() { global $wpdb; self::must($wpdb->query('START TRANSACTION')); self::$in_transaction=true; }
    public static function commit() { global $wpdb; self::must($wpdb->query('COMMIT')); self::$in_transaction=false; }
    public static function rollback() { global $wpdb; if (self::$in_transaction) { $wpdb->query('ROLLBACK'); self::$in_transaction=false; } }
    public static function must($result) { if ($result === false) { throw new \RuntimeException('Ошибка записи в БД. Операция отменена; повторите после проверки журнала сервера.'); } return $result; }
    public static function json($value) {
        $json=wp_json_encode($value, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if ($json===false) { throw new \RuntimeException('Не удалось закодировать данные.'); }
        return $json;
    }
    public static function now() { return gmdate('Y-m-d H:i:s'); }
    public static function catalog() { global $wpdb; return (int)$wpdb->get_var('SELECT value FROM '.self::table('state')." WHERE name='catalog'"); }
    public static function invalidate() { global $wpdb; self::must($wpdb->query('UPDATE '.self::table('state')." SET value=value+1 WHERE name='catalog'")); }
}
