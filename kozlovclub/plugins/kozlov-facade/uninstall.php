<?php
/**
 * Removing the plugin removes its two options and cached Джазета data. Nothing else was created.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
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
