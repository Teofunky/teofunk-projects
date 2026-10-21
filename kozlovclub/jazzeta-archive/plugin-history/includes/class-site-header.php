<?php
namespace Kozlov\Standard;
if (!defined('ABSPATH')) { exit; }

/** Read the club's existing navigation only; never modify its menu or load theme widgets. */
final class Site_Header {
    public static function logo_url() {
        $file='assets/images/logo-kozlovclub.png';
        if (is_file(get_theme_file_path($file))) { return get_theme_file_uri($file); }
        $id=(int)get_theme_mod('custom_logo');
        return $id?(string)wp_get_attachment_image_url($id,'full'):'';
    }

    public static function menu_html() {
        $locations=get_nav_menu_locations();
        // The current KozlovCLub theme uses the named menu even without an assigned location.
        $menu=!empty($locations['main-menu'])?$locations['main-menu']:'main-menu';
        $items=wp_get_nav_menu_items($menu,['post_status'=>'publish']);
        if (!$items||is_wp_error($items)) { return ''; }
        $children=[];
        foreach ($items as $item) {
            if ($item->post_status!=='publish') { continue; }
            $parent=(int)$item->menu_item_parent;
            $children[$parent][]=$item;
        }
        return self::branch($children,0,0,[]);
    }

    private static function branch($children,$parent,$depth,$seen) {
        if ($depth>5||empty($children[$parent])) { return ''; }
        $html='<ul class="'.($depth?'es-club-submenu':'es-club-menu').'">';
        foreach ($children[$parent] as $item) {
            $id=(int)$item->ID;
            if (isset($seen[$id])) { continue; }
            $visited=$seen; $visited[$id]=true;
            $title=trim(wp_strip_all_tags($item->title));
            if ($title==='') { continue; }
            $url=esc_url((string)$item->url,['http','https']);
            $link=$url&&$url!=='#';
            $nested=self::branch($children,$id,$depth+1,$visited);
            $target=$item->target==='_blank'?' target="_blank" rel="noopener noreferrer"':'';
            $html.='<li>';
            if ($nested) {
                $html.='<details class="es-club-dropdown"><summary>'.esc_html($title).'</summary><div class="es-club-dropdown-panel">';
                if ($link) { $html.='<a class="es-club-parent" href="'.$url.'"'.$target.'>Открыть '.esc_html($title).' →</a>'; }
                $html.=$nested.'</div></details>';
            } elseif ($link) {
                $html.='<a href="'.$url.'"'.$target.'>'.esc_html($title).'</a>';
            } else {
                $html.='<span>'.esc_html($title).'</span>';
            }
            $html.='</li>';
        }
        return $html.'</ul>';
    }

    public static function render($settings) {
        $logo=self::logo_url();
        echo '<header class="es-club-header" aria-label="Клуб Алексея Козлова"><div class="es-club-header-inner">';
        echo '<a class="es-club-logo" href="'.esc_url($settings['club_home_url']).'" aria-label="Клуб Алексея Козлова — главная сайта">';
        if ($logo) { echo '<img src="'.esc_url($logo).'" alt="Клуб Алексея Козлова" width="222" height="106" decoding="async">'; }
        else { echo '<span>Клуб<br>Алексея Козлова</span>'; }
        echo '</a><a class="es-club-home" href="'.esc_url($settings['club_home_url']).'"><span aria-hidden="true">←</span> На главную сайта</a>';
        echo '<nav class="es-club-navigation" aria-label="Меню сайта клуба">'.self::menu_html();
        echo '<a class="es-club-afisha" href="'.esc_url($settings['club_events_url']).'">Афиша и билеты</a><a class="es-club-current" href="'.esc_url(Front::url()).'" aria-current="location">Газета</a></nav>';
        echo '</div></header>';
    }
}
