<?php
/**
 * Plugin Name:       Kozlov StreamVi
 * Description:       Переименовывает трансляцию StreamVi под концерты главной сцены: название концерта и первый абзац анонса. За час до начала или сразу после окончания предыдущего, не трогая идущий эфир. Сайт, тему и афишу не меняет.
 * Version:           1.1.0
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * Author:            Kozlov Club
 * License:           GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

define( 'KZSV_VERSION', '1.1.0' );
define( 'KZSV_FILE', __FILE__ );
define( 'KZSV_DIR', plugin_dir_path( __FILE__ ) );
define( 'KZSV_URL', plugin_dir_url( __FILE__ ) );

/**
 * Ошибка плагина уходит в журнал PHP и никогда не ломает сайт.
 *
 * @param \Throwable|string $e Исключение или текст.
 */
function kzsv_log_exception( $e ) {
	$msg = ( $e instanceof \Throwable ) ? $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() : (string) $e;
	error_log( '[Kozlov StreamVi] ' . $msg ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	if ( class_exists( 'KZSV_Log' ) ) {
		try {
			KZSV_Log::add( 'error', 'Внутренняя ошибка плагина: ' . $msg );
		} catch ( \Throwable $ignored ) {
			unset( $ignored );
		}
	}
}

$kzsv_loaded = true;
try {
	foreach ( array( 'settings', 'log', 'text', 'source', 'engine', 'api', 'notify', 'runner', 'cron', 'metabox', 'admin' ) as $kzsv_part ) {
		require_once KZSV_DIR . 'includes/class-kzsv-' . $kzsv_part . '.php';
	}
} catch ( \Throwable $e ) {
	$kzsv_loaded = false;
	kzsv_log_exception( $e );
}

if ( $kzsv_loaded ) {
	register_activation_hook( __FILE__, array( 'KZSV_Cron', 'activate' ) );
	register_deactivation_hook( __FILE__, array( 'KZSV_Cron', 'deactivate' ) );

	add_action(
		'plugins_loaded',
		function () {
			try {
				KZSV_Cron::init();
				KZSV_Metabox::init();
				if ( is_admin() ) {
					KZSV_Admin::init();
				}
			} catch ( \Throwable $e ) {
				kzsv_log_exception( $e );
			}
		}
	);
}
unset( $kzsv_loaded, $kzsv_part );
