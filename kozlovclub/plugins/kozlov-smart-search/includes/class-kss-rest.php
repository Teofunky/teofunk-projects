<?php
/**
 * REST: только чтение, только пространство имён /kozlov-search/v1/.
 *   GET /wp-json/kozlov-search/v1/index  — концерты и страницы
 *   GET /wp-json/kozlov-search/v1/texts  — словарь текстов анонсов
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'KSS_Rest' ) ) :

	class KSS_Rest {

		const NS   = 'kozlov-search/v1';
		const PAST = 'Wed, 11 Jan 1984 05:00:00 GMT';

		public static function init() {
			add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
			add_filter( 'rest_pre_serve_request', array( __CLASS__, 'serve_compact' ), 10, 4 );
			add_filter( 'rest_post_dispatch', array( __CLASS__, 'error_headers' ), 10, 3 );
		}

		public static function routes() {
			try {
				foreach ( array( 'index', 'texts' ) as $part ) {
					register_rest_route(
						self::NS,
						'/' . $part,
						array(
							'methods'             => 'GET',
							'callback'            => array( __CLASS__, 'serve_' . $part ),
							'permission_callback' => array( __CLASS__, 'can_read' ),
						)
					);
				}
			} catch ( \Throwable $e ) {
				kss_log( $e );
			}
		}

		/** Выключенный поиск отвечает 403: скрипт в браузере посетителя после этого не включается. */
		public static function can_read( $request = null ) {
			try {
				$key = ( is_object( $request ) && method_exists( $request, 'get_header' ) ) ? (string) $request->get_header( 'x_kss_preview' ) : '';
				if ( KSS_Settings::allowed_for_current_user( $key ) ) {
					return true;
				}
			} catch ( \Throwable $e ) {
				kss_log( $e );
			}
			return new WP_Error( 'kss_disabled', 'Search is disabled.', array( 'status' => 403 ) );
		}

		public static function serve_index( $request ) {
			return self::respond( 'index' );
		}

		public static function serve_texts( $request ) {
			return self::respond( 'texts' );
		}

		protected static function respond( $part ) {
			try {
				$data = KSS_Index::get( $part );
				if ( ! is_array( $data ) ) {
					return new WP_Error( 'kss_unavailable', 'Index is not available.', array( 'status' => 503 ) );
				}
				unset( $data['sig'], $data['stats'] );
				$data['now'] = time();
				$response    = new WP_REST_Response( $data, 200 );
				$public      = ( 'on' === KSS_Settings::mode() ) && ! is_user_logged_in();
				$response->header( 'Cache-Control', $public ? 'public, max-age=300' : 'private, no-store' );
				// Срок хранения задаётся явно: иначе правило сервера для JSON-ответов (mod_expires) допишет к ответу год хранения в браузере.
				$response->header( 'Expires', $public ? gmdate( 'D, d M Y H:i:s', time() + 300 ) . ' GMT' : self::PAST );
				$response->header( 'X-Robots-Tag', 'noindex, nofollow' );
				return $response;
			} catch ( \Throwable $e ) {
				kss_log( $e );
				return new WP_Error( 'kss_error', 'Search index error.', array( 'status' => 500 ) );
			}
		}

		/**
		 * Ответы плагина с ошибкой (поиск выключен, индекс недоступен) браузер не должен хранить:
		 * иначе после включения поиска посетитель продолжал бы получать сохранённый отказ.
		 * Чужие маршруты не затрагиваются.
		 */
		public static function error_headers( $response, $server = null, $request = null ) {
			try {
				if ( is_object( $response ) && is_object( $request ) && method_exists( $request, 'get_route' ) && method_exists( $response, 'get_status' ) && method_exists( $response, 'header' ) ) {
					if ( 0 === strpos( (string) $request->get_route(), '/' . self::NS . '/' ) && (int) $response->get_status() >= 400 ) {
						$response->header( 'Cache-Control', 'private, no-store' );
						$response->header( 'Expires', self::PAST );
						if ( 503 === (int) $response->get_status() ) {
							$response->header( 'Retry-After', '5' );
						}
					}
				}
			} catch ( \Throwable $e ) {
				kss_log( $e );
			}
			return $response;
		}

		/**
		 * Отдаёт ответ плагина компактным JSON: кириллица буквами, а не кодами \uXXXX (ответ втрое меньше).
		 * Чужие маршруты не затрагиваются. При любой неудаче WordPress отдаёт ответ обычным способом.
		 */
		public static function serve_compact( $served, $result, $request, $server ) {
			try {
				if ( $served || ! is_object( $request ) || ! is_object( $result ) || ! method_exists( $request, 'get_route' ) || ! method_exists( $result, 'get_data' ) ) {
					return $served;
				}
				if ( 0 !== strpos( (string) $request->get_route(), '/' . self::NS . '/' ) ) {
					return $served;
				}
				if ( 'GET' !== $request->get_method() || isset( $_GET['_jsonp'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
					return $served;
				}
				$data = $result->get_data();
				if ( ! is_array( $data ) || empty( $data['v'] ) || ! isset( $data['built'] ) ) {
					return $served;
				}
				$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
				if ( defined( 'JSON_INVALID_UTF8_SUBSTITUTE' ) ) {
					$flags |= JSON_INVALID_UTF8_SUBSTITUTE;
				}
				$json = json_encode( $data, $flags ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				if ( ! is_string( $json ) || '' === $json ) {
					return $served;
				}
				echo $json; // phpcs:ignore WordPress.Security.EscapeOutput
				return true;
			} catch ( \Throwable $e ) {
				kss_log( $e );
				return $served;
			}
		}
	}

endif;
