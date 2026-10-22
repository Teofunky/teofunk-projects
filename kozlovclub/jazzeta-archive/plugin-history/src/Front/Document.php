<?php

namespace Kozlov\Gazeta\Front;

use Kozlov\Gazeta\Settings\Analytics;
use Kozlov\Gazeta\Settings\Appearance;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The newspaper is a standalone page: its own <html> document, no club header or footer.
 * The only way back to the club is an explicit link to the club's home page.
 *
 * wp_head()/wp_footer() still run, so the WordPress toolbar for editors, Yoast site
 * verification and integration hooks keep working. Nothing is parsed out of HTML:
 *  - the theme's own styles and scripts are dequeued for this request only;
 *  - Yoast keeps only verification + Organization/WebSite schema (its filters);
 *  - core canonical/robots/shortlink are unhooked for this request; the page prints its own tags.
 */
final class Document
{
    /** Theme-registered handles that live outside the theme folder (fonts, widgets). */
    private const THEME_EXTERNAL_HANDLES = ['google', 'google2', 'tcwidget', 'kozlov-chat-widget-v2', 'kozlov-formstyler', 'kozlov-scripts', 'kozlov-style'];

    /**
     * Runs in <head> before any stylesheet. Reads only this browser's localStorage; nothing is
     * sent anywhere. Unknown values are ignored, so a stale or edited key cannot break the page.
     */
    public const EARLY_SCRIPT = "(function(d){var h=d.documentElement,g=function(k){try{return window.localStorage.getItem(k)}catch(e){return null}};h.className+=' es-js';"
        . "var lock=h.getAttribute('data-es-lock')==='1';"
        . "var m=g('es-design');if(!lock&&(m==='gazeta'||m==='modern'||m==='brief')){h.setAttribute('data-es-design',m)}"
        . "if(!h.getAttribute('data-es-theme')){var t=g('es-theme');if(t===null&&g('es-night')==='1'){t='dark'}"
        . "if(t!=='light'&&t!=='dark'){t=(window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches)?'dark':'light';h.setAttribute('data-es-theme-auto','1')}h.setAttribute('data-es-theme',t)}"
        . "if(!lock){var z=g('es-size');if(z===null){var p=parseInt(g('es-reader-percent')||'',10);if(p>=90&&p<=140){z=String([90,100,112,125,140].reduce(function(a,b){return Math.abs(b-p)<Math.abs(a-p)?b:a}))}}"
        . "if(z==='90'||z==='100'||z==='112'||z==='125'||z==='140'){h.setAttribute('data-es-size',z)}"
        . "if(g('es-density')==='airy'){h.setAttribute('data-es-density','airy')}}"
        . "h.className+=' es-js'})(document);";

    /** @var array */
    private static $model = [];

    public static function open(array $model): void
    {
        self::$model = $model;
        self::registerHooks();
        if ($model['private'] && !headers_sent()) {
            // No third-party script, pixel or font may see a draft or the closed newspaper.
            header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; frame-src 'none'; object-src 'none'; base-uri 'self'; form-action 'self'");
        }
        $design = $model['design'] ?? Appearance::DEFAULT_DESIGN;
        // data-es-default is the owner's mode for new readers: «Вернуть как было» returns to it.
        $attrs = ' data-es-design="' . esc_attr($design) . '" data-es-default="' . esc_attr($design) . '" data-es-size="100"';
        if (!empty($model['design_lock'])) {
            // Desk preview of a chosen mode: the reader's saved choice must not override it.
            $attrs .= ' data-es-lock="1"';
            if (!empty($model['theme_lock'])) {
                $attrs .= ' data-es-theme="' . esc_attr($model['theme_lock']) . '"';
            }
        }
        echo "<!doctype html>\n<html lang=\"ru\" class=\"es-document\"" . $attrs . ">\n<head>\n";
        echo '<meta charset="' . esc_attr(get_bloginfo('charset') ?: 'UTF-8') . '">' . "\n";
        echo '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">' . "\n";
        // Applies the reader's saved mode, palette and text size before the first paint (no flash).
        echo '<script>' . self::EARLY_SCRIPT . "</script>\n";
        echo '<meta name="color-scheme" content="light dark">' . "\n";
        echo '<title>' . esc_html(self::$model['seo']['title']) . "</title>\n";
        wp_head();
        echo "</head>\n<body ";
        body_class();
        echo ">\n";
        wp_body_open();
    }

    public static function close(array $model): void
    {
        if (!$model['private']) {
            echo Analytics::markup(); // Built from a validated numeric counter id only.
            do_action('es_newspaper_footer');
        }
        wp_footer();
        echo "\n</body>\n</html>\n";
    }

    private static function registerHooks(): void
    {
        add_filter('body_class', [self::class, 'bodyClass'], 99);
        // Themes that support title-tag would print a second <title>; ours is already in <head>.
        add_filter('pre_get_document_title', static function () {
            return esc_html(self::$model['seo']['title']);
        }, 1000);
        remove_action('wp_head', '_wp_render_title_tag', 1);
        if (defined('WPSEO_VERSION')) {
            add_filter('wpseo_frontend_presenters', [self::class, 'yoastPresenters'], 99);
            add_filter('wpseo_schema_graph', [self::class, 'yoastGraph'], 99);
        }
        remove_action('wp_head', 'rel_canonical');
        remove_action('wp_head', 'wp_robots', 1);
        remove_action('wp_head', 'wp_shortlink_wp_head');
        add_action('wp_head', [self::class, 'head'], 1);
        add_action('wp_enqueue_scripts', [self::class, 'assets'], PHP_INT_MAX);
        add_action('admin_bar_menu', [self::class, 'adminBar'], 80);
    }

    public static function bodyClass(array $classes): array
    {
        $classes = array_diff($classes, ['home', 'blog', 'error404', 'paged']);
        $classes[] = 'es-newspaper-page';
        $classes[] = 'es-view-' . sanitize_html_class(self::$model['view']);
        if (self::$model['private']) {
            $classes[] = 'es-private';
        }
        return array_values($classes);
    }

    /** Keep Yoast's site verification, debug markers and schema; drop page-level tags. */
    public static function yoastPresenters($presenters)
    {
        if (!is_array($presenters)) {
            return $presenters;
        }
        return array_values(array_filter($presenters, static function ($presenter) {
            $class = is_object($presenter) ? get_class($presenter) : '';
            return strpos($class, '\\Webmaster\\') !== false
                || strpos($class, '\\Debug\\') !== false
                || substr($class, -strlen('Schema_Presenter')) === 'Schema_Presenter';
        }));
    }

    /** Site-wide Organization/WebSite stay; WebPage, Breadcrumb etc. would describe the wrong page. */
    public static function yoastGraph($graph)
    {
        if (!is_array($graph) || self::$model['private']) {
            return [];
        }
        return array_values(array_filter($graph, static function ($node) {
            if (!is_array($node) || !isset($node['@type'])) {
                return false;
            }
            $types = (array) $node['@type'];
            return $types && !array_diff($types, ['Organization', 'WebSite']);
        }));
    }

    /** Our assets in; the club theme's own CSS/JS out (this request only). Plugins keep theirs. */
    public static function assets(): void
    {
        $themeUrl = get_template_directory_uri();
        $stylesheetUrl = get_stylesheet_directory_uri();
        foreach (['styles' => wp_styles(), 'scripts' => wp_scripts()] as $kind => $registry) {
            foreach ((array) $registry->queue as $handle) {
                $src = isset($registry->registered[$handle]) ? (string) $registry->registered[$handle]->src : '';
                $fromTheme = $src !== '' && (strpos($src, $themeUrl) === 0 || strpos($src, $stylesheetUrl) === 0 || strpos($src, '/wp-content/themes/') !== false);
                if ($fromTheme || in_array($handle, self::THEME_EXTERNAL_HANDLES, true)) {
                    if ($kind === 'styles') {
                        wp_dequeue_style($handle);
                    } else {
                        wp_dequeue_script($handle);
                    }
                }
            }
        }
        wp_enqueue_style('es-newspaper', View::versioned('css/newspaper.css'), [], null);
        wp_add_inline_style('es-newspaper', Appearance::cssVariables());
        wp_enqueue_script('es-newspaper', View::versioned('js/newspaper.js'), [], null, ['in_footer' => true, 'strategy' => 'defer']);
        if ((self::$model['view'] ?? '') === 'crossword') {
            // Only the crossword page loads the game.
            wp_enqueue_style('es-crossword', View::versioned('css/crossword.css'), ['es-newspaper'], null);
            wp_enqueue_script('es-crossword', View::versioned('js/crossword.js'), [], null, ['in_footer' => true, 'strategy' => 'defer']);
        }
    }

    public static function head(): void
    {
        $seo = self::$model['seo'];
        $out = [];
        if ($seo['description'] !== '') {
            $out[] = '<meta name="description" content="' . esc_attr($seo['description']) . '">';
        }
        $out[] = '<meta name="robots" content="' . esc_attr($seo['robots']) . '">';
        if ($seo['canonical'] !== '') {
            $out[] = '<link rel="canonical" href="' . esc_url($seo['canonical']) . '">';
            $og = [
                'og:locale' => 'ru_RU',
                'og:site_name' => self::$model['brand'],
                'og:type' => $seo['og_type'],
                'og:title' => $seo['title'],
                'og:description' => $seo['description'],
                'og:url' => $seo['canonical'],
            ];
            if ($seo['image'] !== '') {
                $og['og:image'] = $seo['image'];
            }
            if (!empty($seo['published']) && $seo['og_type'] === 'article') {
                $og['article:published_time'] = $seo['published'];
                $og['article:modified_time'] = $seo['modified'];
            }
            foreach ($og as $property => $content) {
                $out[] = '<meta property="' . esc_attr($property) . '" content="' . esc_attr((string) $content) . '">';
            }
            $out[] = '<meta name="twitter:card" content="' . ($seo['image'] !== '' ? 'summary_large_image' : 'summary') . '">';
        }
        $out[] = '<meta name="theme-color" content="#F6F3EB" media="(prefers-color-scheme: light)">';
        $out[] = '<meta name="theme-color" content="#151513" media="(prefers-color-scheme: dark)">';
        $out[] = '<link rel="preload" href="' . esc_url(View::asset('fonts/es-serif.woff')) . '" as="font" type="font/woff" crossorigin>';
        if (!empty($seo['schema'])) {
            $out[] = '<script type="application/ld+json">' . wp_json_encode($seo['schema'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . '</script>';
        }
        echo "<!-- Ежедневный стандарт -->\n" . implode("\n", $out) . "\n";
        if (!self::$model['private']) {
            do_action('es_newspaper_head');
        }
    }

    /** "Редакция газеты" in the toolbar for editors. */
    public static function adminBar($bar): void
    {
        if (!is_object($bar) || !current_user_can('es_edit')) {
            return;
        }
        $bar->add_node([
            'id' => 'es-desk',
            'title' => 'Редакция газеты',
            'href' => self::$model['urls']['desk_issue'] ?? self::$model['urls']['desk'],
        ]);
    }
}
