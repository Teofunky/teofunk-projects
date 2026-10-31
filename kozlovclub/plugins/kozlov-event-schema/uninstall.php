<?php
/**
 * Удаление плагина: стираем только собственные данные (настройки, поля статуса, исполнителей, title и description).
 *
 * @package Kozlov_Event_Schema
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'kozlov_event_schema_settings' );
delete_post_meta_by_key( '_kozlov_event_status' );
delete_post_meta_by_key( '_kozlov_event_previous_start' );
delete_post_meta_by_key( '_kozlov_event_performers' );
delete_post_meta_by_key( '_kozlov_event_seo_title' );
delete_post_meta_by_key( '_kozlov_event_seo_desc' );
