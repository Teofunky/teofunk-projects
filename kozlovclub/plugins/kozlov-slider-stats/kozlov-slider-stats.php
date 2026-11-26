<?php
/**
 * Plugin Name: Kozlov Slider Stats
 * Description: Слайдер главной в Яндекс Метрике: показы слайдера и каждого слайда, листания, клики и кнопки покупки отдельно для десктопа, планшета и телефона (фасад, театр теней, обычный слайдер). Ничего не меняет на странице, только слушает.
 * Version: 1.0.1
 * Author: Kozlov Club
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * Text Domain: kozlov-slider-stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'KZSL_VER', '1.0.1' );
define( 'KZSL_FILE', __FILE__ );
define( 'KZSL_DIR', plugin_dir_path( __FILE__ ) );
define( 'KZSL_URL', plugin_dir_url( __FILE__ ) );
define( 'KZSL_DEFAULT_COUNTER', '44419291' );

/** Goals the script sends: identifier => name to give the goal in Metrika. */
function kzsl_goals() {
	return array(
		'kzsl_view'       => 'Слайдер: показ',
		'kzsl_slide_view' => 'Слайдер: показ слайда',
		'kzsl_swipe'      => 'Слайдер: листание',
		'kzsl_click'      => 'Слайдер: клик',
		'kzsl_buy'        => 'Слайдер: кнопка покупки',
		'kzsl_buy_next'   => 'Слайдер: кнопка покупки на следующей странице',
	);
}

function kzsl_default_settings() {
	return array(
		'enabled'      => 1,
		'counter'      => KZSL_DEFAULT_COUNTER,
		'skip_editors' => 1,
		'next'         => 1,
	);
}

function kzsl_settings() {
	$s = get_option( 'kzsl_settings', array() );
	$s = is_array( $s ) ? $s : array();
	$s = array_merge( kzsl_default_settings(), $s );
	$s['counter'] = preg_replace( '/\D/', '', (string) $s['counter'] );
	if ( '' === $s['counter'] ) {
		$s['counter'] = KZSL_DEFAULT_COUNTER;
	}
	return $s;
}

register_activation_hook( __FILE__, 'kzsl_activate' );
function kzsl_activate() {
	if ( false === get_option( 'kzsl_settings', false ) ) {
		add_option( 'kzsl_settings', kzsl_default_settings(), '', 'no' );
	}
	kzsl_purge_page_cache();
}

register_deactivation_hook( __FILE__, 'kzsl_purge_page_cache' );

/** Cached pages (W3 Total Cache) are dropped so the script appears or disappears at once. */
function kzsl_purge_page_cache() {
	try {
		if ( function_exists( 'w3tc_pgcache_flush' ) ) {
			w3tc_pgcache_flush();
		} elseif ( function_exists( 'w3tc_flush_url' ) ) {
			w3tc_flush_url( home_url( '/' ) );
		}
	} catch ( Throwable $e ) {
		return;
	}
}

/** Where the script runs: home page always, other pages only for «покупка на следующей странице». */
function kzsl_where() {
	static $where = null;
	if ( null !== $where ) {
		return $where;
	}
	$where = '';
	try {
		if ( is_admin() || wp_doing_ajax() || is_feed() || ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) ) {
			return $where;
		}
		$s = kzsl_settings();
		if ( empty( $s['enabled'] ) ) {
			return $where;
		}
		if ( is_front_page() ) {
			$where = 'home';
		} elseif ( ! empty( $s['next'] ) ) {
			$where = 'other';
		}
	} catch ( Throwable $e ) {
		$where = '';
	}
	return $where;
}

add_action( 'wp_enqueue_scripts', 'kzsl_enqueue', 30 );
function kzsl_enqueue() {
	try {
		if ( ! kzsl_where() ) {
			return;
		}
		wp_enqueue_script( 'kzsl-front', KZSL_URL . 'assets/kzsl.js', array(), KZSL_VER, array( 'in_footer' => true, 'strategy' => 'defer' ) );
	} catch ( Throwable $e ) {
		return;
	}
}

/** Settings for the script as JSON data, not a global variable. Printed before the footer scripts. */
add_action( 'wp_footer', 'kzsl_print_config', 1 );
function kzsl_print_config() {
	try {
		$where = kzsl_where();
		if ( ! $where ) {
			return;
		}
		$s      = kzsl_settings();
		$silent = ! empty( $s['skip_editors'] ) && is_user_logged_in() && current_user_can( 'edit_posts' );
		$cfg    = array(
			'v'       => KZSL_VER,
			'counter' => (int) $s['counter'],
			'home'    => 'home' === $where,
			'next'    => ! empty( $s['next'] ),
			'silent'  => $silent,
		);
		echo '<script type="application/json" id="kzsl-config">' . wp_json_encode( $cfg ) . '</script>' . "\n";
	} catch ( Throwable $e ) {
		return;
	}
}

if ( is_admin() ) {
	require_once KZSL_DIR . 'includes/admin.php';
}
