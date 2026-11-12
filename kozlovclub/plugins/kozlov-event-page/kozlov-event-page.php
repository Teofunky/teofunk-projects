<?php
/**
 * Plugin Name:       Kozlov Event Page
 * Description:       Новая страница концерта. Включается для анонса, когда редактор загрузил заглавное фото. Тема, данные анонсов и афиша не меняются: плагин только перестраивает уже готовую страницу.
 * Version:           1.0.16
 * Requires at least: 6.0
 * Requires PHP:      7.2
 * Author:            Kozlov Club
 * Text Domain:       kozlov-event-page
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'KEP_VERSION', '1.0.16' );
define( 'KEP_FILE', __FILE__ );
define( 'KEP_DIR', plugin_dir_path( __FILE__ ) );
define( 'KEP_URL', plugin_dir_url( __FILE__ ) );
define( 'KEP_POST_TYPE', 'ai1ec_event' );
define( 'KEP_META_HERO', '_kep_hero_id' );
define( 'KEP_META_PROGRAM', '_kep_program' );
define( 'KEP_META_LEAD', '_kep_lead' );
define( 'KEP_META_NOTES', '_kep_notes' );
define( 'KEP_META_KEEP', '_kep_keep_text' );

/**
 * Нужные расширения PHP. Без них плагин ничего не делает и показывает уведомление.
 *
 * @return array
 */
function kep_missing_requirements() {
	$missing = array();
	if ( ! class_exists( 'DOMDocument' ) ) {
		$missing[] = 'dom';
	}
	if ( ! function_exists( 'mb_strtolower' ) ) {
		$missing[] = 'mbstring';
	}
	return $missing;
}

if ( kep_missing_requirements() ) {
	add_action(
		'admin_notices',
		function () {
			if ( current_user_can( 'activate_plugins' ) ) {
				echo '<div class="notice notice-error"><p>Kozlov Event Page не работает: на сервере нет расширений PHP ' . esc_html( implode( ', ', kep_missing_requirements() ) ) . '. Попросите хостинг включить их. Сайт показывает старые страницы концертов, ничего не сломано.</p></div>';
			}
		}
	);
	return;
}

require_once KEP_DIR . 'includes/class-kep-settings.php';
require_once KEP_DIR . 'includes/class-kep-data.php';
require_once KEP_DIR . 'includes/class-kep-parser.php';
require_once KEP_DIR . 'includes/class-kep-render.php';
require_once KEP_DIR . 'includes/class-kep-front.php';
require_once KEP_DIR . 'includes/class-kep-cards.php';
require_once KEP_DIR . 'includes/class-kep-live.php';

add_action( 'plugins_loaded', array( 'KEP_Settings', 'maybe_upgrade' ) );
KEP_Front::init();
KEP_Cards::init();
KEP_Live::init();

if ( is_admin() ) {
	require_once KEP_DIR . 'includes/class-kep-admin.php';
	KEP_Admin::init();
}

register_activation_hook( __FILE__, array( 'KEP_Settings', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'KEP_Settings', 'deactivate' ) );
