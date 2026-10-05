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
