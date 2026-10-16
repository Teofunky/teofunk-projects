<?php
/**
 * Plugin Name:       Kozlov Smart Search
 * Description:       Мгновенный поиск по будущим концертам и страницам сайта. Работает только на чтение: не меняет тему, афишу и данные сайта.
 * Version:           1.0.4
 * Requires at least: 5.3
 * Requires PHP:      7.2
 * Author:            Клуб Алексея Козлова
 * License:           GPL-2.0-or-later
 * Text Domain:       kozlov-smart-search
 *
 * Как устроен плагин:
 *  - читает будущие концерты из таблиц All-in-One Event Calendar и полей события, ничего в них не записывает;
 *  - собирает из них небольшой индекс и хранит его в кэше (transient);
 *  - отдаёт индекс через REST в пространстве имён /kozlov-search/v1/;
 *  - сам поиск идёт в браузере посетителя: ни одного запроса к базе на нажатие клавиши;
 *  - интерфейс живёт в Shadow DOM и не трогает стили темы.
 *
 * Аварийное отключение без входа в админку: добавьте в wp-config.php строку
 *     define( 'KSS_DISABLE', true );
 * или просто удалите папку плагина. Сайт и афиша от плагина не зависят.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Второй экземпляр плагина (например, копия папки) не загружается.
if ( defined( 'KSS_VERSION' ) ) {
	return;
}

define( 'KSS_VERSION', '1.0.4' );
define( 'KSS_FILE', __FILE__ );
define( 'KSS_DIR', dirname( __FILE__ ) . '/' );

if ( ! function_exists( 'kss_log' ) ) {
	/**
	 * Пишет ошибку плагина в журнал PHP и никогда не прерывает работу сайта.
	 *
	 * @param mixed $e Исключение или строка.
	 */
	function kss_log( $e ) {
		try {
			$msg = is_object( $e ) && method_exists( $e, 'getMessage' )
				? get_class( $e ) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine()
				: (string) $e;
			error_log( '[kozlov-smart-search] ' . $msg ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		} catch ( \Throwable $ignored ) {
			return;
		}
	}
}

if ( ! function_exists( 'kss_boot' ) ) {
	/**
	 * Загружает плагин. Любая ошибка здесь гасится: сайт продолжает работать со штатным поиском темы.
	 */
	function kss_boot() {
		if ( defined( 'KSS_DISABLE' ) && KSS_DISABLE ) {
			return;
		}
		try {
			foreach ( array( 'settings', 'index', 'rest', 'front', 'admin' ) as $part ) {
				$file = KSS_DIR . 'includes/class-kss-' . $part . '.php';
				if ( ! is_readable( $file ) ) {
					kss_log( 'missing file ' . $file );
					return;
				}
				require_once $file;
			}
			KSS_Rest::init();
			KSS_Front::init();
			KSS_Admin::init();
			KSS_Index::init();
		} catch ( \Throwable $e ) {
			kss_log( $e );
		}
	}
	add_action( 'plugins_loaded', 'kss_boot', 20 );
}
