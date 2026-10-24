<?php
/**
 * Plugin Name:       Kozlov New Year
 * Description:       Новогодняя страница «Новый год в Клубе Алексея Козлова» с редактором текстов, концертов, цен и фото. Изолирована от темы: не меняет файлы темы, таблицы, афишу и другие страницы.
 * Version:           1.0.1
 * Requires at least: 5.6
 * Requires PHP:      7.2
 * Author:            Клуб Алексея Козлова
 * License:           GPL-2.0-or-later
 * Text Domain:       kozlov-new-year
 *
 * @package Kozlov_New_Year
 */

defined( 'ABSPATH' ) || exit;

define( 'KNY_VERSION', '1.0.1' );
define( 'KNY_FILE', __FILE__ );
define( 'KNY_DIR', plugin_dir_path( __FILE__ ) );
define( 'KNY_URL', plugin_dir_url( __FILE__ ) );

require_once KNY_DIR . 'includes/defaults.php';
require_once KNY_DIR . 'includes/schema.php';
require_once KNY_DIR . 'includes/data.php';
require_once KNY_DIR . 'includes/render.php';

if ( is_admin() ) {
	require_once KNY_DIR . 'includes/admin.php';
}

register_activation_hook( __FILE__, 'kny_activate' );

/**
 * Активация: создаёт черновик страницы (если её ещё нет) и ищет счётчик Метрики.
 * Ничего не публикует и ничего на сайте не меняет.
 */
function kny_activate() {
	$s = kny_get_settings();
	if ( empty( $s['page_id'] ) || ! get_post( (int) $s['page_id'] ) ) {
		$s['page_id'] = kny_create_page();
	}
	if ( '' === (string) $s['metrika_id'] ) {
		$s['metrika_id'] = kny_detect_metrika();
	}
	kny_save_settings( $s );
}
