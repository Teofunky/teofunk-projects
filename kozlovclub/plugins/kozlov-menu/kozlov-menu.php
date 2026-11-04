<?php
/**
 * Plugin Name:       Kozlov Menu
 * Description:       Меню ресторана клуба: разделы (кухня, бранч, бар), блюда с фото, вариантами объёма и ценами. Редактор в админке, страница /menu/ с поиском и навигацией по разделам.
 * Version:           1.0.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Kozlov Club
 * Text Domain:       kozlov-menu
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'KZM_VER', '1.0.1' );
define( 'KZM_FILE', __FILE__ );
define( 'KZM_DIR', plugin_dir_path( __FILE__ ) );
define( 'KZM_URL', plugin_dir_url( __FILE__ ) );
define( 'KZM_PT', 'kz_menu' );

require_once KZM_DIR . 'includes/class-kzm-data.php';
require_once KZM_DIR . 'includes/class-kzm-front.php';
if ( is_admin() ) {
	require_once KZM_DIR . 'includes/class-kzm-admin.php';
	KZM_Admin::init();
}
KZM_Data::init();
KZM_Front::init();
