<?php
/**
 * Removing the plugin removes everything it created: three options, one cache entry, one cron job.
 * Theme files, tables and posts were never touched.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// 1.0 kept the Джазета issue in one transient per URL; clean those up as well.
$kzf_slides = get_option( 'kzf_slides', array() );
if ( is_array( $kzf_slides ) ) {
	foreach ( $kzf_slides as $kzf_s ) {
		if ( is_array( $kzf_s ) && isset( $kzf_s['type'] ) && 'gazeta' === $kzf_s['type'] ) {
			$kzf_url = ! empty( $kzf_s['link'] ) ? $kzf_s['link'] : home_url( '/gazeta/' );
			delete_transient( 'kzf_gazeta_' . md5( $kzf_url ) );
		}
	}
}

delete_option( 'kzf_settings' );
delete_option( 'kzf_slides' );
delete_option( 'kzf_gazeta_cache' );
delete_transient( 'kzf_display' );
wp_clear_scheduled_hook( 'kzf_refresh_gazeta' );
