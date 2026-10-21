<?php
namespace Kozlov\Standard;
if (!defined('ABSPATH')) { exit; }
final class Repository {
    public static function issue($id,$lock=false) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::table('issues').' WHERE id=%d'.($lock?' FOR UPDATE':''),$id),ARRAY_A);
    }
    public static function rows($id,$active=true) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare('SELECT * FROM '.DB::table('articles').' WHERE issue_id=%d'.($active?' AND active=1':'').' ORDER BY position,id',$id),ARRAY_A);
    }
    public static function doc($issue) {
        $d=json_decode($issue['draft_json'],true);
        $d['articles']=array_map(static function($r){return json_decode($r['draft_json'],true);},self::rows($issue['id']));
        return $d;
    }
    public static function hash($article) { return hash('sha256',DB::json($article)); }
    private static function begin() {
        global $wpdb; DB::begin();
        // The row is created on activation. INSERT IGNORE here would take a shared
        // duplicate-key lock: two writers then upgrading to FOR UPDATE can deadlock.
        $lock=$wpdb->get_var('SELECT value FROM '.DB::table('state')." WHERE name='write' FOR UPDATE");
        if ($wpdb->last_error || $lock===null) { throw new \RuntimeException('Не удалось заблокировать запись. Проверьте таблицы или повторно активируйте плагин.',503); }
    }
    private static function fail($e) { DB::rollback(); return new \WP_Error('es_operation',$e->getMessage(),['status'=>$e->getCode()>=400?$e->getCode():500]); }
    private static function version($row,$version) {
        if (!$row) { throw new \RuntimeException('Выпуск не найден.',404); }
        if ((int)$row['version']!==(int)$version) { throw new \RuntimeException('Выпуск изменён в другой вкладке. Скопируйте свои правки, обновите страницу и повторите.',409); }
    }
    private static function log($id,$action) {
        global $wpdb; $i=self::issue($id);
        $snapshot=['document'=>self::doc($i),'reviews'=>array_map(static function($r){return array_intersect_key($r,array_flip(['external_id','reviewed_by','reviewed_at','reviewed_hash','review_note','new_fact']));},self::rows($id))];
        DB::must($wpdb->insert(DB::table('revisions'),['issue_id'=>$id,'version'=>$i['version'],'actor_id'=>get_current_user_id(),'action'=>$action,'created_at'=>DB::now(),'snapshot'=>DB::json($snapshot)]));
    }
    private static function put_document($id,$d,$reset=true) {
        global $wpdb; $table=DB::table('articles');
        DB::must($wpdb->update($table,['active'=>0],['issue_id'=>$id]));
        foreach ($d['articles'] as $pos=>$a) {
            $old=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE issue_id=%d AND external_id=%s",$id,$a['id']),ARRAY_A);
            $fields=['position'=>$pos,'active'=>1,'story_group'=>$a['story_group_id'],'draft_json'=>DB::json($a)];
            if ($reset || !$old || self::hash($a)!==hash('sha256',$old['draft_json'])) { $fields+=['reviewed_hash'=>'','reviewed_by'=>null,'reviewed_at'=>null,'review_note'=>'','new_fact'=>'']; }
            if ($old) { DB::must($wpdb->update($table,$fields,['id'=>$old['id']])); }
            else {
                $fields+=['issue_id'=>$id,'external_id'=>$a['id'],'slug'=>$d['edition_date'].'-'.$a['id'],'review_note'=>'','new_fact'=>''];
                DB::must($wpdb->insert($table,$fields));
            }
        }
        Duplicates::sync($id);
        unset($d['articles']);
        DB::must($wpdb->update(DB::table('issues'),['draft_json'=>DB::json($d),'updated_at'=>DB::now()],['id'=>$id]));
    }
    public static function import($raw,$expected=null) {
        if (!current_user_can('es_import')) { return Validation::error('Нет права импорта.',403); }
        $d=Validation::parse($raw); if (is_wp_error($d)) { return $d; }
        global $wpdb; $t=DB::table('issues'); $hash=hash('sha256',DB::json($d));
        try {
            self::begin();
            $imports=DB::table('imports');
            $request=isset($d['import_id'])?hash('sha256',$d['import_id']):null;
            $prior=$wpdb->get_row($wpdb->prepare("SELECT * FROM $imports WHERE payload_hash=%s".($request?" OR request_key=%s":''),...($request?[$hash,$request]:[$hash])),ARRAY_A);
            if ($prior) {
                if (!hash_equals($prior['payload_hash'],$hash)) { throw new \RuntimeException('import_id уже использован с другим содержимым.',409); }
                $existing=self::issue($prior['issue_id']);
                if ((int)$prior['actor_id']!==get_current_user_id()&&!current_user_can('es_edit')) { throw new \RuntimeException('Нет доступа к этому импорту.',403); }
                DB::commit(); return ['id'=>(int)$existing['id'],'version'=>(int)$existing['version'],'status'=>$existing['state'],'unchanged'=>true];
            }
            $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE edition_date=%s FOR UPDATE",$d['edition_date']),ARRAY_A);
            if ($row) {
                if ((int)$row['created_by']!==get_current_user_id() && !current_user_can('es_edit')) { throw new \RuntimeException('Нельзя обновлять чужой черновик.',403); }
                if ($row['public_json']!==null || $row['state']!=='draft') { throw new \RuntimeException('Импорт не меняет ранее опубликованные выпуски.',409); }
                if ((int)$row['human_touched']) { throw new \RuntimeException('Выпуск уже редактировался человеком. Автоматическая перезапись запрещена.',409); }
                if (hash_equals($row['import_hash'],$hash)) { DB::commit(); return ['id'=>(int)$row['id'],'version'=>(int)$row['version'],'status'=>'draft','unchanged'=>true]; }
                if ($expected===null) { throw new \RuntimeException('Для обновления нужен If-Match с текущей версией.',428); }
                self::version($row,$expected); $id=(int)$row['id'];
                DB::must($wpdb->update($t,['version'=>$row['version']+1,'import_hash'=>$hash],['id'=>$id]));
            } else {
                if ($expected!==null && (int)$expected!==0) { throw new \RuntimeException('Выпуска для указанной версии нет.',409); }
                DB::must($wpdb->insert($t,['edition_date'=>$d['edition_date'],'draft_json'=>'{}','import_hash'=>$hash,'created_by'=>get_current_user_id(),'created_at'=>DB::now(),'updated_at'=>DB::now()])); $id=(int)$wpdb->insert_id;
            }
            self::put_document($id,$d); DB::must($wpdb->insert($imports,['issue_id'=>$id,'actor_id'=>get_current_user_id(),'request_key'=>$request,'payload_hash'=>$hash,'created_at'=>DB::now()])); self::log($id,'import'); $row=self::issue($id); DB::commit();
            return ['id'=>$id,'version'=>(int)$row['version'],'status'=>'draft','unchanged'=>false];
        } catch (\Throwable $e) { return self::fail($e); }
    }
    public static function create($date) {
        if (!current_user_can('es_edit')) { return Validation::error('Нет прав.',403); }
        if (!Validation::date($date)) { return Validation::error('Неверная дата.'); }
        global $wpdb;
        try {
            self::begin();
            if ($wpdb->get_var($wpdb->prepare('SELECT id FROM '.DB::table('issues').' WHERE edition_date=%s',$date))) { throw new \RuntimeException('Выпуск на эту дату уже существует.',409); }
            $d=self::empty_doc($date);
            DB::must($wpdb->insert(DB::table('issues'),['edition_date'=>$date,'draft_json'=>DB::json($d),'human_touched'=>1,'created_by'=>get_current_user_id(),'created_at'=>DB::now(),'updated_at'=>DB::now()]));
            $id=(int)$wpdb->insert_id; self::log($id,'create'); DB::commit(); return $id;
        } catch (\Throwable $e) { return self::fail($e); }
    }
    public static function empty_doc($date) {
        return ['schema_version'=>'1.1','edition_date'=>$date,'timezone'=>'Europe/Moscow','edition_number'=>null,'publication'=>['status'=>'draft'],'articles'=>[],'social'=>[],'editorial'=>['ready_for_human_review'=>false,'blocking_issues'=>[],'source_scan_window_start'=>gmdate('c'),'source_scan_window_end'=>gmdate('c'),'generation_notes'=>[]]];
    }
    public static function blank_article() {
        return ['id'=>'story-'.strtolower(wp_generate_password(8,false,false)),'region'=>'world','category'=>'jazz','placement'=>'regular','headline'=>'Новый материал','lead'=>'Краткое содержание материала.','body_paragraphs'=>['Текст материала.'],'summary'=>'Краткая выжимка материала.','event_date'=>null,'story_group_id'=>'story-'.strtolower(wp_generate_password(10,false,false)),'story_score'=>50,'event_id'=>'event-'.strtolower(wp_generate_password(10,false,false)),'previous_article_ids'=>[],'development_note'=>'','sources'=>[['id'=>'s1','title'=>'Первоисточник','publisher'=>'Название источника','url'=>'https://example.com/source','published_at'=>null,'type'=>'official','verification_checked_at'=>null,'dependency_group'=>'chain-1']],'claims'=>[['id'=>'f1','statement'=>'Существенный факт, требующий проверки.','verification'=>'unverified','source_ids'=>['s1'],'editorial_note'=>'']],'quotes'=>[],'conflict_disclosure'=>null,'editorial_notes'=>[],'media_rights'=>['image_url'=>null,'status'=>'none','credit'=>'']];
    }
    public static function save_article($id,$version,$a,$review,$note,$new_fact,$social=null,$delete=false) {
        if (!current_user_can('es_edit')) { return Validation::error('Нет прав.',403); }
        if (!is_string($note)||!is_string($new_fact)||strlen($note)>16000||strlen($new_fact)>16000) { return Validation::error('Слишком длинное замечание проверки.'); }
        global $wpdb;
        try {
            self::begin(); $row=self::issue($id,true); self::version($row,$version); $d=self::doc($row); $found=false;
            foreach ($d['articles'] as $k=>$old) { if ($old['id']===$a['id']) { $found=true; if ($delete) { unset($d['articles'][$k]); } else { $d['articles'][$k]=$a; } break; } }
            if (!$found && !$delete) { $d['articles'][]=$a; }
            $d['articles']=array_values($d['articles']);
            $d['social']=array_values(array_filter($d['social'],static function($s)use($a){return $s['article_id']!==$a['id'];}));
            if (!$delete && $social!==null) { $d['social'][]=$social; }
            $d=Validation::parse(DB::json($d)); if (is_wp_error($d)) { throw new \RuntimeException($d->get_error_message(),400); }
            if ($review && !$delete) {
                $clean=array_values(array_filter($d['articles'],static function($x)use($a){return $x['id']===$a['id'];}))[0];
                $errors=Validation::article_blockers($clean);
                if ($errors) { throw new \RuntimeException(implode(' ',$errors),400); }
                if (!trim($note)) { throw new \RuntimeException('Для подтверждения запишите результат проверки источников, фактов, атрибуции, цитат и прав.',400); }
            }
            self::put_document($id,$d,false);
            if (!$delete) {
                $fields=['reviewed_hash'=>'','reviewed_by'=>null,'reviewed_at'=>null,'review_note'=>sanitize_textarea_field($note),'new_fact'=>sanitize_textarea_field($new_fact)];
                if ($review) { $fields['reviewed_hash']=self::hash($clean); $fields['reviewed_by']=get_current_user_id(); $fields['reviewed_at']=DB::now(); }
                DB::must($wpdb->update(DB::table('articles'),$fields,['issue_id'=>$id,'external_id'=>$a['id']]));
            }
            DB::must($wpdb->update(DB::table('issues'),['version'=>$version+1,'human_touched'=>1],['id'=>$id]));
            self::log($id,$delete?'remove_article':($review?'review_article':'edit_article')); DB::commit(); return $id;
        } catch (\Throwable $e) { return self::fail($e); }
    }
    public static function save_issue($id,$version,$editorial,$order) {
        if (!current_user_can('es_edit')) { return Validation::error('Нет прав.',403); }
        global $wpdb;
        try {
            self::begin(); $row=self::issue($id,true); self::version($row,$version); $d=self::doc($row);
            $d['editorial']=$editorial;
            $ids=array_column($d['articles'],'id');
            if (count($order)!==count($ids) || count(array_unique($order))!==count($ids) || array_diff($ids,$order)) { throw new \RuntimeException('Порядок должен содержать каждый ID статьи ровно один раз.',400); }
            $by=array_column($d['articles'],null,'id'); $d['articles']=array_map(static function($key)use($by){return $by[$key];},$order);
            $d=Validation::parse(DB::json($d)); if (is_wp_error($d)) { throw new \RuntimeException($d->get_error_message(),400); }
            self::put_document($id,$d,false); DB::must($wpdb->update(DB::table('issues'),['version'=>$version+1,'human_touched'=>1],['id'=>$id]));
            self::log($id,'edit_issue'); DB::commit(); return $id;
        } catch (\Throwable $e) { return self::fail($e); }
    }
    public static function blockers($row) {
        $d=self::doc($row); $errors=[];
        $v=Validation::parse(DB::json($d)); if (is_wp_error($v)) { return [$v->get_error_message()]; }
        if (count($d['articles'])<4 || count($d['articles'])>7) { $errors[]='Полный номер содержит 4–7 материалов.'; }
        if ($d['edition_date']>wp_date('Y-m-d',null,new \DateTimeZone('Europe/Moscow'))) { $errors[]='Дата выпуска ещё не наступила в Москве.'; }
        if (!$d['editorial']['ready_for_human_review'] || $d['editorial']['blocking_issues']) { $errors[]='Редакционные блокеры не сняты или выпуск не готов к проверке.'; }
        if (count(array_filter($d['articles'],static function($a){return $a['placement']==='lead';}))!==1) { $errors[]='Нужен ровно один материал первой полосы.'; }
        foreach (self::rows($row['id']) as $r) {
            $a=json_decode($r['draft_json'],true); $errors=array_merge($errors,Validation::article_blockers($a));
            if (!$r['reviewed_by'] || !hash_equals($r['reviewed_hash'],self::hash($a))) { $errors[]='Не подтверждена текущая версия: '.$a['headline']; }
            if (self::duplicates($a['story_group_id'],$row['edition_date'],$row['id']) && !trim($r['new_fact'])) { $errors[]='Сюжет уже выходил за последние 14 дней: '.$a['headline'].'. Укажите новый факт.'; }
        }
        return array_values(array_unique(array_merge($errors,Duplicates::blockers($row))));
    }
    public static function duplicates($group,$date,$except) {
        global $wpdb; $a=DB::table('articles'); $i=DB::table('issues');
        $start=(new \DateTimeImmutable($date))->modify('-14 days')->format('Y-m-d');
        return $wpdb->get_results($wpdb->prepare("SELECT i.edition_date,a.slug FROM $a a JOIN $i i ON i.id=a.issue_id WHERE a.public_story_group=%s AND a.public_json IS NOT NULL AND i.state='published' AND i.id<>%d AND i.edition_date BETWEEN %s AND %s LIMIT 15",$group,$except,$start,$date),ARRAY_A);
    }
    /** Called only after the browser cookie/nonce/confirmation gate in Admin. No REST route calls this. */
    public static function publish_from_admin($id,$version) {
        if (!Admin::publication_authorized($id,$version)) { return Validation::error('Публикация разрешена только через подтверждённую административную форму.',403); }
        global $wpdb;
        try {
            self::begin(); $row=self::issue($id,true); self::version($row,$version);
            $errors=self::blockers($row); if ($errors) { throw new \RuntimeException(implode(' ',$errors),400); }
            $number=(int)$row['number']; $state=DB::table('state');
            if (!$number) { DB::must($wpdb->query("UPDATE $state SET value=value+1 WHERE name='number'")); $number=(int)$wpdb->get_var("SELECT value FROM $state WHERE name='number'"); }
            $d=self::doc($row); $public=['edition_date'=>$d['edition_date'],'number'=>$number,'articles'=>[],'social'=>$d['social']];
            foreach (self::rows($id,false) as $r) {
                if (!$r['active']) { DB::must($wpdb->update(DB::table('articles'),['public_json'=>null,'public_story_group'=>''],['id'=>$r['id']])); continue; }
                $a=json_decode($r['draft_json'],true); $p=Validation::public_article($a); $p['slug']=$r['slug'];
                $public['articles'][]=$p;
                DB::must($wpdb->update(DB::table('articles'),['public_json'=>DB::json($p),'public_story_group'=>$a['story_group_id']],['id'=>$r['id']]));
            }
            DB::must($wpdb->update(DB::table('issues'),['number'=>$number,'state'=>'published','version'=>$version+1,'public_version'=>$version+1,'public_json'=>DB::json($public),'published_at'=>$row['published_at']?:DB::now(),'public_updated_at'=>DB::now(),'updated_at'=>DB::now(),'approved_by'=>get_current_user_id(),'human_touched'=>1],['id'=>$id]));
            Duplicates::sync($id,'public'); self::log($id,'publish'); DB::invalidate(); DB::commit(); return $id;
        } catch (\Throwable $e) { return self::fail($e); }
    }
    public static function withdraw($id,$version) {
        if (!Admin::can_publish()) { return Validation::error('Только выбранный владелец может снять выпуск.',403); }
        global $wpdb;
        try {
            self::begin(); $row=self::issue($id,true); self::version($row,$version);
            DB::must($wpdb->update(DB::table('issues'),['state'=>'withdrawn','version'=>$version+1,'updated_at'=>DB::now(),'human_touched'=>1],['id'=>$id]));
            self::log($id,'withdraw'); DB::invalidate(); DB::commit(); return $id;
        } catch (\Throwable $e) { return self::fail($e); }
    }
    public static function restore($id,$version,$revision) {
        if (!current_user_can('es_edit')) { return Validation::error('Нет прав.',403); }
        global $wpdb;
        try {
            self::begin(); $row=self::issue($id,true); self::version($row,$version);
            $raw=$wpdb->get_var($wpdb->prepare('SELECT snapshot FROM '.DB::table('revisions').' WHERE id=%d AND issue_id=%d',$revision,$id));
            $snap=json_decode($raw??'',true); if (!$snap || !isset($snap['document'])) { throw new \RuntimeException('Версия не найдена.',404); }
            $d=Validation::parse(DB::json($snap['document'])); if (is_wp_error($d)) { throw new \RuntimeException($d->get_error_message(),400); }
            $d['editorial']['ready_for_human_review']=false;
            self::put_document($id,$d,true); DB::must($wpdb->update(DB::table('issues'),['version'=>$version+1,'human_touched'=>1],['id'=>$id]));
            self::log($id,'restore_draft'); DB::commit(); return $id;
        } catch (\Throwable $e) { return self::fail($e); }
    }
    public static function listing($page=1,$public=false,$date='') {
        global $wpdb; $page=max(1,(int)$page); $size=$public?12:20;
        $where=$public?" WHERE state='published'":" WHERE 1=1";
        if ($date) { $where.=$wpdb->prepare(' AND edition_date=%s',$date); }
        $key='es_archive_'.DB::catalog().'_'.$page; if ($public && !$date) { $cached=get_transient($key); if (is_array($cached)) { return $cached; } }
        $t=DB::table('issues');
        $total=(int)$wpdb->get_var("SELECT COUNT(*) FROM $t$where");
        $rows=$page>max(1,(int)ceil($total/$size))?[]:$wpdb->get_results($wpdb->prepare("SELECT id,edition_date,number,state,version,public_version,public_updated_at FROM $t$where ORDER BY edition_date DESC,id DESC LIMIT %d OFFSET %d",$size,($page-1)*$size),ARRAY_A);
        $result=['items'=>$rows,'pages'=>(int)ceil($total/$size),'total'=>$total];
        if ($public&&!$date && $page<=max(1,$result['pages'])) { set_transient($key,$result,300); }
        return $result;
    }
}
