<?php
/**
 * Plugin Name:       Kozlov Redirect Guard
 * Description:       Диагностика и страховка для пустого ответа на адресах без слеша (/event/slug). Для таких запросов записывает, что происходит внутри WordPress (заголовки, фильтры, кто завершил запрос), в wp-content/uploads/kozlov-redirect-guard.txt. Если какой-то плагин обнуляет адрес в фильтре wp_redirect, восстанавливает его.
 * Version:           1.1.0
 * Author:            Kozlov Club
 * License:           GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function kozlov_rg_file() {
	return WP_CONTENT_DIR . '/uploads/kozlov-redirect-guard.txt';
}

function kozlov_rg_write( $text ) {
	$file = kozlov_rg_file();
	if ( file_exists( $file ) && filesize( $file ) > 1024 * 1024 ) {
		return;
	}
	$ok = @file_put_contents( $file, $text . "\n", FILE_APPEND | LOCK_EX );
	if ( false === $ok ) {
		error_log( 'kozlov-redirect-guard: ' . str_replace( "\n", ' | ', $text ) );
	}
}

function kozlov_rg_describe( $f ) {
	try {
		if ( is_string( $f ) ) {
			$d  = $f;
			$rf = function_exists( $f ) ? new ReflectionFunction( $f ) : null;
		} elseif ( is_array( $f ) ) {
			$d  = ( is_object( $f[0] ) ? get_class( $f[0] ) : $f[0] ) . '::' . $f[1];
			$rf = new ReflectionMethod( $f[0], $f[1] );
		} elseif ( $f instanceof Closure ) {
			$d  = 'closure';
			$rf = new ReflectionFunction( $f );
		} else {
			return 'callable';
		}
		if ( $rf ) {
			$d .= '  @ ' . str_replace( ABSPATH, '', (string) $rf->getFileName() ) . ':' . $rf->getStartLine();
		}
		return $d;
	} catch ( \Throwable $e ) {
		return 'unknown';
	}
}

function kozlov_rg_hook_list( $hook ) {
	$lines = array();
	if ( isset( $GLOBALS['wp_filter'][ $hook ] ) && is_object( $GLOBALS['wp_filter'][ $hook ] ) ) {
		foreach ( $GLOBALS['wp_filter'][ $hook ]->callbacks as $prio => $cbs ) {
			foreach ( $cbs as $cb ) {
				$lines[] = str_pad( (string) $prio, 9, ' ', STR_PAD_LEFT ) . '  ' . kozlov_rg_describe( $cb['function'] );
			}
		}
	}
	return $lines;
}

/* ---------- страховка wp_redirect (как в 1.0) ---------- */

add_filter( 'wp_redirect', function ( $location ) {
	$GLOBALS['kozlov_rg_orig'] = $location;
	kozlov_rg_mark( 'wp_redirect вызван с адресом: ' . var_export( $location, true ) );
	return $location;
}, -1000000 );

add_filter( 'wp_redirect', function ( $location ) {
	if ( $location ) {
		kozlov_rg_mark( 'wp_redirect после всех фильтров: ' . $location );
		return $location;
	}
	$orig = isset( $GLOBALS['kozlov_rg_orig'] ) ? $GLOBALS['kozlov_rg_orig'] : '';
	kozlov_rg_mark( "wp_redirect: адрес обнулён фильтром, восстанавливаю '$orig'. Фильтры wp_redirect:\n    " . implode( "\n    ", kozlov_rg_hook_list( 'wp_redirect' ) ) );
	return $orig ? $orig : $location;
}, 1000000 );

/* ---------- диагностика запросов без слеша ---------- */

function kozlov_rg_mark( $label ) {
	if ( empty( $GLOBALS['kozlov_rg_diag_on'] ) ) {
		return;
	}
	$f = '';
	$l = 0;
	$sent = headers_sent( $f, $l );
	$GLOBALS['kozlov_rg_diag'][] = sprintf(
		"[%s] %s\n    headers_sent=%d%s  http_code=%s  ob_level=%d  ob_handlers=[%s]\n    headers=[%s]",
		date( 'H:i:s' ),
		$label,
		$sent ? 1 : 0,
		$sent ? ' (вывод начался в ' . str_replace( ABSPATH, '', $f ) . ':' . $l . ')' : '',
		http_response_code(),
		ob_get_level(),
		implode( ', ', ob_list_handlers() ),
		implode( '; ', headers_list() )
	);
}

$kozlov_rg_uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
$kozlov_rg_path = (string) parse_url( $kozlov_rg_uri, PHP_URL_PATH );
$kozlov_rg_diag = '' !== $kozlov_rg_path
	&& '/' !== $kozlov_rg_path
	&& '/' !== substr( $kozlov_rg_path, -1 )
	&& ! preg_match( '#\.[a-z0-9]{1,5}$#i', $kozlov_rg_path )
	&& 0 !== strpos( $kozlov_rg_path, '/wp-admin' )
	&& 0 !== strpos( $kozlov_rg_path, '/wp-json' )
	&& ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'GET' === $_SERVER['REQUEST_METHOD'] );

if ( $kozlov_rg_diag ) {
	$GLOBALS['kozlov_rg_diag_on'] = true;
	$GLOBALS['kozlov_rg_diag']    = array();
	kozlov_rg_mark( 'плагин загружен, запрос ' . $kozlov_rg_uri );

	add_action( 'plugins_loaded', function () { kozlov_rg_mark( 'plugins_loaded' ); }, 1000000 );
	add_action( 'init', function () { kozlov_rg_mark( 'init' ); }, 1000000 );
	add_action( 'send_headers', function () { kozlov_rg_mark( 'send_headers (WP отправил свои заголовки)' ); }, 1000000 );
	add_action( 'wp', function () { kozlov_rg_mark( 'wp (запрос разобран)' ); }, -1000000 );
	add_action( 'template_redirect', function () {
		kozlov_rg_mark( "template_redirect НАЧАЛО. Обработчики template_redirect по порядку:\n    " . implode( "\n    ", kozlov_rg_hook_list( 'template_redirect' ) ) );
	}, -1000000 );
	add_action( 'template_redirect', function () { kozlov_rg_mark( 'template_redirect КОНЕЦ (никто не завершил запрос, дальше шаблон)' ); }, 1000000 );
	add_filter( 'redirect_canonical', function ( $url, $req ) {
		kozlov_rg_mark( 'redirect_canonical вычислил: ' . var_export( $url, true ) . ' для ' . $req );
		return $url;
	}, -1000000, 2 );
	add_filter( 'redirect_canonical', function ( $url ) {
		kozlov_rg_mark( "redirect_canonical после фильтров: " . var_export( $url, true ) . "\n    Фильтры redirect_canonical:\n    " . implode( "\n    ", kozlov_rg_hook_list( 'redirect_canonical' ) ) );
		return $url;
	}, 1000000 );
	add_action( 'template_include', function ( $t ) { kozlov_rg_mark( 'template_include: ' . str_replace( ABSPATH, '', (string) $t ) ); return $t; }, 1000000 );

	register_shutdown_function( function () {
		kozlov_rg_mark( 'SHUTDOWN (конец запроса)' );
		$e = error_get_last();
		$bt = array();
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 15 ) as $fr ) {
			if ( isset( $fr['file'] ) ) {
				$bt[] = str_replace( ABSPATH, '', $fr['file'] ) . ':' . ( isset( $fr['line'] ) ? $fr['line'] : '?' ) . ' ' . ( isset( $fr['function'] ) ? $fr['function'] : '' );
			}
		}
		$text  = "==================== " . gmdate( 'Y-m-d H:i:s' ) . " UTC  " . ( isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '' ) . "\n";
		$text .= implode( "\n", $GLOBALS['kozlov_rg_diag'] ) . "\n";
		$text .= 'Последняя ошибка PHP: ' . ( $e ? ( $e['message'] . ' в ' . str_replace( ABSPATH, '', $e['file'] ) . ':' . $e['line'] ) : 'нет' ) . "\n";
		$text .= 'Активные плагины: ' . implode( ', ', (array) get_option( 'active_plugins' ) ) . "\n";
		kozlov_rg_write( $text );
	} );
}
