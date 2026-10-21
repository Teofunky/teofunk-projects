<?php
namespace Kozlov\Standard;
if (!defined('ABSPATH')) { exit; }
/** Appearance only. This option never stores article or issue data. */
final class Settings {
    public static function defaults() {
        return ['name'=>'Ежедневный стандарт','subtitle'=>'Хроника джаза и музыки нашего времени','motto'=>'Читать между нот','logo_id'=>0,'club_home_url'=>home_url('/'),'club_events_url'=>home_url('/'),'background'=>'#152320','paper'=>'#f3f0e7','ink'=>'#191d1b','accent'=>'#8b352d','font'=>'georgia','template'=>'classic','layout'=>'editorial','social_template'=>'classic-4x5','config_version'=>1];
    }
    public static function get() { return array_merge(self::defaults(),(array)get_option('es_appearance',[])); }
    public static function name() { return self::get()['name']; }
    public static function init() {
        add_action('admin_menu',static function(){add_submenu_page('es-newsroom','Оформление газеты','Оформление','manage_options','es-appearance',[self::class,'screen']); add_submenu_page('es-newsroom','Доступ к газете','Доступ','manage_options','es-access',[self::class,'access']);},20);
        add_action('admin_init',static function(){register_setting('es_appearance_group','es_appearance',['type'=>'array','sanitize_callback'=>[self::class,'sanitize'],'default'=>self::defaults(),'show_in_rest'=>false]); register_setting('es_security_group','es_security',['type'=>'array','sanitize_callback'=>[self::class,'security'],'show_in_rest'=>false]);});
    }
    public static function sanitize($input) {
        $old=self::get(); $out=$old;
        if (!current_user_can('manage_options')||!is_array($input)) { return $old; }
        foreach (['name'=>80,'subtitle'=>150,'motto'=>120] as $key=>$max) {
            if (!isset($input[$key])||!is_string($input[$key])) { continue; }
            $s=trim(sanitize_text_field($input[$key]));
            if (($key==='name'&&$s==='') || (function_exists('mb_strlen')?mb_strlen($s):strlen($s))>$max) { add_settings_error('es_appearance',$key,'Проверьте длину поля '.$key.'. Прежнее значение сохранено.'); } else { $out[$key]=$s; }
        }
        foreach (['background','paper','ink','accent'] as $key) {
            $color=isset($input[$key])&&is_string($input[$key])?sanitize_hex_color($input[$key]):null;
            if ($color) { $out[$key]=$color; }
        }
        foreach (['club_home_url','club_events_url'] as $key) {
            $u=isset($input[$key])&&is_string($input[$key])?esc_url_raw($input[$key],['http','https']):'';
            $p=wp_parse_url($u);
            if ($u&&($p['host']??'')===wp_parse_url(home_url(),PHP_URL_HOST)&&!isset($p['user'])&&!isset($p['pass'])) { $out[$key]=$u; }
            else { add_settings_error('es_appearance',$key,'Ссылки клуба должны вести на этот сайт.'); }
        }
        $logo=isset($input['logo_id'])&&is_scalar($input['logo_id'])?absint($input['logo_id']):0;
        if (!$logo || (wp_attachment_is_image($logo)&&in_array(get_post_mime_type($logo),['image/png','image/jpeg','image/webp','image/avif'],true))) { $out['logo_id']=$logo; }
        else { add_settings_error('es_appearance','logo','Выберите растровый логотип в медиатеке.'); }
        $out['font']=in_array($input['font']??'',['georgia','times'],true)?$input['font']:$old['font'];
        // Template identifiers are an allowlist. Never allow user-controlled include paths, CSS or JS.
        $out['template']='classic';$out['layout']='editorial';$out['social_template']='classic-4x5';$out['config_version']=1;
        return $out;
    }
    public static function screen() {
        if (!current_user_can('manage_options')) { wp_die('Нет прав.'); }
        $s=self::get();
        echo '<div class="wrap es-admin"><h1>Оформление газеты</h1><p>Название и дизайн независимы от материалов. Адрес остаётся <code>/gazeta/</code>.</p>';settings_errors('es_appearance');
        echo '<form method="post" action="options.php" class="es-form">'; settings_fields('es_appearance_group');
        foreach (['name'=>'Название','subtitle'=>'Подзаголовок','motto'=>'Девиз','club_home_url'=>'Главная клуба','club_events_url'=>'Афиша клуба'] as $key=>$label) { Admin::field('es_appearance['.$key.']',$label,$s[$key]); }
        Admin::field('es_appearance[logo_id]','ID логотипа в медиатеке (0 — текстовая шапка)',$s['logo_id'],'number');
        echo '<button type="button" class="button" id="es-choose-logo">Выбрать логотип</button><p>Логотип заменяет текстовую шапку; название остаётся в заголовках страниц и метаданных. Поддерживаются PNG, JPEG, WebP, AVIF.</p><h2>Палитра и типографика</h2><div class="es-grid">';
        foreach (['background'=>'Фон вокруг газеты','paper'=>'Бумага','ink'=>'Основной текст','accent'=>'Акцент'] as $key=>$label) { Admin::field('es_appearance['.$key.']',$label,$s[$key],'color'); }
        Admin::field('es_appearance[font]','Основной шрифт',$s['font'],'select',['georgia'=>'Georgia — утверждённый','times'=>'Times New Roman']);
        echo '</div><p>Текущий шаблон: классический газетный. Компоновка: редакционные колонки. Социальные карточки: классические 4:5. Эти параметры хранятся отдельно; дополнительные варианты подключаются в последующих версиях.</p><p>После изменения цветов проверьте контраст текста и ссылок на компьютере и телефоне.</p>';
        submit_button('Сохранить оформление');echo '</form></div>';
    }

    public static function security($input) {
        $old=(array)get_option('es_security',[]);
        if (!current_user_can('manage_options')||!is_array($input)||!is_scalar($input['publisher_id']??null)) { return $old; }
        $id=absint($input['publisher_id']);
        if (!$id||!user_can($id,'es_publish')||!user_can($id,'es_edit')) { add_settings_error('es_security','publisher','Выберите пользователя с правами редакции и публикации газеты.'); return $old; }
        return ['publisher_id'=>$id];
    }
    public static function access() {
        if (!current_user_can('manage_options')) { wp_die('Нет прав.'); }
        $security=(array)get_option('es_security',[]);
        echo '<div class="wrap es-admin"><h1>Доступ к газете</h1><p>Публиковать и снимать выпуски может только выбранный владелец с правами es_edit и es_publish. По умолчанию — администратор, активировавший плагин.</p>';settings_errors('es_security');
        echo '<form method="post" action="options.php" class="es-form">';settings_fields('es_security_group');
        Admin::field('es_security[publisher_id]','ID учётной записи владельца',$security['publisher_id']??0,'number');
        echo '<p>Ваш ID: '.get_current_user_id().'. Изменение допуска доступно администраторам сайта. Для сотрудников используйте роль «Газета — редактор», для сборщика — только «Газета — импортёр».</p>';
        submit_button('Сохранить владельца');echo '</form></div>';
    }
    public static function head_css() {
        $s=self::get();$font=$s['font']==='times'?"'Times New Roman',Georgia,serif":"Georgia,'Times New Roman',serif";
        return '#es-newspaper{--bg:'.sanitize_hex_color($s['background']).';--paper:'.sanitize_hex_color($s['paper']).';--ink:'.sanitize_hex_color($s['ink']).';--wine:'.sanitize_hex_color($s['accent']).';--serif:'.$font.'}';
    }
}
