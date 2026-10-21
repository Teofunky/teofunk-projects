<?php
namespace Kozlov\Standard;
if (!defined('ABSPATH')) { exit; }

/** Render the active classic theme, keeping the newspaper's metadata and styles local. */
final class Theme_Shell {
    private static $native=false;

    public static function begin($head,$private) {
        self::$native=(bool)(locate_template('header.php')&&locate_template('footer.php'));
        if ($private) {
            // The club theme includes hard-coded analytics. Drafts must never be sent to them.
            header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; frame-src 'none'; object-src 'none'; base-uri 'self'; form-action 'self'");
        }
        if (!self::$native) {
            echo '<!doctype html><html lang="ru"><head><meta charset="utf-8">'.$head.'</head><body class="es-newspaper-page">';
            return;
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
        ob_start(); get_header(); $html=ob_get_clean();
        $html=preg_replace_callback('~<head\b[^>]*>(.*?)</head\s*>~is',static function($match)use($head) {
            $content=preg_replace('~<title\b[^>]*>.*?</title\s*>~is','',$match[1]);
            $content=preg_replace('~<script\b(?=[^>]*type\s*=\s*[\"\']application/ld\+json[\"\'])[^>]*>.*?</script\s*>~is','',$content);
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
        if (self::$native) { get_footer(); }
        else { echo '</body></html>'; }
    }
}
