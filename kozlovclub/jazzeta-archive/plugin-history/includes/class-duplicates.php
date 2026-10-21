<?php
namespace Kozlov\Standard;
if (!defined('ABSPATH')) { exit; }
/** Exact matching only. No remote requests and no claim of semantic recognition. */
final class Duplicates {
    public static function install() {
        global $wpdb; $c=$wpdb->get_charset_collate();
        $defs=[
            'events'=>"event_id varchar(120) NOT NULL,\n title text NOT NULL,\n metadata longtext DEFAULT NULL,\n created_at datetime NOT NULL,\n PRIMARY KEY  (event_id)",
            'publications'=>"id bigint unsigned NOT NULL AUTO_INCREMENT,\n url_hash char(64) NOT NULL,\n canonical_url text NOT NULL,\n publisher varchar(500) NOT NULL,\n title text NOT NULL,\n external_key char(64) NOT NULL DEFAULT '',\n content_hash char(64) NOT NULL DEFAULT '',\n metadata longtext DEFAULT NULL,\n created_at datetime NOT NULL,\n PRIMARY KEY  (id),\n UNIQUE KEY url_hash (url_hash),\n KEY external_key (external_key),\n KEY content_hash (content_hash)",
            'article_sources'=>"article_id bigint unsigned NOT NULL,\n source_id varchar(64) NOT NULL,\n publication_id bigint unsigned NOT NULL,\n external_key char(64) NOT NULL DEFAULT '',\n content_hash char(64) NOT NULL DEFAULT '',\n layer varchar(8) NOT NULL,\n PRIMARY KEY  (article_id,source_id,layer),\n KEY publication (publication_id,layer,article_id),\n KEY external_key (external_key,layer),\n KEY content_hash (content_hash,layer)",
            'identities'=>"article_id bigint unsigned NOT NULL,\n layer varchar(8) NOT NULL,\n event_id varchar(120) NOT NULL,\n text_hash char(64) NOT NULL,\n title_hash char(64) NOT NULL,\n PRIMARY KEY  (article_id,layer),\n KEY event_id (event_id,layer),\n KEY text_hash (text_hash,layer),\n KEY title_hash (title_hash,layer)",
            'relations'=>"article_id bigint unsigned NOT NULL,\n related_article_id bigint unsigned NOT NULL,\n layer varchar(8) NOT NULL,\n relation_type varchar(24) NOT NULL,\n reason text NOT NULL,\n method varchar(24) NOT NULL DEFAULT 'manual',\n confidence decimal(5,4) DEFAULT NULL,\n reviewed_by bigint unsigned NOT NULL DEFAULT 0,\n PRIMARY KEY  (article_id,related_article_id,layer),\n KEY related (related_article_id,layer)",
            'imports'=>"id bigint unsigned NOT NULL AUTO_INCREMENT,\n issue_id bigint unsigned NOT NULL,\n actor_id bigint unsigned NOT NULL,\n request_key char(64) DEFAULT NULL,\n payload_hash char(64) NOT NULL,\n created_at datetime NOT NULL,\n PRIMARY KEY  (id),\n UNIQUE KEY request_key (request_key),\n UNIQUE KEY payload_hash (payload_hash),\n KEY issue_id (issue_id)"
        ];
        foreach ($defs as $name=>$columns) {
            $t=DB::table($name); dbDelta("CREATE TABLE $t (\n $columns\n) ENGINE=InnoDB $c;");
            if ($wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$t))!=='InnoDB') { wp_die('Не удалось подготовить таблицы проверки дублей. Активация остановлена.'); }
        }
    }
    public static function canonical($url) {
        $p=wp_parse_url($url); if (!$p) { return ''; }
        $pairs=[];
        foreach (explode('&',$p['query']??'') as $pair) {
            if ($pair==='') { continue; }
            $key=rawurldecode(explode('=',$pair,2)[0]);
            if (preg_match('/^(utm_.+|fbclid|gclid|yclid|mc_cid|mc_eid)$/i',$key)) { continue; }
            $pairs[]=$pair;
        }
        sort($pairs,SORT_STRING);
        $path=$p['path']??'/'; if ($path==='') { $path='/'; }
        return strtolower($p['scheme']).'://'.strtolower($p['host']).(($p['port']??443)!==443?':'.$p['port']:'').$path.($pairs?'?'.implode('&',$pairs):'');
    }
    public static function fingerprint($text) {
        $text=html_entity_decode(wp_strip_all_tags($text),ENT_QUOTES|ENT_HTML5,'UTF-8');
        $text=preg_replace('/\s+/u',' ',trim($text));
        if (function_exists('mb_strtolower')) { $text=mb_strtolower($text,'UTF-8'); } else { $text=strtolower($text); }
        return hash('sha256',$text);
    }
    public static function external($s) { return empty($s['publication_id'])?'':hash('sha256',strtolower(wp_parse_url($s['url'],PHP_URL_HOST)).'|'.$s['publication_id']); }
    public static function sync($issue_id,$layer='draft') {
        global $wpdb; $articles=Repository::rows($issue_id,false);
        foreach ($articles as $r) {
            foreach (['article_sources','identities','relations'] as $table) { DB::must($wpdb->delete(DB::table($table),['article_id'=>$r['id'],'layer'=>$layer])); }
            if (!$r['active']) { continue; }
            $a=json_decode($r['draft_json'],true); $event=$a['event_id']??$a['story_group_id'];
            DB::must($wpdb->query($wpdb->prepare('INSERT IGNORE INTO '.DB::table('events').' (event_id,title,created_at) VALUES (%s,%s,%s)',$event,$a['headline'],DB::now())));
            DB::must($wpdb->insert(DB::table('identities'),['article_id'=>$r['id'],'layer'=>$layer,'event_id'=>$event,'text_hash'=>self::fingerprint(Validation::body($a['body_paragraphs'])),'title_hash'=>self::fingerprint($a['headline'])]));
            foreach ($a['sources'] as $s) {
                $url=self::canonical($s['url']); $hash=hash('sha256',$url); $t=DB::table('publications');
                DB::must($wpdb->query($wpdb->prepare("INSERT IGNORE INTO $t (url_hash,canonical_url,publisher,title,external_key,content_hash,created_at) VALUES (%s,%s,%s,%s,%s,%s,%s)",$hash,$url,$s['publisher'],$s['title'],self::external($s),$s['content_hash']??'',DB::now())));
                $pub=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE url_hash=%s",$hash));
                // Identity evidence is per appearance too: changing a source never rewrites an archived article's text.
                DB::must($wpdb->insert(DB::table('article_sources'),['article_id'=>$r['id'],'source_id'=>$s['id'],'publication_id'=>$pub,'external_key'=>self::external($s),'content_hash'=>$s['content_hash']??'','layer'=>$layer]));
            }
            foreach ($a['previous_article_ids']??[] as $slug) {
                $prior=(int)$wpdb->get_var($wpdb->prepare('SELECT id FROM '.DB::table('articles').' WHERE slug=%s',$slug));
                if ($prior && $prior!==(int)$r['id']) { DB::must($wpdb->insert(DB::table('relations'),['article_id'=>$r['id'],'related_article_id'=>$prior,'layer'=>$layer,'relation_type'=>'development','reason'=>$a['development_note']??'','reviewed_by'=>$r['reviewed_by']??0])); }
            }
        }
    }
    public static function matches($article,$issue_id,$article_id=0) {
        global $wpdb; $x=DB::table('identities'); $a=DB::table('articles'); $i=DB::table('issues');
        $event=$article['event_id']??$article['story_group_id']; $text=self::fingerprint(Validation::body($article['body_paragraphs'])); $title=self::fingerprint($article['headline']);
        $rows=$wpdb->get_results($wpdb->prepare("SELECT a.id,a.slug,i.edition_date,x.event_id,x.text_hash,x.title_hash FROM $x x JOIN $a a ON a.id=x.article_id JOIN $i i ON i.id=a.issue_id WHERE x.layer='public' AND i.state='published' AND i.id<>%d AND (x.event_id=%s OR x.text_hash=%s OR x.title_hash=%s) ORDER BY i.edition_date DESC LIMIT 51",$issue_id,$event,$text,$title),ARRAY_A);
        $out=[];
        foreach ($rows as $r) {
            $reason=$r['text_hash']===$text?'exact_text':($r['event_id']===$event?'event_id':'headline');
            $out[$r['id']]=['article_id'=>(int)$r['id'],'slug'=>$r['slug'],'date'=>$r['edition_date'],'reasons'=>[$reason]];
        }
        $p=DB::table('publications'); $l=DB::table('article_sources');
        foreach ($article['sources'] as $s) {
            $where=$wpdb->prepare('p.url_hash=%s',hash('sha256',self::canonical($s['url'])));
            if (self::external($s)) { $where.=$wpdb->prepare(' OR l.external_key=%s',self::external($s)); }
            if (!empty($s['content_hash'])) { $where.=$wpdb->prepare(' OR l.content_hash=%s',$s['content_hash']); }
            $found=$wpdb->get_results($wpdb->prepare("SELECT DISTINCT a.id,a.slug,i.edition_date FROM $p p JOIN $l l ON l.publication_id=p.id JOIN $a a ON a.id=l.article_id JOIN $i i ON i.id=a.issue_id WHERE l.layer='public' AND i.state='published' AND i.id<>%d AND ($where) ORDER BY i.edition_date DESC LIMIT 51",$issue_id),ARRAY_A);
            foreach ($found as $r) { if (!isset($out[$r['id']])) { $out[$r['id']]=['article_id'=>(int)$r['id'],'slug'=>$r['slug'],'date'=>$r['edition_date'],'reasons'=>[]]; } $out[$r['id']]['reasons'][]='source_identity'; }
        }
        return array_values($out);
    }
    public static function blockers($issue) {
        global $wpdb; $errors=[]; $seen_events=[]; $seen_urls=[]; $seen_text=[]; $seen_external=[]; $seen_hashes=[];
        foreach (Repository::rows($issue['id']) as $r) {
            $a=json_decode($r['draft_json'],true); $event=$a['event_id']??$a['story_group_id']; $text=self::fingerprint(Validation::body($a['body_paragraphs']));
            if (isset($seen_events[$event])) { $errors[]='Два материала одного события внутри выпуска: '.$event.'. Объедините их.'; } $seen_events[$event]=true;
            if (isset($seen_text[$text])) { $errors[]='Дословно повторён текст внутри выпуска: '.$a['headline']; } $seen_text[$text]=true;
            foreach ($a['sources'] as $s) {
                $url=self::canonical($s['url']); if (isset($seen_urls[$url])&&$seen_urls[$url]!==$a['id']) { $errors[]='Одна публикация источника используется в разных новостях номера: '.$url; } $seen_urls[$url]=$a['id'];
                $external=self::external($s); $source_hash=$s['content_hash']??'';
                if (($external&&isset($seen_external[$external])&&$seen_external[$external]!==$a['id'])||($source_hash&&isset($seen_hashes[$source_hash])&&$seen_hashes[$source_hash]!==$a['id'])) { $errors[]='Одинаковый ID или текст публикации источника в разных новостях: '.$a['headline']; }
                if ($external) { $seen_external[$external]=$a['id']; } if ($source_hash) { $seen_hashes[$source_hash]=$a['id']; }
            }
            $matches=self::matches($a,$issue['id'],$r['id']);
            if (count($matches)>50) { $errors[]='Слишком много совпадений: уточните событие и источники '.$a['headline']; }
            foreach ($matches as $m) {
                if (in_array('exact_text',$m['reasons'],true)) { $errors[]='Повтор полного текста из '.$m['date'].': '.$a['headline']; continue; }
                if (!trim($a['development_note']??'') || !in_array($m['slug'],$a['previous_article_ids']??[],true)) { $errors[]='Возможный повтор '.$a['headline'].' → '.$m['slug'].'. Свяжите публикации и объясните существенный новый факт.'; }
            }
            foreach ($a['previous_article_ids']??[] as $slug) {
                $prior=$wpdb->get_row($wpdb->prepare('SELECT i.edition_date,i.state FROM '.DB::table('articles').' a JOIN '.DB::table('issues').' i ON i.id=a.issue_id WHERE a.slug=%s AND a.public_json IS NOT NULL AND i.id<>%d',$slug,$issue['id']),ARRAY_A);
                if (!$prior || $prior['state']!=='published' || $prior['edition_date']>$issue['edition_date']) { $errors[]='Некорректная связь с предыдущей публикацией: '.$slug; }
                if (!trim($a['development_note']??'')) { $errors[]='Для связи с предыдущей публикацией нужен новый факт.'; }
            }
        }
        return array_values(array_unique($errors));
    }
}
