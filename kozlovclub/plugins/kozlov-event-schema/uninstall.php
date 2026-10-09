<?php
/**
 * Удаление плагина: стираем только собственные данные (настройку и поля статуса).
 *
 * @package Kozlov_Event_Schema
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'kozlov_event_schema_settings' );
delete_post_meta_by_key( '_kozlov_event_status' );
delete_post_meta_by_key( '_kozlov_event_previous_start' );
