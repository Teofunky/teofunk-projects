<?php
namespace Kozlov\Standard;
if (!defined('ABSPATH')) { exit; }

/** Render the active classic theme, keeping the newspaper's metadata and styles local. */
final class Theme_Shell {
    private static $native=false;
    private static $private=false;

    public static function begin($head,$private) {
        self::$native=(bool)(locate_template('header.php')&&locate_template('footer.php'));
        self::$private=(bool)$private;
        if ($private) {
            // The club theme includes hard-coded analytics. Drafts must never be sent to them.
            header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; frame-src 'none'; object-src 'none'; base-uri 'self'; form-action 'self'");
        }
        add_filter('body_class',static function($classes) {
            $classes=array_diff($classes,['error404','home','blog']);
            $classes[]='es-newspaper-page'; return $classes;
        });
        // KozlovCLub uses jQuery in its header and before wp_footer().
        // Set the registered dependency's group for this newspaper request only.
        add_action('wp_enqueue_scripts',static function() {
            if (wp_script_is('jquery','registered')) { wp_scripts()->add_data('jquery','group',0); }
        },PHP_INT_MAX);
        // Keep the native toolbar, enqueue pipeline and integration hooks in every shell.
        // Respect WordPress's own toolbar preference; never remove its actions or assets.
        $heads=did_action('wp_head'); $bodies=did_action('wp_body_open');
        ob_start();
        if (self::$native) { get_header(); }
        else { echo '<!doctype html><html '; language_attributes(); echo '><head><meta charset="utf-8">'; wp_head(); echo '</head><body '; body_class(); echo '>'; wp_body_open(); }
        $html=ob_get_clean();
        if (did_action('wp_head')===$heads) {
            ob_start(); wp_head(); $hooks=ob_get_clean();
            $html=preg_replace_callback('~</head\s*>~i',static function()use($hooks){return $hooks.'</head>';},$html,1);
        }
        if (did_action('wp_body_open')===$bodies) {
            ob_start(); wp_body_open(); $hooks=ob_get_clean();
            $html=preg_replace_callback('~<body\b[^>]*>~i',static function($m)use($hooks){return $m[0].$hooks;},$html,1);
        }
        $html=preg_replace_callback('~<head\b[^>]*>(.*?)</head\s*>~is',static function($match)use($head) {
            $content=preg_replace('~<title\b[^>]*>.*?</title\s*>~is','',$match[1]);
            // Keep site-wide Organization/WebSite entities from SEO integrations on public views.
            // Query-based WebPage/Article/Breadcrumb nodes describe the wrong virtual page.
            $content=preg_replace_callback('~<script\b(?=[^>]*type\s*=\s*[\"\']application/ld\+json[\"\'])[^>]*>(.*?)</script\s*>~is',[self::class,'site_schema'],$content);
            $content=preg_replace_callback('~<(?:meta|link)\b[^>]*>~is',static function($tag) {
                if (preg_match('~\b(?:name|property)\s*=\s*[\"\'](?:description|robots|googlebot|bingbot|viewport|theme-color|og:[^\"\']+|twitter:[^\"\']+)[\"\']~i',$tag[0])) { return ''; }
                if (preg_match('~\brel\s*=\s*[\"\']canonical[\"\']~i',$tag[0])) { return ''; }
                return $tag[0];
            },$content);
            return '<head>'.$content.$head.'</head>';
        },$html,1);
        echo $html;
    }

    public static function end() {
        $footers=did_action('wp_footer');
        ob_start(); if (self::$native) { get_footer(); } else { wp_footer(); echo '</body></html>'; } $html=ob_get_clean();
        if (did_action('wp_footer')===$footers) {
            ob_start(); wp_footer(); $hooks=ob_get_clean();
            $html=stripos($html,'</body')!==false?preg_replace_callback('~</body\s*>~i',static function()use($hooks){return $hooks.'</body>';},$html,1):$html.$hooks;
        }
        echo $html;
    }
    private static function site_schema($match) {
        if (self::$private) { return ''; }
        $json=json_decode($match[1],true); if (!is_array($json)) { return ''; }
        $nodes=isset($json['@graph'])?$json['@graph']:(isset($json['@type'])?[$json]:$json); $kept=[];
        if (!is_array($nodes)) { return ''; }
        foreach ($nodes as $node) {
            if (!is_array($node) || !isset($node['@type'])) { continue; }
            $types=(array)$node['@type'];
            if (!$types || array_diff($types,['Organization','WebSite'])) { continue; }
            $kept[]=$node;
        }
        if (!$kept) { return ''; }
        return '<script type="application/ld+json">'.wp_json_encode(['@context'=>'https://schema.org','@graph'=>$kept],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).'</script>';
    }
}
