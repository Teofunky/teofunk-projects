<?php
/**
 * Plugin Name:       Kozlov Afisha Pages
 * Description:       Шорткод [kozlov_afisha] для посадочных страниц: афиша по жанру, сцене, «сегодня», «на выходных», «бесплатно» в дизайне карточек сайта. Анонсы, тему и главную не меняет; пишет только временный кэш готовых списков. Справка: «Настройки → Страницы афиши».
 * Version:           1.1.0
 * Requires at least: 5.3
 * Requires PHP:      7.2
 * Author:            Kozlov Club
 * License:           GPL-2.0-or-later
 * Text Domain:       kozlov-afisha-pages
 *
 * @package Kozlov_Afisha_Pages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'KOZLOV_AFISHA_PAGES_VERSION' ) ) {
	return;
}

define( 'KOZLOV_AFISHA_PAGES_VERSION', '1.1.0' );
define( 'KOZLOV_AFISHA_PAGES_DIR', plugin_dir_path( __FILE__ ) );
define( 'KOZLOV_AFISHA_PAGES_FILE', __FILE__ );

require_once KOZLOV_AFISHA_PAGES_DIR . 'includes/class-kozlov-afisha-data.php';
require_once KOZLOV_AFISHA_PAGES_DIR . 'includes/class-kozlov-afisha-render.php';

Kozlov_Afisha_Render::init();

if ( is_admin() ) {
	require_once KOZLOV_AFISHA_PAGES_DIR . 'includes/class-kozlov-afisha-help.php';
	Kozlov_Afisha_Help::init();
}

add_shortcode( 'kozlov_afisha', 'kozlov_afisha_pages_shortcode' );

/**
 * Шорткод [kozlov_afisha].
 *
 * Параметры:
 *   genre="jazz"            жанр(ы) из «Жанров» анонса, по-русски или по-английски, через запятую (любой из)
 *   stage="the_beat"        сцена(ы): main_scene, mansarda, the_beat, myasnitskaya
 *   when="upcoming"         upcoming (ближайшие), today (сегодня), weekend (пятница-воскресенье)
 *   free="1"                только «Вход свободный»
 *   days="60"               на сколько дней вперёд (для upcoming)
 *   limit="60"              максимум концертов
 *   group="month"           группировка: month, day, none
 *   empty="..."             текст, если концертов нет
 *   more="1"                ссылка «Вся афиша клуба» внизу
 *
 * Любая ошибка: вместо афиши ссылка на главную, страница работает.
 *
 * @param array|string $atts Параметры.
 * @return string
 */
function kozlov_afisha_pages_shortcode( $atts ) {
	try {
		$args = shortcode_atts(
			array(
				'genre' => '',
				'stage' => '',
				'when'  => 'upcoming',
				'free'  => '0',
				'days'  => '60',
				'limit' => '60',
				'group' => '',
				'empty' => '',
				'more'  => '1',
			),
			is_array( $atts ) ? $atts : array(),
			'kozlov_afisha'
		);

		// Список зависит от текущего времени: просим W3 Total Cache не класть страницу в кэш целиком,
		// а готовый список держим 5 минут (сбрасывается при сохранении анонса).
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		return Kozlov_Afisha_Render::shortcode( $args );
	} catch ( Throwable $e ) {
		return '<p class="kozlov-afisha__empty"><a href="' . esc_url( home_url( '/' ) ) . '">Смотрите афишу клуба</a></p>';
	}
}
