<?php
/**
 * Plugin Name:       Kozlov Video
 * Description:       Kozlov TV: одна страница /tv/ для эфира и записей концертов главной сцены через плеер VK Видео. Программа эфиров, записи из истории StreamVi с привязкой к афише, поиск и жанры, мини-плеер. Тему и афишу не меняет; выключение возвращает страницы «Live» и «Видеоархив» как были.
 * Version:           1.1.1
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * Author:            Kozlov Club
 * License:           GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

define( 'KZV_VERSION', '1.1.1' );
define( 'KZV_FILE', __FILE__ );
define( 'KZV_DIR', plugin_dir_path( __FILE__ ) );
define( 'KZV_URL', plugin_dir_url( __FILE__ ) );

/**
 * Ошибка плагина уходит в журнал PHP и никогда не ломает сайт.
 *
 * @param \Throwable|string $e Исключение или текст.
 */
function kzv_log_exception( $e ) {
	$msg = ( $e instanceof \Throwable ) ? $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() : (string) $e;
	error_log( '[Kozlov Video] ' . $msg ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	if ( class_exists( 'KZV_Settings' ) ) {
		try {
			KZV_Settings::note_error( $msg );
		} catch ( \Throwable $ignored ) {
			unset( $ignored );
		}
	}
}

$kzv_loaded = true;
try {
	foreach ( array( 'settings', 'util', 'store', 'streamvi', 'concerts', 'sync', 'live', 'render', 'tv', 'front', 'rest', 'admin' ) as $kzv_part ) {
		require_once KZV_DIR . 'includes/class-kzv-' . $kzv_part . '.php';
	}
} catch ( \Throwable $e ) {
	$kzv_loaded = false;
	kzv_log_exception( $e );
}

if ( $kzv_loaded ) {
	register_activation_hook( __FILE__, array( 'KZV_Sync', 'activate' ) );
	register_deactivation_hook( __FILE__, array( 'KZV_Sync', 'deactivate' ) );

	add_action(
		'plugins_loaded',
		function () {
			try {
				KZV_Store::maybe_upgrade();
				KZV_Sync::init();
				KZV_Front::init();
				KZV_Rest::init();
				if ( is_admin() ) {
					KZV_Admin::init();
				}
			} catch ( \Throwable $e ) {
				kzv_log_exception( $e );
			}
		},
		20
	);
}
