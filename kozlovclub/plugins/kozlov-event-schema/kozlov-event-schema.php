<?php
/**
 * Plugin Name:       Kozlov Event Schema
 * Description:       Разметка schema.org MusicEvent (JSON-LD) для страниц концертов. Читает данные анонсов, тему и афишу не меняет; хранит только свою настройку и необязательный статус «отменён/перенесён». Включается в «Настройки → Разметка концертов».
 * Version:           1.1.0
 * Requires at least: 5.3
 * Requires PHP:      7.2
 * Author:            Kozlov Club
 * License:           GPL-2.0-or-later
 * Text Domain:       kozlov-event-schema
 *
 * @package Kozlov_Event_Schema
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Защита от двойной загрузки (две копии плагина в разных папках).
if ( defined( 'KOZLOV_EVENT_SCHEMA_VERSION' ) ) {
	return;
}

define( 'KOZLOV_EVENT_SCHEMA_VERSION', '1.1.0' );
define( 'KOZLOV_EVENT_SCHEMA_OPTION', 'kozlov_event_schema_settings' );
define( 'KOZLOV_EVENT_SCHEMA_DIR', plugin_dir_path( __FILE__ ) );
define( 'KOZLOV_EVENT_SCHEMA_FILE', __FILE__ );

require_once KOZLOV_EVENT_SCHEMA_DIR . 'includes/class-kozlov-event-schema-builder.php';

if ( is_admin() ) {
	require_once KOZLOV_EVENT_SCHEMA_DIR . 'includes/class-kozlov-event-schema-admin.php';
	require_once KOZLOV_EVENT_SCHEMA_DIR . 'includes/class-kozlov-event-schema-status.php';
	Kozlov_Event_Schema_Admin::init();
	Kozlov_Event_Schema_Status::init();
}

register_activation_hook( __FILE__, 'kozlov_event_schema_activate' );

/**
 * При первой активации разметка ВЫКЛЮЧЕНА: сначала проверка в админке, потом включение.
 */
function kozlov_event_schema_activate() {
	if ( false === get_option( KOZLOV_EVENT_SCHEMA_OPTION ) ) {
		add_option( KOZLOV_EVENT_SCHEMA_OPTION, array( 'enabled' => 0 ) );
	}
}

/**
 * Включена ли разметка на сайте.
 *
 * @return bool
 */
function kozlov_event_schema_is_enabled() {
	$settings = get_option( KOZLOV_EVENT_SCHEMA_OPTION );
	return is_array( $settings ) && ! empty( $settings['enabled'] );
}

add_action( 'wp_head', 'kozlov_event_schema_output', 99 );

/**
 * Выводит один блок JSON-LD в <head> страницы концерта.
 * Любая ошибка внутри: молча ничего не выводим, страница работает как раньше.
 */
function kozlov_event_schema_output() {
	try {
		if ( ! kozlov_event_schema_is_enabled() ) {
			return;
		}
		if ( is_admin() || is_feed() || is_preview() ) {
			return;
		}
		if ( ! is_singular( Kozlov_Event_Schema_Builder::POST_TYPE ) ) {
			return;
		}
		$post = get_queried_object();
		if ( ! ( $post instanceof WP_Post ) ) {
			return;
		}

		$result = Kozlov_Event_Schema_Builder::build_for_front( $post );
		if ( empty( $result['data'] ) ) {
			return;
		}

		$json = Kozlov_Event_Schema_Builder::to_json( $result['data'] );
		if ( '' === $json ) {
			return;
		}

		// JSON закодирован с JSON_HEX_TAG, поэтому «</script>» внутри текста не может закрыть тег.
		echo "\n<script type=\"application/ld+json\" class=\"kozlov-event-schema\">" . $json . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	} catch ( Throwable $e ) {
		return;
	}
}
