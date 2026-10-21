<?php
namespace Kozlov\Standard;
if (!defined('ABSPATH')) { exit; }
final class Front {
    const BASE='gazeta';
    public static function init() { add_action('template_redirect',Runtime::guard([self::class,'route']),0); }
    public static function rules() { Runtime::rules(); }
    public static function url($path='') {
        // Keep internal callers from 0.3.0 compatible while generating only short canonical URLs.
        if (preg_match('/^issue\/(\d{4}-\d{2}-\d{2})\/?$/D',$path,$m)) { $path=$m[1]; }
        if (get_option('permalink_structure')) { return home_url('/'.self::BASE.'/'.($path?(substr($path,-4)==='.xml'?$path:trim($path,'/').'/'):'')); }
        $q=['es_view'=>'latest']; $p=explode('/',trim($path,'/'));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D',$path)) { $q=['es_view'=>'issue','es_key'=>$path]; }
        if (($p[0]??'')==='material') { $q=['es_view'=>'article','es_key'=>$p[1]]; }
        if (($p[0]??'')==='preview') { $q=['es_view'=>'preview','es_key'=>$p[1]]; }
        if (($p[0]??'')==='archive') { $q=['es_view'=>'archive','es_page'=>$p[2]??1]; }
        if ($path==='sitemap.xml') { $q=['es_view'=>'sitemap']; }
        if (preg_match('/^sitemap-(issues|articles)-(\d+)\.xml$/D',$path,$m)) { $q=['es_view'=>'sitemap_part','es_key'=>$m[1],'es_page'=>$m[2]]; }
        return add_query_arg($q,home_url('/'));
    }
    public static function preview_url($id) { $r=Repository::issue($id); return add_query_arg('_esnonce',wp_create_nonce('es-preview-'.$id.'-'.$r['version']),self::url('preview/'.$id)); }
    public static function latest() {
        global $wpdb; $t=DB::table('issues');
        return $wpdb->get_row("SELECT * FROM $t WHERE state='published' ORDER BY edition_date DESC,id DESC LIMIT 1",ARRAY_A);
    }
    public static function route() {
        $view=get_query_var('es_view'); if (!$view) { return; }
        if (!Runtime::can_read()) { Runtime::deny(404); }
        if (!in_array($view,['latest','issue','legacy_issue','article','archive','preview','sitemap','sitemap_part'],true)) { self::error_page(404); }
        if (!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD'],true)) { header('Allow: GET, HEAD'); self::error_page(405); }
        if ($view==='sitemap'||$view==='sitemap_part') { self::sitemap($view); }
        global $wpdb; $key=(string)get_query_var('es_key'); $page=max(1,min(1000000,(int)get_query_var('es_page',1))); $issue=null; $doc=null; $article=null; $archive=null; $preview=$view==='preview';
        if ($preview) {
            $issue=Repository::issue(absint($key));
            $nonce=isset($_GET['_esnonce'])&&is_string($_GET['_esnonce'])?wp_unslash($_GET['_esnonce']):'';
            if (!$issue || !current_user_can('es_edit') || !wp_verify_nonce($nonce,'es-preview-'.$issue['id'].'-'.$issue['version'])) { self::error_page(404); }
            $d=Repository::doc($issue); $doc=['edition_date'=>$d['edition_date'],'number'=>$issue['number'],'articles'=>[],'social'=>$d['social']];
            foreach (Repository::rows($issue['id']) as $r) { $a=Validation::public_article(json_decode($r['draft_json'],true)); $a['slug']=$r['slug']; $doc['articles'][]=$a; }
            $selected=isset($_GET['article'])&&is_string($_GET['article'])?sanitize_key(wp_unslash($_GET['article'])):'';
            if ($selected) { foreach ($doc['articles'] as $a) { if ($a['slug']===$selected) { $article=$a; break; } } if (!$article) { self::error_page(404); } }
            nocache_headers(); header('Cache-Control: private, no-store, max-age=0'); header('X-Robots-Tag: noindex, nofollow, noarchive'); header('Referrer-Policy: no-referrer');
        } elseif ($view==='latest') { $issue=self::latest(); }
        elseif ($view==='issue'||$view==='legacy_issue') {
            if (!Validation::date($key)) { self::error_page(404); }
            $issue=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::table('issues')." WHERE edition_date=%s AND state='published'",$key),ARRAY_A); if (!$issue) { self::error_page(404); }
            if ($view==='legacy_issue') {
                if (Runtime::mode()==='public') { header('Cache-Control: no-cache, max-age=0, must-revalidate'); } else { Runtime::private_headers(); }
                if (!wp_safe_redirect(self::url($issue['edition_date']),301,'Kozlov Jazzeta')) { self::error_page(500); }
                exit;
            }
        }
        elseif ($view==='article') {
            $r=$wpdb->get_row($wpdb->prepare('SELECT a.public_json,a.issue_id FROM '.DB::table('articles').' a JOIN '.DB::table('issues')." i ON i.id=a.issue_id WHERE a.slug=%s AND i.state='published' AND a.public_json IS NOT NULL",$key),ARRAY_A);
            if (!$r) { self::error_page(404); } $issue=Repository::issue($r['issue_id']); $article=json_decode($r['public_json'],true);
        } elseif ($view==='archive') { $archive=Repository::listing($page,true); if ($page>max(1,$archive['pages'])) { self::error_page(404); } }
        if ($issue&&!$doc) { $doc=json_decode($issue['public_json'],true); }
        if (!$preview&&Runtime::mode()==='public') { header('Cache-Control: no-cache, max-age=0, must-revalidate'); header('Referrer-Policy: strict-origin-when-cross-origin'); }
        if (is_user_logged_in()) { nocache_headers(); header('Cache-Control: private, no-store, max-age=0'); header('Vary: Cookie',false); }
        status_header(200); header('Content-Type: text/html; charset=UTF-8'); header('X-Content-Type-Options: nosniff'); header('X-Frame-Options: SAMEORIGIN');
        $canonical=$article?self::url('material/'.$article['slug']):($issue?self::url('issue/'.$issue['edition_date']):self::url($view==='archive'?($page>1?'archive/page/'.$page:'archive'):''));
        $brand=Settings::name();
        $title=$article?$article['headline'].' — '.$brand:($view==='archive'?'Подшивка — страница '.$page.' — '.$brand:($issue?$brand.' · № '.$issue['number'].' · '.$issue['edition_date']:$brand.' — газета о музыке'));
        $description=$article?$article['lead']:($doc&&$doc['articles']?'В выпуске: '.implode(' · ',array_column(array_slice($doc['articles'],0,3),'headline')):'Газета Клуба Алексея Козлова о джазе, блюзе и музыкальной культуре.');
        $description=wp_html_excerpt(wp_strip_all_tags($description),220,'…');
        $private_view=$preview||Runtime::mode()!=='public';
        if ($private_view) { Runtime::private_headers(); }
        $noindex=$private_view || !get_option('blog_public') || (!$issue&&$view!=='archive');
        $schema=self::structured($issue,$article,$canonical,$private_view);
        require ES_DIR.'templates/newspaper.php'; exit;
    }
    public static function story_url($a,$preview,$issue) { return $preview?add_query_arg('article',$a['slug'],self::preview_url($issue['id'])):self::url('material/'.$a['slug']); }
    public static function structured($issue,$a,$url,$preview) {
        if (!$issue||$preview) { return []; }
        $publisher=['@type'=>'Organization','name'=>'Клуб Алексея Козлова','url'=>home_url('/')];
        $graph=[['@type'=>'BreadcrumbList','itemListElement'=>[['@type'=>'ListItem','position'=>1,'name'=>Settings::name(),'item'=>self::url()],['@type'=>'ListItem','position'=>2,'name'=>'Выпуск '.$issue['edition_date'],'item'=>self::url('issue/'.$issue['edition_date'])]]]];
        if ($a) {
            $entry=['@type'=>'NewsArticle','@id'=>$url.'#article','headline'=>$a['headline'],'description'=>$a['lead'],'inLanguage'=>'ru','mainEntityOfPage'=>$url,'datePublished'=>gmdate('c',strtotime($issue['published_at'].' UTC')),'dateModified'=>gmdate('c',strtotime($issue['public_updated_at'].' UTC')),'author'=>['@type'=>'Organization','name'=>'Редакция «'.Settings::name().'»','url'=>self::url()],'publisher'=>$publisher,'isPartOf'=>['@type'=>'PublicationIssue','issueNumber'=>$issue['number'],'url'=>self::url('issue/'.$issue['edition_date'])]];
            if ($a['media_rights']['image_url']) { $entry['image']=[$a['media_rights']['image_url']]; }
            $graph[]=$entry; $graph[0]['itemListElement'][]=['@type'=>'ListItem','position'=>3,'name'=>$a['headline'],'item'=>$url];
        } else { $graph[]=['@type'=>'PublicationIssue','issueNumber'=>$issue['number'],'datePublished'=>gmdate('c',strtotime($issue['published_at'].' UTC')),'url'=>$url,'name'=>Settings::name().' · '.$issue['edition_date'],'publisher'=>$publisher]; }
        return ['@context'=>'https://schema.org','@graph'=>$graph];
    }
    public static function error_page($status) {
        status_header($status); nocache_headers(); header('Cache-Control: no-store'); header('X-Robots-Tag: noindex, nofollow'); header('Content-Type: text/html; charset=UTF-8');
        echo '<!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Страница недоступна — '.esc_html(Settings::name()).'</title><main><h1>Страница недоступна</h1><p>Материал не опубликован, снят с публикации или адрес неверен.</p><a href="'.esc_url(self::url()).'">К газете</a></main></html>'; exit;
    }
    private static function sitemap($view) {
        if (Runtime::mode()!=='public'||!get_option('blog_public')) { self::error_page(404); }
        global $wpdb; $i=DB::table('issues'); $a=DB::table('articles');
        $counts=['issues'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $i WHERE state='published'"),'articles'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $a a JOIN $i i ON i.id=a.issue_id WHERE i.state='published' AND a.public_json IS NOT NULL")];
        header('Content-Type: application/xml; charset=UTF-8'); header('X-Content-Type-Options: nosniff'); header('Cache-Control: no-cache, max-age=0'); status_header(200);
        $out='<?xml version="1.0" encoding="UTF-8"?>';
        if ($view==='sitemap') {
            $out.='<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
            foreach ($counts as $kind=>$count) { for ($p=1;$p<=ceil($count/500);$p++) { $out.='<sitemap><loc>'.esc_xml(self::url('sitemap-'.$kind.'-'.$p.'.xml')).'</loc></sitemap>'; } }
            $out.='</sitemapindex>';
        } else {
            $kind=(string)get_query_var('es_key'); $page=(int)get_query_var('es_page'); if (!isset($counts[$kind])||$page<1||$page>ceil($counts[$kind]/500)) { self::error_page(404); }
            if ($kind==='issues') { $rows=$wpdb->get_results($wpdb->prepare("SELECT edition_date AS route,public_updated_at FROM $i WHERE state='published' ORDER BY id LIMIT 500 OFFSET %d",($page-1)*500),ARRAY_A); }
            else { $rows=$wpdb->get_results($wpdb->prepare("SELECT a.slug AS route,i.public_updated_at FROM $a a JOIN $i i ON i.id=a.issue_id WHERE i.state='published' AND a.public_json IS NOT NULL ORDER BY a.id LIMIT 500 OFFSET %d",($page-1)*500),ARRAY_A); }
            $out.='<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
            foreach ($rows as $r) { $out.='<url><loc>'.esc_xml(self::url(($kind==='issues'?'issue/':'material/').$r['route'])).'</loc><lastmod>'.esc_xml(gmdate('c',strtotime($r['public_updated_at'].' UTC'))).'</lastmod></url>'; }
            $out.='</urlset>';
        }
        echo $out; exit;
    }
}
