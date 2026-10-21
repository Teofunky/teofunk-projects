<?php
namespace Kozlov\Standard;
if (!defined('ABSPATH')) { exit; }
final class Admin {
    private static $permit=null;
    private static $import_permit=null;
    private static $import_candidate=null;
    public static function can_publish() { return Runtime::owner(); }
    public static function init() {
        add_action('admin_menu',[self::class,'menu']);
        add_action('admin_enqueue_scripts',[self::class,'assets']);
        add_action('admin_post_es_action',Runtime::guard([self::class,'handle']));
    }
    public static function menu() {
        add_menu_page(Settings::name(),Settings::name(),'es_edit','es-newsroom',[self::class,'screen'],'dashicons-media-document',29);
        add_submenu_page('es-newsroom','Источники','Источники','es_edit','es-sources',[self::class,'sources']);
        $import_hook=add_submenu_page('es-newsroom','Импорт JSON','Импорт JSON','es_import','es-import',[self::class,'import_screen']);
        add_action('load-'.$import_hook,Runtime::guard([self::class,'import_load']));
        add_submenu_page('es-newsroom','Перенос 0.2.0','Перенос 0.2.0','manage_options','es-migrate',[Migration::class,'screen']);
        if (current_user_can('es_import')&&!current_user_can('es_edit')) { $import_hook=add_menu_page('Импорт газеты','Импорт газеты','es_import','es-import',[self::class,'import_screen'],'dashicons-upload'); add_action('load-'.$import_hook,Runtime::guard([self::class,'import_load'])); }
    }
    public static function assets($hook) {
        $page=isset($_GET['page'])&&is_string($_GET['page'])?sanitize_key(wp_unslash($_GET['page'])):'';
        if (!in_array($page,['es-newsroom','es-sources','es-import','es-migrate','es-appearance','es-access'],true)) { return; }
        if ($page==='es-appearance'&&current_user_can('manage_options')) { wp_enqueue_media(); }
        wp_enqueue_style('es-admin',plugins_url('assets/admin.css',ES_FILE),[],ES_VERSION);
        wp_enqueue_script('es-admin',plugins_url('assets/admin.js',ES_FILE),[],ES_VERSION,true);
    }
    public static function url($id=0,$article='') { return add_query_arg(array_filter(['page'=>'es-newsroom','issue'=>(int)$id,'article'=>$article]),admin_url('admin.php')); }
    public static function publication_authorized($id,$version) {
        return self::$permit===[(int)$id,(int)$version] && self::can_publish();
    }
    public static function manual_import_authorized($id,$version,$raw) {
        return self::$import_permit===[(int)$id,(int)$version,hash('sha256',$raw)] && Runtime::owner() && current_user_can('es_import');
    }
    private static function owner_browser() {
        return Runtime::owner() && current_user_can('es_import') && get_current_user_id()>0 && (int)wp_validate_auth_cookie('', 'logged_in')===get_current_user_id() && !(defined('REST_REQUEST')&&REST_REQUEST) && !(defined('DOING_CRON')&&DOING_CRON);
    }
    private static function value($key,$fallback='') { return isset($_POST[$key]) && is_scalar($_POST[$key])?wp_unslash((string)$_POST[$key]):$fallback; }
    private static function lines($v) { return array_values(array_filter(array_map('trim',preg_split('/\R/',(string)$v)),static function($x){return $x!=='';})); }
    public static function handle() {
        if (Runtime::mode()==='paused') { wp_die('Газета остановлена.','',['response'=>503]); }
        if (($_SERVER['REQUEST_METHOD']??'')!=='POST') { wp_die('Требуется POST.','',['response'=>405]); }
        $op=sanitize_key(self::value('operation')); $id=absint(self::value('issue_id')); $version=absint(self::value('version'));
        $cap=in_array($op,['publish','withdraw'],true)?'es_publish':($op==='import'?'es_import':($op==='migrate'?'manage_options':'es_edit'));
        if (!current_user_can($cap) || (in_array($op,['publish','withdraw'],true)&&!self::can_publish())) { wp_die('Нет прав.','',['response'=>403]); }
        check_admin_referer('es-'.$op.'-'.$id.'-'.$version);
        $result=Validation::error('Неизвестная операция.');
        if ($op==='manual_import') {
            if (!self::owner_browser()) { wp_die('Требуется авторизованная сессия выбранного владельца с правом импорта.','',['response'=>403]); }
            $raw=self::value('raw');
            if (self::value('confirm')!=='1' || !wp_verify_nonce(self::value('payload_nonce'),'es-import-payload-'.$id.'-'.$version.'-'.hash('sha256',$raw))) { wp_die('Подтвердите именно проверенный JSON. Вернитесь к проверке импорта.','',['response'=>403]); }
            self::$import_permit=[$id,$version,hash('sha256',$raw)];
            try { $result=Repository::manual_import_from_admin($id,$version,$raw); } finally { self::$import_permit=null; }
        }
        if ($op==='create') { $result=Repository::create(self::value('edition_date')); }
        if ($op==='import') { $v=self::value('expected'); $result=Repository::import(self::value('raw'),$v===''?null:absint($v)); if (!is_wp_error($result)) { $result=$result['id']; } }
        if ($op==='migrate') { $result=Migration::one(absint(self::value('legacy_id'))); }
        if ($op==='article') {
            $a=isset($_POST['article']) && is_array($_POST['article'])?wp_unslash($_POST['article']):[];
            // An explicit final marker detects PHP max_input_vars truncation before a write.
            if (self::value('form_complete')!=='yes') { wp_die('Форма была обрезана сервером. Изменения не сохранены. Увеличьте max_input_vars на тестовой копии.','',['response'=>400]); }
            try {
                foreach (['headline','lead','summary','id','region','category','placement','story_group_id'] as $k) { if (!isset($a[$k])||!is_string($a[$k])) { throw new \RuntimeException('Неверное поле '.$k); } }
                $a['body_paragraphs']=[self::value('body')];
                $a['previous_article_ids']=array_values(array_filter(array_map('trim',explode(',',self::value('previous_article_ids')))));
                $a['development_note']=self::value('new_fact');
                $a['event_date']=empty($a['event_date'])?null:$a['event_date'];
                $a['story_score']=isset($a['story_score']) && is_numeric($a['story_score'])?(float)$a['story_score']:0;
                $a['conflict_disclosure']=empty($a['conflict_disclosure'])?null:$a['conflict_disclosure'];
                $a['editorial_notes']=self::lines(self::value('editorial_notes'));
                foreach (['sources','claims','quotes'] as $k) { $a[$k]=array_values($a[$k]??[]); }
                foreach ($a['sources'] as &$s) { foreach (['published_at','verification_checked_at','publication_id','content_hash'] as $k) { $s[$k]=empty($s[$k])?null:$s[$k]; } } unset($s);
                foreach ($a['claims'] as &$c) { $c['source_ids']=array_values(array_filter(array_map('trim',explode(',',is_string($c['source_ids']??null)?$c['source_ids']:'')))); } unset($c);
                foreach ($a['quotes'] as &$q) { $q['verified']=isset($q['verified'])&&$q['verified']==='1'; } unset($q);
                $a['media_rights']['image_url']=empty($a['media_rights']['image_url'])?null:$a['media_rights']['image_url'];
                $social=null;
                if (self::value('social_enabled')==='1') { $social=['article_id'=>$a['id'],'telegram_sentence'=>self::value('telegram_sentence'),'instagram_sentence'=>self::value('instagram_sentence'),'visual_format'=>'1080x1350']; }
                $result=Repository::save_article($id,$version,$a,self::value('reviewed')==='1',self::value('review_note'),self::value('new_fact'),$social,self::value('remove')==='1');
            } catch (\Throwable $e) { $result=Validation::error('Неверная структура формы. Правки не сохранены.'); }
        }
        if ($op==='issue') {
            $row=Repository::issue($id);
            if ($row) {
                $ed=Repository::doc($row)['editorial'];
                $ed['ready_for_human_review']=self::value('ready')==='1'; $ed['blocking_issues']=self::lines(self::value('blocking_issues')); $ed['generation_notes']=self::lines(self::value('generation_notes'));
                $ed['source_scan_window_start']=self::value('scan_start'); $ed['source_scan_window_end']=self::value('scan_end');
                $order=array_values(array_filter(array_map('trim',explode(',',self::value('order')))));
                $result=Repository::save_issue($id,$version,$ed,$order);
            }
        }
        if ($op==='restore') { $result=Repository::restore($id,$version,absint(self::value('revision_id'))); }
        if ($op==='publish') {
            if (self::value('confirm')!=='1' || !current_user_can('es_edit') || wp_validate_auth_cookie('', 'logged_in')!==get_current_user_id() || (defined('REST_REQUEST')&&REST_REQUEST) || (defined('DOING_CRON')&&DOING_CRON)) { wp_die('Требуется ручное подтверждение в авторизованной сессии браузера.','',['response'=>403]); }
            self::$permit=[$id,$version];
            try { $result=Repository::publish_from_admin($id,$version); } finally { self::$permit=null; }
        }
        if ($op==='withdraw') {
            if (self::value('confirm')!=='1') { wp_die('Подтвердите снятие выпуска.','',['response'=>400]); }
            $result=Repository::withdraw($id,$version);
        }
        if (is_wp_error($result)) { wp_die(esc_html($result->get_error_message()).'<p>Вернитесь назад и сохраните свои правки перед обновлением страницы.</p>','Операция остановлена',['response'=>$result->get_error_data()['status']??400,'back_link'=>true]); }
        wp_safe_redirect(self::url((int)$result).'&saved=1'); exit;
    }
    public static function form($op,$id=0,$version=0,$target='') {
        echo '<form method="post" action="'.esc_url($target?:admin_url('admin-post.php')).'" class="es-form">';
        foreach (['action'=>'es_action','operation'=>$op,'issue_id'=>$id,'version'=>$version] as $k=>$v) { echo '<input type="hidden" name="'.esc_attr($k).'" value="'.esc_attr($v).'">'; }
        wp_nonce_field('es-'.$op.'-'.$id.'-'.$version);
    }
    public static function field($name,$label,$value='',$type='text',$options=[]) {
        $fid='es-'.md5($name);
        echo '<label class="es-field" for="'.esc_attr($fid).'"><span>'.esc_html($label).'</span>';
        if ($type==='textarea') { echo '<textarea id="'.esc_attr($fid).'" name="'.esc_attr($name).'" rows="3">'.esc_textarea($value).'</textarea>'; }
        elseif ($type==='select') { echo '<select id="'.esc_attr($fid).'" name="'.esc_attr($name).'">'; foreach ($options as $k=>$v) { echo '<option value="'.esc_attr($k).'"'.selected($value,(string)$k,false).'>'.esc_html($v).'</option>'; } echo '</select>'; }
        else { echo '<input id="'.esc_attr($fid).'" type="'.esc_attr($type).'" name="'.esc_attr($name).'" value="'.esc_attr($value).'">'; }
        echo '</label>';
    }
    private static function check($name,$label,$checked=false) { echo '<p><label><input type="checkbox" name="'.esc_attr($name).'" value="1"'.checked($checked,true,false).'> '.esc_html($label).'</label></p>'; }
    public static function screen() {
        if (!current_user_can('es_edit')) { wp_die('Нет прав.'); }
        $id=isset($_GET['issue'])?absint($_GET['issue']):0; $row=$id?Repository::issue($id):null;
        echo '<div class="wrap es-admin"><h1>'.esc_html(Settings::name()).' <small>'.esc_html(ES_VERSION).'</small></h1><p><a href="'.esc_url(self::url()).'">Редакционная очередь</a> · <a href="'.esc_url(Front::url()).'" target="_blank" rel="noopener">Открыть газету ↗</a></p>';
        if (isset($_GET['saved'])) { echo '<div class="notice notice-success"><p>Операция выполнена. Публичная версия меняется только при утверждении выпуска.</p></div>'; }
        if ($id&&!$row) { echo '<p>Выпуск не найден.</p></div>'; return; }
        if (!$row) { self::queue(); echo '</div>'; return; }
        $article=isset($_GET['article'])&&is_string($_GET['article'])?sanitize_key(wp_unslash($_GET['article'])):'';
        if ($article) { self::editor($row,$article); echo '</div>'; return; }
        $d=Repository::doc($row); $rows=Repository::rows($id); $errors=Repository::blockers($row);
        echo '<h2>Выпуск '.esc_html($row['edition_date']).' · '.esc_html($row['number']?'№ '.$row['number']:'номер при публикации').'</h2><p>Версия '.(int)$row['version'].' · '.esc_html($row['state']).' · публичная версия '.(int)$row['public_version'].'</p>';
        echo '<p><a class="button" target="_blank" rel="noopener" href="'.esc_url(Front::preview_url($id)).'">Предпросмотр газеты</a> <a class="button" href="'.esc_url(self::url($id,'new')).'">Добавить материал</a></p>';
        if (Runtime::owner()&&current_user_can('es_import')&&$row['state']==='draft'&&$row['public_json']===null&&!(int)$row['public_version']&&$row['published_at']===null&&$row['number']===null) {
            echo '<p><a class="button" href="'.esc_url(add_query_arg(['page'=>'es-import','issue'=>$id],admin_url('admin.php'))).'">Заменить черновик из JSON…</a></p>';
        }
        echo '<table class="widefat striped"><thead><tr><th>Порядок / ID</th><th>Материал</th><th>Проверка</th></tr></thead><tbody>';
        foreach ($rows as $r) { $a=json_decode($r['draft_json'],true); $reviewed=$r['reviewed_by']&&hash_equals($r['reviewed_hash'],Repository::hash($a)); echo '<tr><td>'.((int)$r['position']+1).' · '.esc_html($a['id']).'</td><td><a href="'.esc_url(self::url($id,$a['id'])).'">'.esc_html($a['headline']).'</a><br>'.esc_html(Validation::REGIONS[$a['region']].' / '.$a['placement']).'</td><td>'.($reviewed?'✓ Подтверждено человеком':'Требует проверки').'</td></tr>'; }
        echo '</tbody></table><h2>Сборка и редакционные блокеры</h2>';
        self::form('issue',$id,$row['version']);
        self::field('order','Порядок ID через запятую',implode(', ',array_column($d['articles'],'id')));
        self::field('blocking_issues','Блокеры — каждый с новой строки',implode("\n",$d['editorial']['blocking_issues']),'textarea');
        self::field('generation_notes','Приватные заметки выпуска',implode("\n",$d['editorial']['generation_notes']),'textarea');
        self::field('scan_start','Начало сбора (ISO 8601)',$d['editorial']['source_scan_window_start']); self::field('scan_end','Конец сбора (ISO 8601)',$d['editorial']['source_scan_window_end']);
        self::check('ready','Выпуск готов к итоговой проверке владельцем',$d['editorial']['ready_for_human_review']);
        echo '<button class="button button-primary">Сохранить сборку</button></form>';
        echo '<h2>Итоговый допуск</h2>';
        if ($errors) { echo '<ul class="es-blockers">'; foreach ($errors as $error) { echo '<li>'.esc_html($error).'</li>'; } echo '</ul>'; } else { echo '<p>Структурные проверки пройдены. Источники, текст и социальные выжимки требуют окончательного прочтения владельцем.</p>'; }
        if (self::can_publish()) {
            self::form('publish',$id,$row['version']); self::check('confirm','Я прочитал текущую версию, проверил факты, цитаты, права и соцверсии. Подтверждаю публикацию.');
            echo '<button class="button button-primary"'.($errors?' disabled':'').'>'.($row['public_json']?'Утвердить и опубликовать новую версию':'Опубликовать выпуск').'</button></form>';
            if ($row['state']==='published') { self::form('withdraw',$id,$row['version']); self::check('confirm','Снять весь выпуск и его материалы с публичного доступа'); echo '<button class="button">Снять с публикации</button></form>'; }
        }
        if (!self::can_publish()) { echo '<p>Публикация доступна только выбранному владельцу. Учётная запись настраивается в разделе «Доступ».</p>'; }
        self::history($row); echo '</div>';
    }
    private static function queue() {
        $page=isset($_GET['paged'])?max(1,absint($_GET['paged'])):1;
        $date=isset($_GET['date'])&&is_string($_GET['date'])?sanitize_text_field(wp_unslash($_GET['date'])):'';
        $data=Repository::listing($page,false,Validation::date($date)?$date:'');
        echo '<form method="get"><input type="hidden" name="page" value="es-newsroom">'; self::field('date','Найти выпуск по дате',$date,'date'); echo '<button class="button">Найти</button></form>';
        echo '<table class="widefat striped"><thead><tr><th>Дата</th><th>Номер</th><th>Состояние</th><th>Версия</th></tr></thead><tbody>';
        foreach ($data['items'] as $r) { echo '<tr><td><a href="'.esc_url(self::url($r['id'])).'">'.esc_html($r['edition_date']).'</a></td><td>'.esc_html($r['number']?:'—').'</td><td>'.esc_html($r['state']).'</td><td>'.(int)$r['version'].'</td></tr>'; }
        echo '</tbody></table>'; self::pagination($page,$data['pages'],self::url());
        echo '<h2>Новый выпуск</h2>'; self::form('create'); self::field('edition_date','Дата выпуска',wp_date('Y-m-d',null,new \DateTimeZone('Europe/Moscow')),'date'); echo '<button class="button button-primary">Создать пустой черновик</button></form>';
    }
    private static function editor($row,$key) {
        $id=$row['id']; $r=null; foreach (Repository::rows($id) as $candidate) { if ($candidate['external_id']===$key) { $r=$candidate; break; } }
        if (!$r && $key!=='new') { echo '<p>Материал не найден.</p>'; return; }
        $a=$r?json_decode($r['draft_json'],true):Repository::blank_article();
        echo '<h2>Редактор материала</h2><p><a href="'.esc_url(self::url($id)).'">← Вернуться к выпуску</a></p>';
        self::form('article',$id,$row['version']);
        echo '<input type="hidden" name="article[id]" value="'.esc_attr($a['id']).'"><p>ID: <code>'.esc_html($a['id']).'</code>. Меняется текст; адрес материала сохраняется.</p>';
        self::field('article[headline]','Заголовок',$a['headline']); self::field('article[lead]','Лид',$a['lead'],'textarea');
        echo '<h3>Текст статьи</h3>';
        wp_editor(Validation::body($a['body_paragraphs']),'es_article_body',['textarea_name'=>'body','media_buttons'=>false,'textarea_rows'=>16,'teeny'=>true,'quicktags'=>true,'tinymce'=>['toolbar1'=>'bold,italic,bullist,numlist,blockquote,link,unlink,undo,redo']]);
        echo '<p>Сохраняются абзацы, выделения, ссылки, списки и цитатные блоки. Сценарии, iframe и шорткоды не исполняются.</p><div class="es-grid">';
        self::field('article[region]','Регион',$a['region'],'select',Validation::REGIONS); self::field('article[category]','Рубрика',$a['category'],'select',Validation::CATEGORIES);
        self::field('article[placement]','Место на полосе',$a['placement'],'select',['lead'=>'Первая полоса','feature'=>'Крупный материал','regular'=>'Обычная заметка','brief'=>'Коротко']);
        self::field('article[story_score]','Значимость (0–100)',$a['story_score'],'number'); self::field('article[event_date]','Дата события / релиза',$a['event_date']??'','date'); self::field('article[event_id]','Единый ID события',$a['event_id']??$a['story_group_id']); self::field('article[story_group_id]','ID сюжета для поиска повторов',$a['story_group_id']); echo '</div>';
        self::field('article[summary]','Краткая выжимка',$a['summary'],'textarea');
        self::repeat('sources','Источники',$a['sources']);
        self::repeat('claims','Существенные утверждения и доказательства',$a['claims']);
        self::repeat('quotes','Проверенные прямые цитаты',$a['quotes']);
        self::field('article[conflict_disclosure]','Раскрытие связи с клубом / Jazzist — видно читателю',$a['conflict_disclosure']??'','textarea');
        echo '<h3>Иллюстрация</h3><p>Используйте HTTPS-URL существующего JPEG/PNG/WebP/AVIF из медиатеки этого сайта. Внешние изображения не скачиваются. Без фото используется редакционная иллюстрация.</p>';
        self::field('article[media_rights][image_url]','URL изображения',$a['media_rights']['image_url']??'','url');
        self::field('article[media_rights][status]','Права',$a['media_rights']['status'],'select',['none'=>'Нет изображения','owned'=>'Собственная работа','licensed'=>'Лицензия','verified_permission'=>'Подтверждённое разрешение']);
        self::field('article[media_rights][credit]','Автор / правообладатель',$a['media_rights']['credit']);
        self::field('editorial_notes','Приватные заметки статьи — по одной на строку',implode("\n",$a['editorial_notes']),'textarea');
        $social=null; foreach (Repository::doc($row)['social'] as $s) { if ($s['article_id']===$a['id']) { $social=$s; } }
        echo '<h3>Соцверсии</h3>'; self::check('social_enabled','Включить в подборку (до пяти материалов)',(bool)$social);
        self::field('telegram_sentence','Telegram — одно предложение',$social['telegram_sentence']??$a['summary'],'textarea'); self::field('instagram_sentence','Instagram — одно предложение',$social['instagram_sentence']??$a['summary'],'textarea');
        echo '<h3>Ручная проверка</h3><p>Проверьте, что все существенные утверждения текста и соцверсий перечислены выше. Для одного источника нужна явная атрибуция в тексте. Отметки JSON сами по себе не доказывают достоверность.</p>';
        self::field('review_note','Результат проверки: факты, источники, цитаты, раскрытия и права',$r['review_note']??'','textarea');
        self::field('new_fact','Если сюжет повторный: какой новый факт оправдывает повтор',$a['development_note']??'','textarea');
        self::field('previous_article_ids','Адресные ID предыдущих статей через запятую',implode(', ',$a['previous_article_ids']??[]));
        $matches=Duplicates::matches($a,$id,$r['id']??0);
        foreach ($matches as $match) { echo '<p class="es-blockers">Совпадение: <a href="'.esc_url(Front::url('material/'.$match['slug'])).'" target="_blank" rel="noopener">'.esc_html($match['slug']).'</a> · '.esc_html(implode(', ',array_map(static function($reason){return ['exact_text'=>'полное совпадение текста','event_id'=>'то же событие','headline'=>'одинаковый заголовок','source_identity'=>'тот же URL, ID или хеш публикации источника'][$reason]??$reason;},$match['reasons']))).'</p>'; }
        if ($r) { $dups=Repository::duplicates($a['story_group_id'],$row['edition_date'],$id); foreach ($dups as $dup) { echo '<p>Ранее: <a target="_blank" rel="noopener" href="'.esc_url(Front::url('material/'.$dup['slug'])).'">'.esc_html($dup['edition_date']).'</a></p>'; } }
        self::check('reviewed','Я проверил текущую статью, источники, атрибуцию, цитаты, права и социальные выжимки.');
        echo '<p><button class="button button-primary">Сохранить материал</button></p>';
        if ($r) { self::check('remove','Удалить материал из рабочей версии при сохранении. Публичная версия изменится после одобрения выпуска.'); }
        echo '<input type="hidden" name="form_complete" value="yes"></form>';
    }
    private static function repeat($kind,$title,$rows) {
        echo '<section class="es-repeater" data-kind="'.esc_attr($kind).'"><h3>'.esc_html($title).'</h3><div class="es-repeat-items">';
        foreach ($rows as $idx=>$data) { self::repeat_row($kind,$idx,$data); }
        echo '</div><button type="button" class="button es-add-row">Добавить строку</button><template>';
        self::repeat_row($kind,'__INDEX__',[]); echo '</template></section>';
    }
    private static function repeat_row($kind,$index,$d) {
        $n='article['.$kind.']['.$index.']'; echo '<fieldset class="es-repeat-row"><legend>'.esc_html($kind==='sources'?'Источник':($kind==='claims'?'Факт':'Цитата')).'</legend><div class="es-grid">';
        if ($kind==='sources') {
            foreach (['id'=>'ID (например s1)','title'=>'Заголовок публикации','publisher'=>'Издатель','url'=>'HTTPS-ссылка','published_at'=>'Дата публикации (ISO 8601 или YYYY-MM-DD)','verification_checked_at'=>'Когда проверен (ISO 8601 с часовым поясом)','publication_id'=>'ID публикации у источника (необязательно)','content_hash'=>'SHA-256 текста источника (необязательно)','dependency_group'=>'Независимая цепочка (одинаковая у перепечаток)'] as $k=>$label) { self::field($n.'['.$k.']',$label,$d[$k]??''); }
            self::field($n.'[type]','Тип источника',$d['type']??'official','select',['official'=>'Официальный первоисточник','independent_editorial'=>'Независимое СМИ','press_release'=>'Пресс-релиз','distributor'=>'Дистрибьютор','event_organizer'=>'Организатор','research_preprint'=>'Препринт','other'=>'Другой']);
        } elseif ($kind==='claims') {
            self::field($n.'[id]','ID факта',$d['id']??''); self::field($n.'[statement]','Утверждение',$d['statement']??'','textarea');
            self::field($n.'[verification]','Подтверждение',$d['verification']??'unverified','select',Validation::LEVELS);
            self::field($n.'[source_ids]','ID источников через запятую',implode(', ',$d['source_ids']??[])); self::field($n.'[editorial_note]','Доказательство / точная выдержка / атрибуция',$d['editorial_note']??'','textarea');
        } else {
            self::field($n.'[original]','Оригинал',$d['original']??'','textarea'); self::field($n.'[translation_ru]','Русский перевод',$d['translation_ru']??'','textarea'); self::field($n.'[source_id]','ID источника',$d['source_id']??'');
            self::check($n.'[verified]','Оригинал и перевод сверены',!empty($d['verified']));
        }
        echo '</div><button class="button es-remove-row" type="button">Убрать строку</button></fieldset>';
    }
    private static function history($row) {
        global $wpdb; $page=isset($_GET['history_page'])?max(1,absint($_GET['history_page'])):1; $t=DB::table('revisions');
        $records=$wpdb->get_results($wpdb->prepare("SELECT id,version,actor_id,action,created_at FROM $t WHERE issue_id=%d ORDER BY id DESC LIMIT 20 OFFSET %d",$row['id'],($page-1)*20),ARRAY_A);
        $count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE issue_id=%d",$row['id']));
        echo '<h2 id="history">Журнал версий (время UTC)</h2><table class="widefat striped"><thead><tr><th>Версия</th><th>Когда / кто</th><th>Действие</th><th>Восстановить</th></tr></thead><tbody>';
        foreach ($records as $rec) { $u=get_userdata($rec['actor_id']); echo '<tr><td>'.(int)$rec['version'].'</td><td>'.esc_html($rec['created_at'].' / '.($u?$u->display_name:'Пользователь #'.$rec['actor_id'])).'</td><td>'.esc_html(['before_manual_import'=>'Перед ручным импортом','manual_import'=>'Ручной импорт JSON'][$rec['action']]??$rec['action']).'</td><td>'; echo '<a href="'.esc_url(add_query_arg('revision',$rec['id'],self::url($row['id'])).'#history').'">Просмотреть</a>'; self::form('restore',$row['id'],$row['version']); echo '<input type="hidden" name="revision_id" value="'.(int)$rec['id'].'"><button class="button">В рабочий черновик</button></form></td></tr>'; }
        echo '</tbody></table>'; self::pagination($page,(int)ceil($count/20),self::url($row['id']),'history_page');
        $selected=isset($_GET['revision'])?absint($_GET['revision']):0;
        if ($selected) { $snap=$wpdb->get_var($wpdb->prepare("SELECT snapshot FROM $t WHERE issue_id=%d AND id=%d",$row['id'],$selected)); if ($snap) { echo '<h3>Снимок версии #'.(int)$selected.'</h3><textarea readonly rows="18" style="width:100%;font-family:monospace" aria-label="Снимок редакционной версии">'.esc_textarea(wp_json_encode(json_decode($snap,true),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)).'</textarea>'; } }
        echo '<p>Восстановление снимает ручные подтверждения. Опубликованный снимок сохраняется до нового одобрения.</p>';
    }
    public static function pagination($page,$pages,$url,$key='paged') {
        echo '<nav class="es-pagination" aria-label="Страницы">';
        if ($page>1) { echo '<a class="button" href="'.esc_url(add_query_arg($key,$page-1,$url)).'">← Назад</a> '; }
        echo esc_html($page.' / '.max(1,$pages));
        if ($page<$pages) { echo ' <a class="button" href="'.esc_url(add_query_arg($key,$page+1,$url)).'">Далее →</a>'; }
        echo '</nav>';
    }
    public static function import_screen() {
        if (!current_user_can('es_import')) { wp_die('Нет прав.'); }
        if (self::$import_candidate!==null) { self::import_confirmation(self::$import_candidate); return; }
        echo '<div class="wrap es-admin"><h1>Импорт редакционного JSON</h1><p>Схема editorial v1. Только черновики. До 256 КиБ, глубина до 32.</p>';
        $id=isset($_GET['issue'])?absint($_GET['issue']):0;
        if ($id) {
            if (!Runtime::owner()) { wp_die('Ручная замена доступна только выбранному владельцу.','',['response'=>403]); }
            $row=Repository::issue($id);
            if (!$row) { wp_die('Черновик не найден.','',['response'=>404]); }
            if ($row['state']!=='draft'||$row['public_json']!==null||(int)$row['public_version']||$row['published_at']!==null||$row['number']!==null) { echo '<p>Этот выпуск ранее публиковался или не является черновиком. Ручной импорт запрещён.</p></div>'; return; }
            echo '<h2>Ручная замена черновика '.esc_html($row['edition_date']).'</h2><p>ID '.(int)$id.' · версия '.(int)$row['version'].'. Все материалы, соцвыжимки и заметки выпуска будут заменены содержимым JSON. Ручные подтверждения проверки будут сняты. Перед заменой сохранится резервная редакция.</p>';
            self::form('import_preview',$id,$row['version'],add_query_arg(['page'=>'es-import','issue'=>$id],admin_url('admin.php'))); self::field('raw','JSON для замены черновика','','textarea'); echo '<button class="button button-primary">Проверить и показать изменения</button></form><p><a href="'.esc_url(self::url($id)).'">Отмена — вернуться к выпуску</a></p></div>'; return;
        }
        echo '<p>Обычный и автоматический импорт не перезаписывают ручные правки. Чтобы заменить существующий черновик, владелец открывает его и выбирает «Заменить черновик из JSON…». Опубликованные и ранее опубликованные выпуски заменить нельзя.</p>';
        self::form('import'); self::field('raw','JSON','', 'textarea'); self::field('expected','Текущая версия для обновления (If-Match); пусто для нового выпуска','', 'number'); echo '<button class="button button-primary">Проверить и импортировать</button></form>';
        echo '<p>REST: <code>POST /wp-json/ezhednevny-standard/v1/import</code>. Для интеграции создайте отдельного пользователя с ролью «Газета — импортёр» и Application Password. Эта роль не может редактировать сайт или публиковать газету.</p></div>';
    }
    public static function import_load() {
        $id=isset($_GET['issue'])?absint($_GET['issue']):0;
        if (!$id) { return; }
        if (!self::owner_browser()) { wp_die('Ручная замена доступна только выбранному владельцу в авторизованном браузере.','',['response'=>403]); }
        if (($_SERVER['REQUEST_METHOD']??'')!=='POST') { return; }
        if (Runtime::mode()==='paused') { wp_die('Газета остановлена.','',['response'=>503]); }
        $version=absint(self::value('version'));
        if (self::value('operation')!=='import_preview'||absint(self::value('issue_id'))!==$id) { wp_die('Неверная форма.','',['response'=>400]); }
        check_admin_referer('es-import_preview-'.$id.'-'.$version);
        $candidate=Repository::manual_import_candidate($id,$version,self::value('raw'));
        if (is_wp_error($candidate)) { wp_die(esc_html($candidate->get_error_message()),'Импорт остановлен',['response'=>$candidate->get_error_data()['status']??400,'back_link'=>true]); }
        self::$import_candidate=$candidate;
    }
    private static function import_confirmation($candidate) {
        $row=$candidate['issue']; $d=$candidate['document']; $raw=DB::json($d); $id=(int)$row['id']; $version=(int)$row['version'];
        $old=Repository::doc($row); $before=array_column($old['articles'],null,'id'); $after=array_column($d['articles'],null,'id');
        echo '<div class="wrap es-admin"><h1>Подтверждение ручного импорта</h1><p><strong>Черновик '.esc_html($row['edition_date']).' · ID '.$id.' · версия '.$version.'</strong></p><p>Было материалов: '.count($before).'. После замены: '.count($after).'. Публикации не будет. Все подтверждения проверки и готовность выпуска будут сняты; автоматическая перезапись останется запрещена.</p>';
        echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Сейчас</th><th>После импорта</th><th>Изменение</th></tr></thead><tbody>';
        foreach (array_unique(array_merge(array_keys($before),array_keys($after))) as $key) {
            $change=!isset($after[$key])?'Будет убран':(!isset($before[$key])?'Будет добавлен':(self::same_article($before[$key],$after[$key])?'Текст без изменений; проверка сбросится':'Будет заменён'));
            echo '<tr><td>'.esc_html($key).'</td><td>'.esc_html($before[$key]['headline']??'—').'</td><td>'.esc_html($after[$key]['headline']??'—').'</td><td>'.esc_html($change).'</td></tr>';
        }
        echo '</tbody></table><p>Замена затрагивает весь выпуск, включая соцвыжимки, источники и редакционные заметки. Текущая версия вместе с результатами проверки будет сохранена в журнале как «Перед ручным импортом». Её можно восстановить в рабочий черновик.</p><details><summary>Полный JSON, который будет записан</summary><pre style="white-space:pre-wrap;overflow-wrap:anywhere">'.esc_html(wp_json_encode($d,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)).'</pre></details>';
        self::form('manual_import',$id,$version);
        echo '<input type="hidden" name="payload_nonce" value="'.esc_attr(wp_create_nonce('es-import-payload-'.$id.'-'.$version.'-'.hash('sha256',$raw))).'"><textarea hidden name="raw">'.esc_textarea($raw).'</textarea>';
        self::check('confirm','Я проверил JSON и подтверждаю замену всего этого черновика, включая прежние ручные правки, с сохранением резервной редакции.');
        echo '<button class="button button-primary">Подтвердить замену черновика</button> <a class="button" href="'.esc_url(self::url($id)).'">Отмена</a></form></div>';
    }
    private static function same_article($a,$b) { return Repository::hash($a)===Repository::hash($b); }
    public static function sources() {
        if (!current_user_can('es_edit')) { wp_die('Нет прав.'); }
        $catalog=json_decode(file_get_contents(ES_DIR.'sources.json'),true);
        echo '<div class="wrap es-admin"><h1>Каталог источников</h1><p>30 стартовых адресов. Каталог не подтверждает факты, RSS или права на фотографии. Дополнительные первоисточники можно указывать непосредственно в статье.</p><table class="widefat striped"><thead><tr><th>Источник</th><th>Регион</th><th>Приоритет / роль</th></tr></thead><tbody>';
        foreach ($catalog as $s) { echo '<tr><td><a target="_blank" rel="noopener noreferrer" href="'.esc_url($s['url']).'">'.esc_html($s['name']).'</a></td><td>'.esc_html($s['region']).'</td><td>'.esc_html($s['priority'].' / '.$s['role']).'</td></tr>'; } echo '</tbody></table></div>';
    }
}
