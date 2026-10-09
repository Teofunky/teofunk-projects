<?php
/**
 * Удаление плагина: стираем только свой временный кэш списков.
 * Страницы, которые вы создали, не трогаем (в них останется текст шорткода).
 *
 * @package Kozlov_Afisha_Pages
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_kozlov_afisha_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_kozlov_afisha_' ) . '%'
	)
);

delete_option( 'kozlov_afisha_cache_ver' );
