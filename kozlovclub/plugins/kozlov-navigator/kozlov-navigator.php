<?php
/**
 * Plugin Name: Kozlov Navigator
 * Description: Джаз-навигатор: квиз подбирает посетителю три концерта клуба по настроению, дате, звучанию и бюджету. Данные берёт из Kozlov Smart Search, работает в изолированном Shadow DOM, ведёт статистику.
 * Version: 1.0.5
 * Author: Клуб Алексея Козлова
 * Requires at least: 6.3
 * Requires PHP: 7.4
 * Text Domain: kozlov-navigator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'KNAV_VERSION', '1.0.5' );
define( 'KNAV_DB_VERSION', '1' );
define( 'KNAV_FILE', __FILE__ );
define( 'KNAV_DIR', plugin_dir_path( __FILE__ ) );
define( 'KNAV_URL', plugin_dir_url( __FILE__ ) );

require_once KNAV_DIR . 'includes/class-knav-settings.php';
require_once KNAV_DIR . 'includes/class-knav-stats.php';
require_once KNAV_DIR . 'includes/class-knav-front.php';
require_once KNAV_DIR . 'includes/class-knav-admin.php';

register_activation_hook( __FILE__, array( 'KNAV_Stats', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'KNAV_Stats', 'deactivate' ) );

add_action( 'plugins_loaded', function () {
	KNAV_Stats::init();
	KNAV_Front::init();
	if ( is_admin() ) {
		KNAV_Admin::init();
	}
} );

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
	array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=kozlov-navigator' ) ) . '">Настройки</a>' );
	return $links;
} );
