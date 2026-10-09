<?php
/**
 * Удаление плагина: стираем только собственную настройку.
 *
 * @package Kozlov_Event_Schema
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'kozlov_event_schema_settings' );
