<?php
/**
 * Журнал плагина: последние 300 записей в одной опции (не грузится на каждой странице).
 */

defined( 'ABSPATH' ) || exit;

final class KZSV_Log {

	const OPT = 'kzsv_log';
	const MAX = 300;

	/**
	 * @param string $level info | ok | warn | error | test
	 * @param string $msg   Текст для человека.
	 */
	public static function add( $level, $msg ) {
		$list   = self::all();
		$list[] = array( time(), (string) $level, (string) $msg );
		if ( count( $list ) > self::MAX ) {
			$list = array_slice( $list, -self::MAX );
		}
		update_option( self::OPT, $list, false );
		if ( 'error' === $level ) {
			error_log( '[Kozlov StreamVi] ' . $msg ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	public static function all() {
		wp_cache_delete( self::OPT, 'options' );
		$list = get_option( self::OPT, array() );
		return is_array( $list ) ? $list : array();
	}

	public static function clear() {
		update_option( self::OPT, array(), false );
	}
}
