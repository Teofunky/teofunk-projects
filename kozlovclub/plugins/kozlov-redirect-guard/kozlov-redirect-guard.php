<?php
/**
 * Plugin Name:       Kozlov Redirect Guard
 * Description:       Страховка и диагностика: если какой-то плагин обнуляет адрес в фильтре wp_redirect (из-за этого /event/slug без слеша отдаёт пустую страницу вместо перехода на /event/slug/), возвращает исходный адрес, и WordPress делает редирект как положено. Виновника записывает в wp-content/uploads/kozlov-redirect-guard.log.
 * Version:           1.0.0
 * Author:            Kozlov Club
 * License:           GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function kozlov_rg_log( $text ) {
	$file = WP_CONTENT_DIR . '/uploads/kozlov-redirect-guard.log';
	if ( file_exists( $file ) && filesize( $file ) > 512 * 1024 ) {
		return; // не раздувать лог
	}
	@file_put_contents( $file, gmdate( 'Y-m-d H:i:s' ) . ' UTC  ' . ( isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '' ) . "\n" . $text . "\n\n", FILE_APPEND | LOCK_EX );
}

function kozlov_rg_describe( $f ) {
	try {
		if ( is_string( $f ) ) {
			$rf = new ReflectionFunction( $f );
			$d  = $f;
		} elseif ( is_array( $f ) ) {
			$rf = new ReflectionMethod( $f[0], $f[1] );
			$d  = ( is_object( $f[0] ) ? get_class( $f[0] ) : $f[0] ) . '::' . $f[1];
		} elseif ( $f instanceof Closure ) {
			$rf = new ReflectionFunction( $f );
			$d  = 'closure';
		} else {
			return 'callable';
		}
		return $d . '  @ ' . str_replace( ABSPATH, '', (string) $rf->getFileName() ) . ':' . $rf->getStartLine();
	} catch ( \Throwable $e ) {
		return 'unknown';
	}
}

// 1) Самым первым запоминаем адрес, с которым WordPress вызвал wp_redirect.
add_filter( 'wp_redirect', function ( $location ) {
	$GLOBALS['kozlov_rg_orig'] = $location;
	if ( ! $location ) {
		// Сам вызов уже с пустым адресом: пишем, кто вызвал.
		$bt = array();
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 12 ) as $fr ) {
			if ( isset( $fr['file'] ) ) {
				$bt[] = str_replace( ABSPATH, '', $fr['file'] ) . ':' . ( isset( $fr['line'] ) ? $fr['line'] : '?' ) . '  ' . ( isset( $fr['function'] ) ? $fr['function'] : '' );
			}
		}
		kozlov_rg_log( "wp_redirect вызван с ПУСТЫМ адресом. Кто вызвал:\n  " . implode( "\n  ", $bt ) );
	}
	return $location;
}, -1000000 );

// 2) Самым последним проверяем: если адрес обнулили по дороге, восстанавливаем и записываем всех участников.
add_filter( 'wp_redirect', function ( $location ) {
	if ( $location ) {
		return $location;
	}
	$orig = isset( $GLOBALS['kozlov_rg_orig'] ) ? $GLOBALS['kozlov_rg_orig'] : '';
	if ( ! $orig ) {
		return $location;
	}
	$lines = array();
	if ( isset( $GLOBALS['wp_filter']['wp_redirect'] ) && is_object( $GLOBALS['wp_filter']['wp_redirect'] ) ) {
		foreach ( $GLOBALS['wp_filter']['wp_redirect']->callbacks as $prio => $cbs ) {
			foreach ( $cbs as $cb ) {
				$lines[] = str_pad( (string) $prio, 9, ' ', STR_PAD_LEFT ) . '  ' . kozlov_rg_describe( $cb['function'] );
			}
		}
	}
	kozlov_rg_log( "Адрес редиректа '$orig' был обнулён одним из фильтров wp_redirect. Восстановлен.\nФильтры wp_redirect по порядку (виновник между -1000000 и 1000000):\n  " . implode( "\n  ", $lines ) );
	return $orig;
}, 1000000 );
