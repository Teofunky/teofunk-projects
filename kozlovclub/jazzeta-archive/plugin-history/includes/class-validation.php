<?php
namespace Kozlov\Standard;
if (!defined('ABSPATH')) { exit; }
final class Validation {
    const MAX_BYTES=262144;
    const LEVELS=['verified_primary'=>'Первоисточник','verified_independent'=>'Независимые источники','attributed_single'=>'По сообщению одного источника','unverified'=>'Не проверено'];
    const REGIONS=['russia'=>'Россия','world'=>'Мир','mixed'=>'Россия и мир'];
    const CATEGORIES=['jazz'=>'Джаз','blues'=>'Блюз','funk_soul'=>'Фанк и соул','guitar_rock'=>'Гитара и рок','festivals'=>'Фестивали','instruments'=>'Инструменты','research'=>'Ноты будущего','other'=>'Люди и сцена'];
    public static function error($text,$status=400) { return new \WP_Error('es_validation',$text,['status'=>$status]); }
    public static function parse($raw) {
        if (!is_string($raw) || strlen($raw)>self::MAX_BYTES) { return self::error('Максимальный размер JSON — 256 КиБ.',413); }
        $object=json_decode($raw,false,32);
        if (json_last_error()!==JSON_ERROR_NONE) { return self::error('Некорректный JSON: '.json_last_error_msg()); }
        $schema=json_decode(file_get_contents(ES_DIR.'schemas/issue.schema.json'),true);
        try { self::schema($object,$schema,$schema,'$'); }
        catch (\InvalidArgumentException $e) { return self::error($e->getMessage()); }
        $doc=json_decode($raw,true,32);
        return self::document($doc);
    }
    private static function schema($v,$s,$root,$path) {
        if (isset($s['$ref'])) { $s=$root['$defs'][basename($s['$ref'])]; }
        if (isset($s['type'])) {
            $ok=false;
            foreach ((array)$s['type'] as $type) {
                $ok=$ok || ($type==='object' && is_object($v)) || ($type==='array' && is_array($v)) || ($type==='string' && is_string($v)) || ($type==='number' && (is_int($v)||is_float($v))) || ($type==='boolean' && is_bool($v)) || ($type==='null' && $v===null);
            }
            if (!$ok) { throw new \InvalidArgumentException($path.': неверный тип.'); }
        }
        if (array_key_exists('const',$s) && $v!==$s['const']) { throw new \InvalidArgumentException($path.': недопустимое значение.'); }
        if (isset($s['enum']) && !in_array($v,$s['enum'],true)) { throw new \InvalidArgumentException($path.': значение вне перечня.'); }
        if (is_object($v)) {
            foreach ($s['required']??[] as $key) { if (!property_exists($v,$key)) { throw new \InvalidArgumentException($path.'.'.$key.': обязательное поле.'); } }
            foreach (get_object_vars($v) as $key=>$child) {
                if (!isset($s['properties'][$key])) { throw new \InvalidArgumentException($path.'.'.$key.': неизвестное поле.'); }
                self::schema($child,$s['properties'][$key],$root,$path.'.'.$key);
            }
        } elseif (is_array($v)) {
            if (count($v)<($s['minItems']??0) || count($v)>min(80,$s['maxItems']??80)) { throw new \InvalidArgumentException($path.': недопустимое число элементов.'); }
            if (!empty($s['uniqueItems']) && count(array_unique(array_map('serialize',$v)))!==count($v)) { throw new \InvalidArgumentException($path.': повтор элементов.'); }
            foreach ($v as $index=>$child) { self::schema($child,$s['items'],$root,$path.'['.$index.']'); }
        } elseif (is_string($v)) {
            $n=function_exists('mb_strlen')?mb_strlen($v,'UTF-8'):strlen($v);
            if ($n<($s['minLength']??0) || $n>20000 || strpos($v,"\0")!==false) { throw new \InvalidArgumentException($path.': недопустимая длина строки.'); }
            if (($s['format']??'')==='date' && !self::date($v)) { throw new \InvalidArgumentException($path.': неверная дата.'); }
            if (($s['format']??'')==='date-time' && !self::timestamp($v)) { throw new \InvalidArgumentException($path.': требуется ISO 8601 с часовым поясом.'); }
            if (($s['format']??'')==='uri' && !self::url($v)) { throw new \InvalidArgumentException($path.': требуется публичная HTTPS-ссылка без логина.'); }
        } elseif (is_numeric($v) && !is_bool($v)) {
            if ($v<($s['minimum']??-INF) || $v>($s['maximum']??INF)) { throw new \InvalidArgumentException($path.': число вне диапазона.'); }
        }
    }
    public static function date($value) {
        if (!is_string($value)) { return false; }
        $d=\DateTimeImmutable::createFromFormat('!Y-m-d',$value,new \DateTimeZone('UTC'));
        return $d && $d->format('Y-m-d')===$value;
    }
    public static function timestamp($value) {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D',$value) || !self::date(substr($value,0,10))) { return false; }
        try { $d=new \DateTimeImmutable($value); $e=\DateTimeImmutable::getLastErrors(); return !$e || (!$e['warning_count']&&!$e['error_count']); } catch (\Exception $e) { return false; }
    }
    public static function url($url) {
        if (!is_string($url) || strlen($url)>2048 || !filter_var($url,FILTER_VALIDATE_URL)) { return false; }
        $p=wp_parse_url($url); $host=strtolower($p['host']??'');
        return ($p['scheme']??'')==='https' && !isset($p['user']) && !isset($p['pass']) && (!isset($p['port'])||$p['port']===443) && preg_match('/^[a-z0-9][a-z0-9.-]*\.[a-z][a-z0-9-]{1,62}$/i',$host) && !filter_var($host,FILTER_VALIDATE_IP) && !preg_match('/(?:^|\.)(?:localhost|local|internal|test|invalid)$/i',$host);
    }
    public static function rich($text) {
        return trim(wp_kses($text,['p'=>[],'br'=>[],'strong'=>[],'em'=>[],'b'=>[],'i'=>[],'blockquote'=>[],'ul'=>[],'ol'=>[],'li'=>[],'a'=>['href'=>true,'title'=>true]],['https','http']));
    }
    public static function body($paragraphs) {
        $out='';
        foreach ($paragraphs as $p) {
            $p=self::rich($p);
            // The text editor can return unwrapped paragraphs followed by a list.
            // wpautop preserves existing blocks and wraps the remaining plain paragraphs.
            $out.=wpautop($p);
        }
        return $out;
    }
    private static function plain($v,$max) {
        $v=sanitize_textarea_field($v);
        if ((function_exists('mb_strlen')?mb_strlen($v):strlen($v))>$max) { throw new \InvalidArgumentException('Поле текста превышает лимит '.$max.' символов.'); }
        return trim($v);
    }
    public static function document($doc) {
        try {
            $ids=[]; $groups=[];
            if (isset($doc['import_id'])&&!preg_match('/^[a-zA-Z0-9_.:-]{1,128}$/D',$doc['import_id'])) { throw new \InvalidArgumentException('Некорректный import_id.'); }
            foreach ($doc['articles'] as &$a) {
                if ($doc['schema_version']==='1.1'&&!isset($a['event_id'])) { throw new \InvalidArgumentException('Для схемы 1.1 требуется event_id.'); }
                $a['event_id']=$a['event_id']??$a['story_group_id'];
                $a['previous_article_ids']=$a['previous_article_ids']??[];
                $a['development_note']=self::plain($a['development_note']??'',4000);
                if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,119}$/D',$a['event_id'])) { throw new \InvalidArgumentException('Некорректный event_id.'); }
                foreach ($a['previous_article_ids'] as $slug) { if (!preg_match('/^[a-z0-9_-]{1,160}$/D',$slug)) { throw new \InvalidArgumentException('Некорректная ссылка на предыдущую статью.'); } }
                foreach (['id'=>64,'story_group_id'=>120] as $key=>$max) {
                    if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,'.($max-1).'}$/D',$a[$key])) { throw new \InvalidArgumentException($key.': используйте латинские строчные буквы, цифры, _ и -.'); }
                }
                if (isset($ids[$a['id']]) || isset($groups[$a['story_group_id']])) { throw new \InvalidArgumentException('Повтор ID статьи или сюжета внутри номера.'); }
                $ids[$a['id']]=true; $groups[$a['story_group_id']]=true;
                foreach (['headline'=>230,'lead'=>1500,'summary'=>1500] as $key=>$max) { $a[$key]=self::plain($a[$key],$max); if (!$a[$key]) { throw new \InvalidArgumentException('Пустой текст: '.$key); } }
                if ($a['event_date']!==null && !self::date($a['event_date'])) { throw new \InvalidArgumentException('event_date: требуется дата YYYY-MM-DD или null.'); }
                if (count($a['sources'])>12 || count($a['claims'])>40 || count($a['body_paragraphs'])>40 || count($a['quotes'])>10) { throw new \InvalidArgumentException('Превышен лимит источников, фактов, абзацев или цитат.'); }
                foreach ($a['body_paragraphs'] as &$p) { $p=self::rich($p); if (!trim(wp_strip_all_tags($p))) { throw new \InvalidArgumentException('Пустой абзац.'); } } unset($p);
                $src=[]; $source_urls=[]; $source_external=[]; $source_hashes=[];
                foreach ($a['sources'] as &$s) {
                    if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/D',$s['id']) || isset($src[$s['id']])) { throw new \InvalidArgumentException('Неверный или повторный ID источника.'); }
                    $src[$s['id']]=true;
                    $canonical=Duplicates::canonical($s['url']);
                    if (isset($source_urls[$canonical])) { throw new \InvalidArgumentException('Одна публикация источника повторяется внутри статьи.'); }
                    $source_urls[$canonical]=true;
                    if (isset($s['publication_id'])&&!is_string($s['publication_id'])) { throw new \InvalidArgumentException('publication_id должен быть строкой.'); }
                    if (isset($s['publication_id'])&&strlen($s['publication_id'])>200) { throw new \InvalidArgumentException('Слишком длинный publication_id.'); }
                    if (isset($s['content_hash'])&&!preg_match('/^[a-f0-9]{64}$/D',$s['content_hash'])) { throw new \InvalidArgumentException('content_hash должен быть SHA-256.'); }
                    $external=Duplicates::external($s); $source_hash=$s['content_hash']??'';
                    if (($external&&isset($source_external[$external]))||($source_hash&&isset($source_hashes[$source_hash]))) { throw new \InvalidArgumentException('Одинаковый ID или текст публикации источника внутри статьи.'); }
                    if ($external) { $source_external[$external]=true; } if ($source_hash) { $source_hashes[$source_hash]=true; }
                    foreach (['title','publisher','dependency_group'] as $key) { $s[$key]=self::plain($s[$key],500); }
                    foreach (['published_at','verification_checked_at'] as $key) {
                        if ($s[$key]!==null && !self::timestamp($s[$key]) && !($key==='published_at'&&self::date($s[$key]))) { throw new \InvalidArgumentException('Неверная дата источника '.$s['id']); }
                    }
                } unset($s);
                $claims=[];
                foreach ($a['claims'] as &$c) {
                    if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/D',$c['id']) || isset($claims[$c['id']])) { throw new \InvalidArgumentException('Неверный или повторный ID факта.'); }
                    $claims[$c['id']]=true;
                    if (array_diff($c['source_ids'],array_keys($src))) { throw new \InvalidArgumentException('Факт ссылается на отсутствующий источник.'); }
                    $c['statement']=self::plain($c['statement'],3000); $c['editorial_note']=self::plain($c['editorial_note'],4000);
                    if (!$c['statement']) { throw new \InvalidArgumentException('Пустой факт.'); }
                } unset($c);
                foreach ($a['quotes'] as &$q) { if (!isset($src[$q['source_id']])) { throw new \InvalidArgumentException('Цитата без известного источника.'); } $q['original']=self::plain($q['original'],4000); $q['translation_ru']=self::plain($q['translation_ru'],4000); } unset($q);
                $a['conflict_disclosure']=$a['conflict_disclosure']===null?null:self::plain($a['conflict_disclosure'],2000);
                $a['media_rights']['credit']=self::plain($a['media_rights']['credit'],500);
                if ($a['media_rights']['image_url']!==null && !self::url($a['media_rights']['image_url'])) { throw new \InvalidArgumentException('Изображение: требуется HTTPS-ссылка или null.'); }
                $a['editorial_notes']=array_map(static function($n){return self::plain($n,4000);},$a['editorial_notes']);
            } unset($a);
            $social=[];
            foreach ($doc['social'] as &$s) {
                if (!isset($ids[$s['article_id']]) || isset($social[$s['article_id']])) { throw new \InvalidArgumentException('Неизвестная или повторная статья в соцверсии.'); }
                $social[$s['article_id']]=true;
                $s['telegram_sentence']=self::plain($s['telegram_sentence'],600); $s['instagram_sentence']=self::plain($s['instagram_sentence'],600);
            } unset($s);
            if (strtotime($doc['editorial']['source_scan_window_start'])>strtotime($doc['editorial']['source_scan_window_end'])) { throw new \InvalidArgumentException('Начало сбора позже конца.'); }
            foreach (['blocking_issues','generation_notes'] as $k) { $doc['editorial'][$k]=array_map(static function($n){return self::plain($n,4000);},$doc['editorial'][$k]); }
            return $doc;
        } catch (\InvalidArgumentException $e) { return self::error($e->getMessage()); }
    }
    public static function article_blockers($a) {
        $errors=[];
        foreach ($a['sources'] as $src) { if (in_array(wp_parse_url($src['url'],PHP_URL_HOST),['example.com','example.org','example.net'],true)) { $errors[]='Замените тестовые ссылки example.com/org/net настоящими первоисточниками.'; } }
        $sources=array_column($a['sources'],null,'id');
        foreach ($a['claims'] as $c) {
            $tag=$a['headline'].' / '.$c['id'].': ';
            if ($c['verification']==='unverified' || !$c['source_ids']) { $errors[]=$tag.'факт не подтверждён.'; continue; }
            $selected=array_intersect_key($sources,array_flip($c['source_ids']));
            foreach ($selected as $s) {
                if (!$s['verification_checked_at'] || strtotime($s['verification_checked_at'])>time()+300) { $errors[]=$tag.'нет корректной даты проверки источника.'; }
                if ($s['published_at'] && strtotime($s['published_at'])>time()+300) { $errors[]=$tag.'дата публикации источника в будущем.'; }
            }
            if ($c['verification']==='verified_primary' && !array_intersect(['official','event_organizer','distributor'],array_column($selected,'type'))) { $errors[]=$tag.'не указан компетентный первоисточник.'; }
            if ($c['verification']==='verified_independent') {
                $independent=array_filter($selected,static function($s){return $s['type']==='independent_editorial' && $s['dependency_group']!=='';});
                if (count(array_unique(array_column($independent,'dependency_group')))<2) { $errors[]=$tag.'нужны две независимые редакционные цепочки.'; }
            }
            if ($c['verification']==='attributed_single' && !trim($c['editorial_note'])) { $errors[]=$tag.'укажите основание и формулу атрибуции в заметке к факту.'; }
        }
        foreach ($a['quotes'] as $q) { if (!trim($q['original']) || !trim($q['translation_ru'])) { $errors[]='Цитата: заполните оригинал и проверенный перевод.'; } }
        $m=$a['media_rights'];
        if ($m['image_url'] && ($m['status']==='none' || !$m['credit'] || !self::local_image($m['image_url']))) { $errors[]='Фото: нужны права, подпись и изображение из медиатеки этого сайта.'; }
        return array_values(array_unique($errors));
    }
    public static function local_image($url) {
        if (!$url || wp_parse_url($url,PHP_URL_HOST)!==wp_parse_url(home_url(),PHP_URL_HOST)) { return false; }
        $id=attachment_url_to_postid($url);
        return $id && wp_attachment_is_image($id) && in_array(get_post_mime_type($id),['image/jpeg','image/png','image/webp','image/avif'],true);
    }
    public static function public_article($a) {
        return array_intersect_key($a,array_flip(['id','region','category','placement','headline','lead','body_paragraphs','summary','event_date','conflict_disclosure','media_rights','previous_article_ids','development_note'])) + [
            'sources'=>array_map(static function($s){return array_intersect_key($s,array_flip(['title','publisher','url','published_at','type']));},$a['sources']),
            'verification'=>array_values(array_unique(array_column($a['claims'],'verification')))
        ];
    }
}
