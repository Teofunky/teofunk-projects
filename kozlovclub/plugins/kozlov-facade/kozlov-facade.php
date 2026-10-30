<?php
/**
 * Plugin Name: Kozlov Living Facade
 * Description: Живой фасад клуба вместо слайдера на главной, только на телефонах. Свой список слайдов (мероприятия, картинки со ссылками, Джазета). Режимы: выключен, только редакторы, все посетители с телефонов. Десктоп не меняется.
 * Version: 1.4.5
 * Author: Kozlov Club
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * Text Domain: kozlov-facade
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'KZF_VER', '1.4.5' );
define( 'KZF_FILE', __FILE__ );
define( 'KZF_DIR', plugin_dir_path( __FILE__ ) );
define( 'KZF_URL', plugin_dir_url( __FILE__ ) );

require_once KZF_DIR . 'includes/data.php';
require_once KZF_DIR . 'includes/front.php';
if ( is_admin() ) {
	require_once KZF_DIR . 'includes/admin.php';
}

register_activation_hook( __FILE__, 'kzf_activate' );
register_deactivation_hook( __FILE__, 'kzf_deactivate' );

/**
 * First install: editors-only mode and a starter set of slides taken from the current home slider.
 * Existing settings are never overwritten.
 */
function kzf_activate() {
	if ( false === get_option( 'kzf_settings', false ) ) {
		add_option( 'kzf_settings', kzf_default_settings(), '', 'no' );
	}
	if ( false === get_option( 'kzf_slides', false ) ) {
		$home = home_url( '/' );
		add_option(
			'kzf_slides',
			array(
				array( 'type' => 'event', 'enabled' => 1, 'label' => 'Концерт', 'event_url' => $home . 'event/uragannyj-dzhaz-pavla-timofeeva-pristegnite-remni/' ),
				array( 'type' => 'event', 'enabled' => 1, 'label' => 'Концерт', 'event_url' => $home . 'event/spokanki-8/' ),
				array( 'type' => 'gazeta', 'enabled' => 1, 'label' => 'Джазета · свежий номер' ),
			),
			'',
			'no'
		);
	}
	if ( ! wp_next_scheduled( 'kzf_refresh_gazeta' ) ) {
		wp_schedule_event( time() + 60, 'hourly', 'kzf_refresh_gazeta' );
	}
	kzf_flush_display_cache();
}

/**
 * Updating by uploading a new zip does not run the activation hook. On the first request after an update:
 * drop cards cached by the old version, make sure the hourly Джазета job exists and runs soon.
 * Safety: a version change never goes straight to every visitor. If the facade was on for everyone,
 * it is switched to editors-only and the settings page says so; one click on «Сохранить» turns it back.
 */
add_action( 'plugins_loaded', 'kzf_maybe_upgrade' );
function kzf_maybe_upgrade() {
	try {
		if ( get_option( 'kzf_ver' ) === KZF_VER ) {
			return;
		}
		update_option( 'kzf_ver', KZF_VER, true );
		$set = kzf_settings();
		if ( 'all' === $set['mode'] ) {
			$set['mode'] = 'editors';
			update_option( 'kzf_settings', $set, false );
			update_option( 'kzf_notice', 'Фасад обновлён до версии ' . KZF_VER . ' и пока виден только редакторам. Проверьте главную на телефоне под своим логином, затем выберите «Все посетители с телефонов» и сохраните.', false );
		}
		kzf_flush_display_cache();
		kzf_purge_front_cache();
		if ( ! wp_next_scheduled( 'kzf_refresh_gazeta' ) ) {
			wp_schedule_event( time() + 30, 'hourly', 'kzf_refresh_gazeta' );
		} else {
			wp_schedule_single_event( time() + 30, 'kzf_refresh_gazeta' ); // fresh issue soon, in the background
		}
	} catch ( Throwable $e ) {
		return;
	}
}

function kzf_deactivate() {
	wp_clear_scheduled_hook( 'kzf_refresh_gazeta' );
	wp_clear_scheduled_hook( 'kzf_expire' );
	kzf_purge_front_cache();
	kzf_flush_display_cache();
}
