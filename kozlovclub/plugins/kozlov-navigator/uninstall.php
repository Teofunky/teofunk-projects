<?php
/**
 * Удаление плагина (не деактивация): убираем свою настройку, свою таблицу и задачу очистки.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
global $wpdb;
delete_option( 'knav_settings' );
delete_option( 'knav_db' );
wp_clear_scheduled_hook( 'knav_cleanup' );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}knav_events" ); // phpcs:ignore
