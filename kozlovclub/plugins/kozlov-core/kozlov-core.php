<?php
/**
 * Plugin Name: Kozlov Core
 * Description: Модель данных сайта Клуба Алексея Козлова: тип записи «Концерт», сцены, жанры, поля, REST и функции для темы и плагинов.
 * Version: 1.1.1
 * Author: Kozlov Club
 * Requires PHP: 8.1
 * Requires at least: 6.5
 * Text Domain: kozlov-core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'KC_VERSION', '1.1.1' );
define( 'KC_FILE', __FILE__ );
define( 'KC_DIR', plugin_dir_path( __FILE__ ) );
define( 'KC_URL', plugin_dir_url( __FILE__ ) );
define( 'KC_POST_TYPE', 'concert' );
define( 'KC_TAX_STAGE', 'kc_stage' );
define( 'KC_TAX_GENRE', 'kc_genre' );
define( 'KC_TAX_TAG', 'kc_tag' );

require_once KC_DIR . 'includes/functions.php';
require_once KC_DIR . 'includes/class-kc-post-type.php';
require_once KC_DIR . 'includes/class-kc-meta.php';
require_once KC_DIR . 'includes/class-kc-query.php';
require_once KC_DIR . 'includes/class-kc-rest.php';
require_once KC_DIR . 'includes/class-kc-admin.php';

add_action( 'plugins_loaded', function () {
	KC_Post_Type::init();
	KC_Meta::init();
	KC_Query::init();
	KC_Rest::init();
	if ( is_admin() ) {
		KC_Admin::init();
	}
} );

register_activation_hook( __FILE__, function () {
	KC_Post_Type::register();
	KC_Post_Type::seed_terms();
	flush_rewrite_rules();
	update_option( 'kc_version', KC_VERSION );
} );

register_deactivation_hook( __FILE__, function () {
	flush_rewrite_rules();
} );
