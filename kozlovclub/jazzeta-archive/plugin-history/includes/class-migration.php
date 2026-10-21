<?php
namespace Kozlov\Standard;
if (!defined('ABSPATH')) { exit; }
final class Migration {
    public static function screen() {
        if (!current_user_can('manage_options')) { wp_die('Нет прав.'); }
        $page=isset($_GET['paged'])?max(1,absint($_GET['paged'])):1;
        $q=new \WP_Query(['post_type'=>'jz_issue','post_status'=>['publish','draft','pending','private','future'],'posts_per_page'=>20,'paged'=>$page,'orderby'=>'ID','order'=>'DESC']);
        echo '<div class="wrap es-admin"><h1>Перенос выпусков 0.2.0</h1><p>Только копирование в новые непроверенные черновики. Старые записи, номера и метаданные не изменяются. Сохраните резервную копию перед проверкой. Отсутствующие данные проверки нужно заполнить заново.</p>';
        foreach ($q->posts as $p) { Admin::form('migrate'); echo '<strong>'.esc_html($p->post_title).'</strong> <input type="hidden" name="legacy_id" value="'.(int)$p->ID.'"> <button class="button">Перенести в черновик</button></form>'; }
        if (!$q->posts) { echo '<p>Старые выпуски не найдены.</p>'; }
        Admin::pagination($page,$q->max_num_pages,admin_url('admin.php?page=es-migrate')); echo '</div>';
    }
    public static function one($legacy_id) {
        if (!current_user_can('manage_options') || !current_user_can('es_import')) { return Validation::error('Нет прав.',403); }
        $post=get_post($legacy_id); if (!$post || $post->post_type!=='jz_issue') { return Validation::error('Старый выпуск не найден.',404); }
        $raw=get_post_meta($legacy_id,'_jz_issue_json',true);
        if (!is_string($raw)||strlen($raw)>Validation::MAX_BYTES) { return Validation::error('Некорректный размер старого выпуска.'); }
        $old=json_decode($raw,true,32);
        if (!is_array($old) || !Validation::date($old['issue']['date']??null) || !is_array($old['articles']??null)) { return Validation::error('Неверные данные 0.2.0.'); }
        $d=Repository::empty_doc($old['issue']['date']); $d['import_id']='legacy-0.2.0-'.$legacy_id;
        $d['editorial']['source_scan_window_start']=$d['editorial']['source_scan_window_end']=$old['issue']['date'].'T00:00:00+03:00';
        $d['editorial']['blocking_issues']=['Перенесено из 0.2.0: повторно проверить все факты и источники.'];
        $d['editorial']['generation_notes']=['Исходная запись #'.$legacy_id.'; прежний номер '.($old['issue']['number']??'не указан').'. Автоматический номер будет присвоен новой системой.'];
        foreach ($old['articles'] as $pos=>$item) {
            if (!is_array($item)) { return Validation::error('Повреждён старый материал.'); }
            $a=Repository::blank_article(); $a['id']='legacy-'.$legacy_id.'-'.($pos+1); $a['event_id']=$a['story_group_id']=$a['id'];
            $a['headline']=$item['title']??'Материал из старого выпуска'; $a['lead']=$item['summary']??''; $a['summary']=$item['social']??$a['lead'];
            $a['body_paragraphs']=preg_split('/\R\s*\R/',(string)($item['body']??'')); $a['region']=($item['region']??'')==='Россия'?'russia':'world'; $a['placement']=$pos===0?'lead':'regular'; $a['sources']=[];
            foreach ($item['sources']??[] as $idx=>$s) { $date=$s['published']??null; $a['sources'][]=['id'=>'s'.($idx+1),'title'=>$s['name']??'Источник','publisher'=>$s['name']??'Источник','url'=>$s['url']??'','published_at'=>Validation::date($date)?$date:null,'type'=>'other','verification_checked_at'=>null,'dependency_group'=>'legacy-source-'.($idx+1)]; }
            $a['claims']=[['id'=>'f1','statement'=>'Перенесённый материал требует разметки существенных фактов.','verification'=>'unverified','source_ids'=>array_column($a['sources'],'id'),'editorial_note'=>'Старый флаг verified не переносится как человеческое подтверждение.']];
            if (!empty($item['related'])) { $a['conflict_disclosure']='Материал связан с издателем. Уточните характер связи перед публикацией.'; }
            $d['articles'][]=$a;
            if ($pos<5) { $d['social'][]=['article_id'=>$a['id'],'telegram_sentence'=>$a['summary'],'instagram_sentence'=>$a['summary'],'visual_format'=>'1080x1350']; }
        }
        $result=Repository::import(DB::json($d)); return is_wp_error($result)?$result:$result['id'];
    }
}
