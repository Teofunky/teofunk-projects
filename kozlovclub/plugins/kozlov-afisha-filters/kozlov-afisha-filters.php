<?php
/**
 * Plugin Name:       Kozlov Afisha Filters
 * Description:       Панель фильтров афиши на главной странице: Сегодня, Завтра, Выходные, Дата, Жанр, Цена, Сцена. Только прячет и показывает карточки афиши темы: события, тему и базу не меняет. Данные берёт из индекса Kozlov Smart Search.
 * Version:           1.1.0
 * Requires at least: 5.3
 * Requires PHP:      7.2
 * Author:            Клуб Алексея Козлова
 * License:           GPL-2.0-or-later
 * Text Domain:       kozlov-afisha-filters
 *
 * Как устроен плагин:
 *  - на главной странице выводит одну JSON-настройку и один скрипт с defer;
 *  - скрипт ставит панель сразу под слайдером, в Shadow DOM: стили темы и панели не пересекаются;
 *  - концерты берёт из индекса Kozlov Smart Search (/wp-json/kozlov-search/v1/index), своих запросов к базе нет;
 *  - при выбранном фильтре прячет лишние карточки афиши темы, при сбросе возвращает всё как было;
 *  - при сбое загрузки, выключении в настройках или ошибке в своём коде панель убирает себя;
 *  - если карточки страницы и индекс разошлись, фильтры отключаются и видна вся афиша.
 *
 * Аварийное отключение без входа в админку: добавьте в wp-config.php строку
 *     define( 'KAF_DISABLE', true );
 * или удалите папку плагина, затем сбросьте кэш страниц (Performance → Purge All Caches).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Второй экземпляр плагина (например, копия папки) не загружается.
if ( defined( 'KAF_VERSION' ) ) {
	return;
}

define( 'KAF_VERSION', '1.1.0' );
define( 'KAF_FILE', __FILE__ );
define( 'KAF_DIR', dirname( __FILE__ ) . '/' );

if ( ! function_exists( 'kaf_log' ) ) {
	/**
	 * Пишет ошибку плагина в журнал PHP и никогда не прерывает работу сайта.
	 *
	 * @param mixed $e Исключение или строка.
	 */
	function kaf_log( $e ) {
		try {
			$msg = is_object( $e ) && method_exists( $e, 'getMessage' )
				? get_class( $e ) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine()
				: (string) $e;
			error_log( '[kozlov-afisha-filters] ' . $msg ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		} catch ( \Throwable $ignored ) {
			return;
		}
	}
}

if ( ! function_exists( 'kaf_purge_page_cache' ) ) {
	/**
	 * Сбрасывает кэш страниц W3 Total Cache, чтобы смена режима сразу дошла до посетителей.
	 * Без W3 Total Cache ничего не делает.
	 *
	 * @return bool Был ли сброшен кэш.
	 */
	function kaf_purge_page_cache() {
		try {
			if ( function_exists( 'w3tc_pgcache_flush' ) ) {
				w3tc_pgcache_flush();
				return true;
			}
			if ( function_exists( 'w3tc_flush_all' ) ) {
				w3tc_flush_all();
				return true;
			}
		} catch ( \Throwable $e ) {
			kaf_log( $e );
		}
		return false;
	}
	// при выключении плагина страницы из кэша не должны продолжать показывать панель
	register_deactivation_hook( __FILE__, 'kaf_purge_page_cache' );
}

if ( ! function_exists( 'kaf_boot' ) ) {
	/**
	 * Загружает плагин. Любая ошибка здесь гасится: сайт работает как без плагина.
	 * Загрузка после Kozlov Smart Search (у него приоритет 20), чтобы видеть его версию и режим.
	 */
	function kaf_boot() {
		if ( defined( 'KAF_DISABLE' ) && KAF_DISABLE ) {
			return;
		}
		try {
			foreach ( array( 'settings', 'front', 'admin' ) as $part ) {
				$file = KAF_DIR . 'includes/class-kaf-' . $part . '.php';
				if ( ! is_readable( $file ) ) {
					kaf_log( 'missing file ' . $file );
					return;
				}
				require_once $file;
			}
			KAF_Front::init();
			KAF_Admin::init();
		} catch ( \Throwable $e ) {
			kaf_log( $e );
		}
	}
	add_action( 'plugins_loaded', 'kaf_boot', 30 );
}
