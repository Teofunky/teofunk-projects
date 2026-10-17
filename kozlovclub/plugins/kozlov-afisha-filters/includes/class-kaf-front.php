<?php
/**
 * Вывод на сайте: только на главной странице, только когда панель разрешена текущему посетителю.
 * Добавляет одну JSON-настройку и один скрипт с defer. Стилей в страницу не добавляет: они внутри Shadow DOM.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'KAF_Front' ) ) :

	class KAF_Front {

		protected static $on = null;

		public static function init() {
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 20 );
			add_action( 'wp_footer', array( __CLASS__, 'config' ), 5 );
			add_filter( 'rest_post_dispatch', array( __CLASS__, 'mark_index' ), 20, 3 );
		}

		/**
		 * Добавляет в ответ индекса Kozlov Smart Search поле kaf с режимом панели (off, preview, on).
		 * Скрипт панели проверяет его при каждой загрузке индекса: если панель выключили в настройках
		 * или выключили сам плагин (тогда поля нет), уже открытые вкладки убирают панель при следующей фоновой проверке.
		 * Другие маршруты и ответы с ошибкой не меняются.
		 */
		public static function mark_index( $response, $server = null, $request = null ) {
			try {
				if ( ! is_object( $request ) || ! method_exists( $request, 'get_route' ) || '/kozlov-search/v1/index' !== (string) $request->get_route() ) {
					return $response;
				}
				if ( ! is_object( $response ) || ! method_exists( $response, 'get_data' ) || ! method_exists( $response, 'set_data' ) || ! method_exists( $response, 'get_status' ) || 200 !== (int) $response->get_status() ) {
					return $response;
				}
				$data = $response->get_data();
				if ( is_array( $data ) && isset( $data['v'], $data['ev'] ) ) {
					$data['kaf'] = KAF_Settings::mode();
					$response->set_data( $data );
				}
			} catch ( \Throwable $e ) {
				kaf_log( $e );
			}
			return $response;
		}

		/** Нужна ли панель на этой странице. Считается один раз за запрос. */
		protected static function on() {
			if ( null !== self::$on ) {
				return self::$on;
			}
			self::$on = false;
			try {
				if ( is_admin() || is_feed() || ( function_exists( 'is_embed' ) && is_embed() ) || ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) ) {
					return false;
				}
				self::$on = is_front_page() && KAF_Settings::visible_for_current_user();
			} catch ( \Throwable $e ) {
				kaf_log( $e );
				self::$on = false;
			}
			return self::$on;
		}

		public static function enqueue() {
			try {
				if ( ! self::on() ) {
					return;
				}
				// в WordPress 6.3+ скрипт получает defer; в более ранних просто грузится в подвале страницы
				wp_enqueue_script( 'kozlov-afisha-filters', plugins_url( 'assets/kaf-app.js', KAF_FILE ), array(), KAF_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
			} catch ( \Throwable $e ) {
				kaf_log( $e );
			}
		}

		public static function config() {
			try {
				if ( ! self::on() ) {
					return;
				}
				$cfg = array(
					'v'       => KAF_VERSION,
					'index'   => wp_make_link_relative( rest_url( 'kozlov-search/v1/index' ) ),
					'fonts'   => wp_make_link_relative( plugins_url( 'assets/fonts/', KAF_FILE ) ),
					'preview' => ( 'preview' === KAF_Settings::mode() || 'admin' === KAF_Settings::kss_mode() ) ? 1 : 0,
				);
				// тексты анонсов для поиска по музыкантам: только если в Kozlov Smart Search включён поиск по анонсам
				if ( class_exists( 'KSS_Settings' ) && method_exists( 'KSS_Settings', 'content' ) && KSS_Settings::content() ) {
					$cfg['texts'] = wp_make_link_relative( rest_url( 'kozlov-search/v1/texts' ) );
				}
				// вошедшему администратору нужен nonce: в режиме проверки поиск отдаёт индекс только ему
				if ( is_user_logged_in() ) {
					$cfg['nonce'] = wp_create_nonce( 'wp_rest' );
				}
				$json = wp_json_encode( $cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES );
				if ( ! is_string( $json ) ) {
					return;
				}
				echo '<script type="application/json" id="kaf-config">' . $json . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON с экранированием тегов и кавычек
			} catch ( \Throwable $e ) {
				kaf_log( $e );
			}
		}
	}

endif;
