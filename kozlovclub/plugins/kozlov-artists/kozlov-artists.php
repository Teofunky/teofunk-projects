<?php
/**
 * Plugin Name: Kozlov Artists
 * Description: Страницы артистов клуба: автоподбор концертов из афиши (All-in-One Event Calendar), SEO через Yoast, разметка MusicGroup/Event.
 * Version: 0.2.2
 * Author: Kozlov Club
 * Text Domain: kozlov-artists
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'KA_VERSION', '0.2.2' );
define( 'KA_DIR', plugin_dir_path( __FILE__ ) );
define( 'KA_URL', plugin_dir_url( __FILE__ ) );

require_once KA_DIR . 'includes/post-type.php';
require_once KA_DIR . 'includes/events.php';
require_once KA_DIR . 'includes/seo.php';
require_once KA_DIR . 'includes/template.php';
require_once KA_DIR . 'includes/import.php';
require_once KA_DIR . 'includes/event-block.php';

register_activation_hook( __FILE__, function () {
	ka_register_post_type();
	flush_rewrite_rules();
} );
register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );
