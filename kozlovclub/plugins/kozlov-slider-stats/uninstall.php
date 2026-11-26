<?php
/**
 * Removing the plugin removes its only option. Theme files, tables and posts were never touched.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
delete_option( 'kzsl_settings' );
