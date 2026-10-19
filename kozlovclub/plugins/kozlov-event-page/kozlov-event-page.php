<?php
/**
 * Plugin Name:       Kozlov Event Page
 * Description:       Новая страница концерта. Включается для анонса, когда редактор загрузил заглавное фото. Тема, данные анонсов и афиша не меняются: плагин только перестраивает уже готовую страницу.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.2
 * Author:            Kozlov Club
 * Text Domain:       kozlov-event-page
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'KEP_VERSION', '1.0.0' );
define( 'KEP_FILE', __FILE__ );
define( 'KEP_DIR', plugin_dir_path( __FILE__ ) );
define( 'KEP_URL', plugin_dir_url( __FILE__ ) );
define( 'KEP_POST_TYPE', 'ai1ec_event' );
define( 'KEP_META_HERO', '_kep_hero_id' );
define( 'KEP_META_PROGRAM', '_kep_program' );

require_once KEP_DIR . 'includes/class-kep-settings.php';
require_once KEP_DIR . 'includes/class-kep-data.php';
require_once KEP_DIR . 'includes/class-kep-parser.php';
require_once KEP_DIR . 'includes/class-kep-render.php';
require_once KEP_DIR . 'includes/class-kep-front.php';

KEP_Front::init();

if ( is_admin() ) {
	require_once KEP_DIR . 'includes/class-kep-admin.php';
	KEP_Admin::init();
}

register_activation_hook( __FILE__, array( 'KEP_Settings', 'activate' ) );
