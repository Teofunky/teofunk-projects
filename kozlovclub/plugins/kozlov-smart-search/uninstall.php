<?php
/**
 * Удаление плагина: стираются только собственные данные (настройки и кэш индекса).
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'kss_settings' );
delete_transient( 'kss_index_v1' );
delete_transient( 'kss_texts_v1' );
delete_transient( 'kss_lock_v1' );
delete_transient( 'kss_dirty_v1' );
delete_transient( 'kss_fail_v1' );

// пустой файл блокировки во временной папке сервера
global $wpdb;
$kss_dir  = function_exists( 'get_temp_dir' ) ? get_temp_dir() : sys_get_temp_dir();
$kss_lock = rtrim( (string) $kss_dir, '/\\' ) . '/kss-' . substr( md5( ABSPATH . '|' . $wpdb->prefix . '|' . ( defined( 'DB_NAME' ) ? DB_NAME : '' ) ), 0, 16 ) . '.lock';
if ( is_file( $kss_lock ) ) {
	@unlink( $kss_lock ); // phpcs:ignore
}
