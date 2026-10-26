<?php
/**
 * Plugin Name: Kozlov — замер скорости админки (временно)
 * Description: Пишет в лог время каждого запроса админки, память, число запросов к базе, внешние HTTP-запросы и обходы папок. УДАЛИТЬ после замера.
 *
 * Положить в wp-content/mu-plugins/. Лог: на уровень выше public_html, файл admin-timing.log
 * (для kozlovclub.ru: /home/t/trubkabb/kozlovclub.ru/admin-timing.log).
 * Пишет только запросы вошедших пользователей в админке, admin-ajax и REST, а также cron.
 */

defined( 'ABSPATH' ) || exit;

$GLOBALS['kozlov_timing'] = array(
	'http'      => array(),
	'http_t0'   => array(),
	'dirs'      => 0,
	'dir_first' => '',
);

add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) {
		$GLOBALS['kozlov_timing']['http_t0'][ md5( (string) $url ) ] = microtime( true );
		return $pre;
	},
	1,
	3
);

add_action(
	'http_api_debug',
	function ( $response, $context, $class, $args, $url ) {
		$key  = md5( (string) $url );
		$t0   = isset( $GLOBALS['kozlov_timing']['http_t0'][ $key ] ) ? $GLOBALS['kozlov_timing']['http_t0'][ $key ] : microtime( true );
		$code = is_wp_error( $response ) ? 'ERROR ' . $response->get_error_message() : (string) wp_remote_retrieve_response_code( $response );
		$GLOBALS['kozlov_timing']['http'][] = sprintf( '%.2fs  %s  %s', microtime( true ) - $t0, substr( $code, 0, 80 ), substr( (string) $url, 0, 150 ) );
	},
	10,
	5
);

add_filter(
	'pre_recurse_dirsize',
	function ( $pre, $directory = '' ) {
		$GLOBALS['kozlov_timing']['dirs']++;
		if ( '' === $GLOBALS['kozlov_timing']['dir_first'] ) {
			$GLOBALS['kozlov_timing']['dir_first'] = (string) $directory;
		}
		return $pre;
	},
	1,
	2
);

add_action(
	'shutdown',
	function () {
		$cron = function_exists( 'wp_doing_cron' ) && wp_doing_cron();
		$ajax = function_exists( 'wp_doing_ajax' ) && wp_doing_ajax();
		$rest = defined( 'REST_REQUEST' ) && REST_REQUEST;
		if ( ! ( is_admin() || $ajax || $rest || $cron ) ) {
			return;
		}
		if ( ! $cron && ! is_user_logged_in() ) {
			return;
		}
		global $wpdb;
		$t     = $GLOBALS['kozlov_timing'];
		$start = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime( true );
		$uri   = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		$act   = ( isset( $_REQUEST['action'] ) && is_scalar( $_REQUEST['action'] ) ) ? ' action=' . sanitize_key( (string) $_REQUEST['action'] ) : '';
		$kind  = $cron ? '[cron] ' : ( $ajax ? '[ajax] ' : ( $rest ? '[rest] ' : '' ) );
		$line  = sprintf(
			"[%s UTC] %6.2fs  mem %5.1f MB  db %4d  dirs %d  %s%s%s\n",
			gmdate( 'Y-m-d H:i:s' ),
			microtime( true ) - $start,
			memory_get_peak_usage( true ) / 1048576,
			isset( $wpdb->num_queries ) ? (int) $wpdb->num_queries : -1,
			(int) $t['dirs'],
			$kind,
			substr( $uri, 0, 200 ),
			$act
		);
		if ( $t['dirs'] > 0 ) {
			$line .= '            dirsize walk started at ' . $t['dir_first'] . "\n";
		}
		foreach ( $t['http'] as $h ) {
			$line .= '            http ' . $h . "\n";
		}
		@file_put_contents( dirname( ABSPATH ) . '/admin-timing.log', $line, FILE_APPEND | LOCK_EX );
	},
	PHP_INT_MAX
);
