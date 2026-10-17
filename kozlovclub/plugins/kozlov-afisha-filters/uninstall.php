<?php
/**
 * Удаление плагина: стирается только его собственная запись настроек.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'kaf_settings' );
