<?php
/**
 * Plugin Name: Kozlov Jazzeta — Jazz Newspaper
 * Description: Independent daily jazz newspaper with JSON issues, archive and social summaries. No theme modifications.
 * Version: 0.1.0
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 */
if (!defined('ABSPATH')) { exit; }

final class Kozlov_Jazzeta {
    const CPT = 'jz_issue';
    const META = '_jz_issue_json';
    const REST = 'kozlov-jazzeta/v1';

    public static function init() {
        add_action('init', [__CLASS__, 'register']);
        add_action('admin_menu', [__CLASS__, 'admin_menu']);
        add_action('rest_api_init', [__CLASS__, 'rest_routes']);
        add_shortcode('kozlov_jazzeta', [__CLASS__, 'shortcode']);
    }

    public static function register() {
        register_post_type(self::CPT, [
            'labels' => ['name' => 'Джазета: выпуски', 'singular_name' => 'Выпуск Джазеты'],
            'public' => false, 'show_ui' => true, 'show_in_menu' => true,
            'menu_icon' => 'dashicons-media-document', 'show_in_rest' => false,
            'supports' => ['title', 'revisions'], 'capability_type' => 'post',
            'has_archive' => false, 'rewrite' => false,
        ]);
    }

    public static function admin_menu() {
        add_submenu_page('edit.php?post_type='.self::CPT, 'Импорт выпуска', 'Импорт JSON', 'edit_others_posts', 'jz-import', [__CLASS__, 'import_screen']);
    }

    private static function safe_text($value, $max=5000) {
        if (!is_string($value)) return '';
        $value=sanitize_text_field($value);
        return function_exists('mb_substr') ? mb_substr($value,0,$max) : substr($value,0,$max);
    }

    public static function validate($doc) {
        if (!is_array($doc) || !isset($doc['issue'], $doc['articles']) || !is_array($doc['issue']) || !is_array($doc['articles'])) {
            return new WP_Error('jz_bad_json', 'Нужны поля issue и articles.', ['status'=>400]);
        }
        $date = isset($doc['issue']['date']) ? $doc['issue']['date'] : '';
        $dt = is_string($date) ? DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC')) : false;
        if (!$dt || $dt->format('Y-m-d') !== $date) { return new WP_Error('jz_date', 'Неправильная дата выпуска.', ['status'=>400]); }
        $n = count($doc['articles']);
        if ($n < 4 || $n > 7) { return new WP_Error('jz_count', 'В выпуске должно быть от 4 до 7 новостей.', ['status'=>400]); }
        $seen = []; $ru=0; $world=0; $clean=[];
        foreach ($doc['articles'] as $i => $a) {
            if (!is_array($a)) { return new WP_Error('jz_article','Ошибочная структура заметки.', ['status'=>400]); }
            foreach (['title','summary','body','social','date','region','sources'] as $field) {
                if (!isset($a[$field]) || !$a[$field]) { return new WP_Error('jz_field','В новости '.($i+1).' отсутствует '.$field.'.',['status'=>400]); }
            }
            $ad = self::safe_text($a['date'],20);
            $adt=DateTimeImmutable::createFromFormat('!Y-m-d',$ad,new DateTimeZone('UTC'));
            if (!$adt || $adt->format('Y-m-d') !== $ad) { return new WP_Error('jz_article_date','Неверная дата новости.',['status'=>400]); }
            if (!in_array($a['region'], ['Россия', 'Мир'], true)) { return new WP_Error('jz_region','Регион: Россия или Мир.',['status'=>400]); }
            if ($a['region']==='Россия') $ru++; else $world++;
            $srcs=[];
            if (!is_array($a['sources']) || count($a['sources'])<1 || count($a['sources'])>6) { return new WP_Error('jz_sources','Нужен хотя бы один источник.',['status'=>400]); }
            foreach ($a['sources'] as $src) {
                if (!is_array($src) || empty($src['url']) || empty($src['name'])) { return new WP_Error('jz_source','Неполный источник.',['status'=>400]); }
                $url = esc_url_raw($src['url'], ['https']);
                if (!$url || wp_parse_url($url, PHP_URL_SCHEME) !== 'https' || !wp_parse_url($url, PHP_URL_HOST)) {
                    return new WP_Error('jz_https','Источники должны иметь корректные HTTPS-ссылки.',['status'=>400]);
                }
                if (isset($seen[$url])) { return new WP_Error('jz_duplicate','Один и тот же URL использован в нескольких новостях: '.$url,['status'=>400]); }
                $seen[$url]=true;
                $srcs[]=['name'=>self::safe_text($src['name'],150), 'url'=>$url, 'published'=>self::safe_text($src['published']??'',20), 'type'=>self::safe_text($src['type']??'',80)];
            }
            $title=self::safe_text($a['title'],230);
            $summary=self::safe_text($a['summary'],1300);
            $body=self::safe_text($a['body'],8000);
            $social=self::safe_text($a['social'],500);
            if (strlen($title)<8 || strlen($summary)<30 || strlen($body)<80 || strlen($social)<20) {
                return new WP_Error('jz_minlength','Неполный текст новости '.($i+1),['status'=>400]);
            }
            $clean[]=['id'=>sanitize_key($a['id']??('item-'.$i)),'region'=>$a['region'],
            'section'=>self::safe_text($a['section']??'Новости',100), 'date'=>$ad, 'title'=>$title,
            'summary'=>$summary, 'body'=>$body, 'social'=>$social, 'sources'=>$srcs,
            'verified'=>!empty($a['verified']), 'related'=>!empty($a['related']), 'importance'=>max(0,min(10,absint($a['importance']??0)))];
        }
        if (!(($ru >= 2 && $ru <= 3 && $world >= 3 && $world <= 4) || ($n===4 && $ru===2 && $world===2))) { return new WP_Error('jz_mix','Обычно нужно 2–3 российских и 3–4 мировых новости; для короткого номера из 4 допустимо 2+2.',['status'=>400]); }
        $ids=array_column($clean,'id');
        if(count($ids)!==count(array_unique($ids))) { return new WP_Error('jz_duplicate_id','Повторяются ID новостей.',['status'=>400]); }
        $social_ids=$doc['social']['article_ids']??array_slice($ids,0,5);
        if(!is_array($social_ids) || count($social_ids)!==5 || count(array_unique($social_ids))!==5 || array_diff($social_ids,$ids)) {
            return new WP_Error('jz_social','Нужно 5 неповторяющихся ID для соцсетей.',['status'=>400]);
        }
        $status=self::safe_text($doc['issue']['status']??'draft',20);
        // Publishing needs an editorial decision. A flag in the JSON alone does not bypass source hygiene checks.
        if ($status==='approved') {
            if ($date > wp_date('Y-m-d')) return new WP_Error('jz_future','Нельзя сразу публиковать выпуск с будущей датой.',['status'=>400]);
            foreach($clean as $a) if(!$a['verified']) return new WP_Error('jz_unverified','В одобренном выпуске есть неподтверждённый материал.',['status'=>400]);
        }
        return ['schema_version'=>'1.0','publication'=>'ДЖАЗЕТА','publisher'=>'Клуб Алексея Козлова',
        'issue'=>['number'=>absint($doc['issue']['number']??0),'date'=>$date,'timezone'=>'Europe/Moscow',
           'status'=>in_array($status,['approved','draft','demo'],true)?$status:'draft',
           'edition_label'=>self::safe_text($doc['issue']['edition_label']??'',180),
           'editor_note'=>self::safe_text($doc['issue']['editor_note']??'',1500)],
        'articles'=>$clean,'social'=>['count'=>5,'article_ids'=>array_values($social_ids)]];
    }

    public static function import($raw) {
        if (!current_user_can('edit_others_posts')) return new WP_Error('jz_forbidden','Недостаточно прав.',['status'=>403]);
        if(strlen($raw)>150000) return new WP_Error('jz_big','JSON больше 150 KB.',['status'=>413]);
        $doc=json_decode($raw,true,128);
        if(json_last_error()!==JSON_ERROR_NONE) return new WP_Error('jz_invalid_json','JSON: '.json_last_error_msg(),['status'=>400]);
        $clean=self::validate($doc);
        if(is_wp_error($clean))return $clean;
        $date=$clean['issue']['date'];
        $status=($clean['issue']['status']==='approved')?'publish':'draft';
        $existing=get_posts(['post_type'=>self::CPT,'name'=>'jz-'.$date,'post_status'=>'any','numberposts'=>1,'fields'=>'ids']);
        $fields=['post_type'=>self::CPT,'post_title'=>'Джазета — '.date_i18n('d.m.Y',strtotime($date)),
          'post_name'=>'jz-'.$date,'post_status'=>$status,'post_content'=>''];
        if($existing) $fields['ID']=(int)$existing[0];
        $id=wp_insert_post(wp_slash($fields), true);
        if(is_wp_error($id))return $id;
        update_post_meta($id,self::META,wp_json_encode($clean,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        update_post_meta($id,'_jz_issue_date',$date);
        return ['post_id'=>(int)$id,'post_status'=>$status,'date'=>$date];
    }

    public static function import_screen() {
        if(!current_user_can('edit_others_posts'))wp_die('Недостаточно прав.');
        echo '<div class="wrap"><h1>ДЖАЗЕТА — импорт выпуска</h1><p>Вставьте JSON подготовленного выпуска. Статус <code>demo</code> или <code>draft</code> оставит его неопубликованным. Для публикации нужен <code>approved</code> и проверенные материалы. Рекомендуем сначала проверить выпуск на тестовом сайте.</p>';
        if(isset($_POST['jz_import']) && check_admin_referer('jz-import-action')) {
            $raw=isset($_POST['jz_json']) && is_string($_POST['jz_json'])?wp_unslash($_POST['jz_json']):'';
            $result=self::import($raw);
            echo is_wp_error($result)?'<div class="notice notice-error"><p>'.esc_html($result->get_error_message()).'</p></div>':'<div class="notice notice-success"><p>Выпуск '.esc_html($result['date']).' импортирован, статус: '.esc_html($result['post_status']).'.</p></div>';
        }
        echo '<form method="post">';wp_nonce_field('jz-import-action');
        echo '<textarea name="jz_json" rows="20" spellcheck="false" style="font-family:monospace;width:100%;max-width:1000px" placeholder="{ ... }"></textarea><p><button class="button button-primary" name="jz_import" value="1">Импортировать / обновить</button></p></form><p>Для отображения добавьте на отдельную страницу шорткод: <code>[kozlov_jazzeta]</code>. Для внешнего импорта доступен защищённый WordPress REST API.</p></div>';
    }

    public static function rest_routes() {
        register_rest_route(self::REST,'/import',['methods'=>'POST','callback'=>function($req) {
            $raw=$req->get_body();$result=self::import($raw);
            return is_wp_error($result)?$result:rest_ensure_response($result);
        },'permission_callback'=>function(){return current_user_can('edit_others_posts');}]);
        register_rest_route(self::REST,'/latest',['methods'=>'GET','callback'=>function(){
            $issue=self::get_latest(); if(!$issue)return new WP_Error('jz_empty','Нет опубликованных выпусков.',['status'=>404]);
            return rest_ensure_response($issue);
        },'permission_callback'=>'__return_true']);
    }

    private static function get_latest($date='') {
        $args=['post_type'=>self::CPT,'post_status'=>'publish','posts_per_page'=>1,'orderby'=>'date','order'=>'DESC'];
        if($date){$args['name']='jz-'.$date;}
        else {$args['meta_key']='_jz_issue_date';$args['orderby']='meta_value';$args['order']='DESC';}
        $found=get_posts($args);
        if(!$found)return null;
        $doc=json_decode(get_post_meta($found[0]->ID,self::META,true),true);
        return is_array($doc)?$doc:null;
    }

    private static function render_issue($doc) {
        $a=$doc['articles'];usort($a,function($x,$y){return $y['importance']<=>$x['importance'];});
        $lead=array_shift($a);
        ob_start();
        echo '<div class="jz-newspaper"><div class="jz-top">Ежедневная газета о джазе и людях, которые его создают <span>Клуб Алексея Козлова · Москва</span></div>';
        echo '<div class="jz-mast">ДЖАЗЕТА</div><div class="jz-sub">МУЗЫКАЛЬНЫЕ НОВОСТИ И ОБСТОЯТЕЛЬСТВА ВРЕМЕНИ</div>';
        echo '<div class="jz-edition"><span>№ '.esc_html(str_pad((string)$doc['issue']['number'],3,'0',STR_PAD_LEFT)).'</span><span>'.esc_html(date_i18n('d.m.Y',strtotime($doc['issue']['date']))).'</span></div>';
        echo '<div class="jz-sections"><a href="#jz-news">Первая полоса</a><a href="#jz-archive">Подшивка</a><a href="#jz-social">Джаз за 60 секунд</a></div>';
        echo '<div class="jz-banner">В номере: '.count($doc['articles']).' новостей · Россия и мир</div><section id="jz-news">';
        echo '<article class="jz-lead"><div><small>'.esc_html($lead['region']).' / '.esc_html($lead['section']).'</small><h2>'.esc_html($lead['title']).'</h2><p>'.esc_html($lead['summary']).'</p><details><summary>Читать материал</summary><div class="jz-full"><p>'.esc_html($lead['body']).'</p>'.self::source_links($lead).'</div></details></div><div class="jz-vinyl"><div class="jz-vinyl-disc"><span>JAZZ</span></div><em>THE MUSIC NEVER STOPS</em></div></article>';
        echo '<div class="jz-grid">';foreach($a as $story) {
            echo '<article class="jz-story"><small>'.esc_html($story['region']).' / '.esc_html($story['section']).'</small><h3>'.esc_html($story['title']).'</h3><p>'.esc_html($story['summary']).'</p><details><summary>Подробнее и источники</summary><div class="jz-full"><p>'.esc_html($story['body']).'</p>'.self::source_links($story).'</div></details></article>';
        } echo '</div></section>';
        echo '<section id="jz-social" class="jz-block"><h2>Джаз за 60 секунд</h2><p>Пять новостей — по одному предложению.</p><div class="jz-social">';
        foreach($doc['social']['article_ids'] as $index=>$aid){foreach($doc['articles'] as $story)if($story['id']===$aid){echo '<div class="jz-social-card"><b>ДЖАЗЕТА / '.esc_html(str_pad((string)($index+1),2,'0',STR_PAD_LEFT)).'</b><p>'.esc_html($story['social']).'</p><small>'.esc_html($story['region']).'</small></div>';break;}}
        echo '</div></section><section id="jz-archive" class="jz-block"><h2>Подшивка номеров</h2><div class="jz-archive-list">';
        $archives=get_posts(['post_type'=>self::CPT,'post_status'=>'publish','posts_per_page'=>-1,'meta_key'=>'_jz_issue_date','orderby'=>'meta_value','order'=>'DESC']);
        foreach($archives as $post){$item=json_decode(get_post_meta($post->ID,self::META,true),true);if(!is_array($item))continue;$d=$item['issue']['date'];$url=add_query_arg('jz_issue',$d,get_permalink());echo '<a href="'.esc_url($url).'">№ '.esc_html(str_pad((string)$item['issue']['number'],3,'0',STR_PAD_LEFT)).' — '.esc_html(date_i18n('d.m.Y',strtotime($d))).' ↗</a>';}
        echo '</div></section><div class="jz-foot">Издание Клуба Алексея Козлова · Материалы подготовлены по указанным источникам</div></div>';
        return ob_get_clean();
    }

    private static function source_links($story) {
        $out='<div class="jz-sources"><strong>Источники:</strong> ';
        foreach($story['sources'] as $source){$out.='<a target="_blank" rel="nofollow noopener noreferrer" href="'.esc_url($source['url']).'">'.esc_html($source['name']).' ('.esc_html($source['published']).') ↗</a> ';}
        if(!empty($story['related']))$out.='<i>Связанный с издателем релиз</i>';
        return $out.'</div>';
    }

    public static function shortcode() {
        $date=isset($_GET['jz_issue']) && is_string($_GET['jz_issue'])?sanitize_text_field(wp_unslash($_GET['jz_issue'])):'';
        if($date && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$date))$date='';
        $doc=self::get_latest($date);
        if(!$doc)return '<p>Ни одного опубликованного выпуска пока нет.</p>';
        wp_enqueue_style('kozlov-jazzeta',plugins_url('assets/jazzeta.css',__FILE__),[], '0.1.0');
        return self::render_issue($doc);
    }
}
Kozlov_Jazzeta::init();
