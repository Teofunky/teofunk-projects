<?php
/**
 * Kozlov Club 2026: тема на Kozlov Core.
 * Требует плагин Kozlov Core (kozlov_concert, kozlov_concerts).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'KZ_THEME_VER', '1.0.9' );
define( 'KZ_PROD_HOST', 'kozlovclub.ru' ); // счётчики и пиксели включаются только на боевом домене

require_once get_template_directory() . '/inc/helpers.php';
require_once get_template_directory() . '/inc/shortcodes.php';
require_once get_template_directory() . '/inc/calendar.php';

add_action( 'after_setup_theme', function () {
	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'html5', array( 'search-form', 'gallery', 'caption' ) );
	register_nav_menus( array( 'main-menu' => 'Главное меню' ) );
	// размеры из старой темы: старые превью подходят без пересчёта
	add_image_size( 'category-thumb', 320, 9999 );
	add_image_size( 'category-thumb2', 450, 9999 );
	add_image_size( 'slider-thumb', 300, 400 );
} );

add_action( 'wp_enqueue_scripts', function () {
	wp_enqueue_style( 'kz-google', 'https://fonts.googleapis.com/css?family=Roboto:300,400,500,700,900&subset=cyrillic', array(), null );
	wp_enqueue_style( 'kz-google2', 'https://fonts.googleapis.com/css?family=Lora&subset=cyrillic', array(), null );
	wp_enqueue_style( 'kz-style', get_stylesheet_uri(), array(), KZ_THEME_VER );
	// старые скрипты темы написаны под глобальный $, поэтому jQuery свой
	if ( ! is_admin() ) {
		wp_deregister_script( 'jquery' );
		wp_register_script( 'jquery', get_template_directory_uri() . '/js/libs/jquery-3.2.1.min.js', array(), '3.2.1', false );
		wp_enqueue_script( 'jquery' );
	}
	wp_enqueue_script( 'kz-owl', get_template_directory_uri() . '/js/libs/owl.carousel.min.js', array( 'jquery' ), '2.3.4', true );
	wp_enqueue_script( 'kz-script', get_template_directory_uri() . '/js/script.js', array( 'jquery', 'kz-owl' ), KZ_THEME_VER, true );
	wp_enqueue_script( 'kz-calendar', get_template_directory_uri() . '/js/calendar.js', array(), KZ_THEME_VER, array( 'in_footer' => true, 'strategy' => 'defer' ) );
	wp_localize_script( 'kz-script', 'KZ', array(
		'ajax'   => admin_url( 'admin-ajax.php' ),
		'home'   => home_url( '/' ),
		'phone'  => kz_setting( 'phone', '+7 (495) 296-12-00' ),
		'tel'    => preg_replace( '/[^0-9+]/', '', kz_setting( 'phone', '+74952961200' ) ),
	) );
	wp_register_script( 'tcwidget', 'https://ticketscloud.com/static/scripts/widget/tcwidget.js', array(), null, true );
	wp_enqueue_script( 'tcwidget' );
	wp_add_inline_script( 'tcwidget', 'window.ticketscloudid=' . wp_json_encode( kz_tc_token() ) . ';', 'before' );
} );

/** Токен организатора Ticketscloud: из настроек Core, иначе прежний токен сайта. */
function kz_tc_token() {
	$t = kz_setting( 'tc_token', '' );
	return $t ?: 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiIsImlzcyI6InRpY2tldHNjbG91ZC5ydSJ9.eyJwIjoiNWNlZmE4MTUxMzFjMGQwMDBmOWU5YmI1In0.UO8dYRJNhMsXTk2fspJJuF_yGo4LggOkQadFqtsXZWc';
}

function kz_setting( $key, $default = '' ) {
	$s = get_option( 'kc_settings', array() );
	return ( isset( $s[ $key ] ) && '' !== $s[ $key ] ) ? $s[ $key ] : $default;
}

function kz_is_production() {
	return wp_parse_url( home_url(), PHP_URL_HOST ) === KZ_PROD_HOST || wp_parse_url( home_url(), PHP_URL_HOST ) === 'www.' . KZ_PROD_HOST;
}

/** Страница ресторана использует свой шаблон, как раньше (page-menu.php). */
add_filter( 'theme_page_templates', function ( $t ) {
	$t['page-menu.php'] = 'Меню ресторана';
	$t['page-archive.php'] = 'Архив концертов';
	return $t;
} );

/** Страница со слагом archive показывает прошедшие концерты. */
add_filter( 'template_include', function ( $template ) {
	if ( is_page( 'archive' ) ) {
		$t = locate_template( 'page-archive.php' );
		if ( $t ) {
			return $t;
		}
	}
	return $template;
} );

/** 404 без «угадывания» адреса (правило старой темы). */
add_filter( 'redirect_canonical', function ( $url ) {
	return is_404() ? false : $url;
} );

/** Заголовок жанровых и сценических страниц: «Джаз: концерты в Москве». */
add_filter( 'wpseo_title', 'kz_archive_title' );
add_filter( 'pre_get_document_title', 'kz_archive_title' );
function kz_archive_title( $title ) {
	if ( is_tax( 'kc_genre' ) ) {
		return single_term_title( '', false ) . ': концерты в Москве | Клуб Алексея Козлова';
	}
	if ( is_tax( 'kc_stage' ) ) {
		return 'Концерты на сцене ' . single_term_title( '', false ) . ' | Клуб Алексея Козлова';
	}
	return $title;
}

/** Автоотключение автосохранения, как в старой теме (редакторы теряли черновики из-за гонок). */
add_action( 'admin_init', function () {
	wp_deregister_script( 'autosave' );
} );

// Короткие теги <? не используются: тема работает при любом short_open_tag.

/**
 * Афиша по дате (/?date=…&date2=…): та же главная, но без слайдера, фасада, панели фильтров и навигатора.
 * Плагины показываются только на is_front_page(), поэтому для таких адресов запрос перестаёт считаться главной,
 * а шаблон front-page.php подключается явно.
 */
function kz_is_date_view() {
	return isset( $_GET['date'] ) || isset( $_GET['date1'] );
}
add_action( 'wp', function () {
	if ( is_admin() || ! kz_is_date_view() || ! is_front_page() ) {
		return;
	}
	global $wp_query;
	$GLOBALS['kz_date_view'] = true;
	$wp_query->is_home = false;
	$wp_query->is_page = false;
	$wp_query->is_singular = false;
}, 1 );
add_filter( 'template_include', function ( $tpl ) {
	return empty( $GLOBALS['kz_date_view'] ) ? $tpl : get_template_directory() . '/front-page.php';
}, 99 );
add_filter( 'body_class', function ( $classes ) {
	if ( ! empty( $GLOBALS['kz_date_view'] ) ) {
		$classes[] = 'home';
		$classes[] = 'kz-date-view';
	}
	return $classes;
} );
function kz_date_view_title( $title ) {
	if ( empty( $GLOBALS['kz_date_view'] ) ) {
		return $title;
	}
	$a = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? $_GET['date1'] ?? '' ) ? ( $_GET['date'] ?? $_GET['date1'] ) : '';
	$b = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $_GET['date2'] ?? '' ) ? $_GET['date2'] : '';
	return $a ? 'Концерты ' . kz_range_label( $a, $b ) . ' | ' . get_bloginfo( 'name' ) : $title;
}
add_filter( 'pre_get_document_title', 'kz_date_view_title', 20 );
add_filter( 'wpseo_title', 'kz_date_view_title', 20 );
add_filter( 'wpseo_robots', fn( $r ) => empty( $GLOBALS['kz_date_view'] ) ? $r : 'noindex, follow' );
