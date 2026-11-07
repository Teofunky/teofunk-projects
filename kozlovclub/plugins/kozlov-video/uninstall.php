<?php
/**
 * Удаление плагина: своя таблица, настройки и задание по расписанию. Страницы сайта не трогаются.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'kzv_videos' ); // phpcs:ignore WordPress.DB
foreach ( array( 'kzv_settings', 'kzv_state', 'kzv_db_version' ) as $kzv_opt ) {
	delete_option( $kzv_opt );
}
delete_transient( 'kzv_live_state' );
delete_transient( 'kzv_sync_lock' );
wp_clear_scheduled_hook( 'kzv_sync' );
