<?php
/**
 * Подключение поиска на страницах сайта: один небольшой отложенный скрипт в подвале.
 * Файлы темы не меняются, глобальных стилей плагин не добавляет.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'KSS_Front' ) ) :

	class KSS_Front {

		public static function init() {
			add_action( 'wp_footer', array( __CLASS__, 'print_loader' ), 100 );
			add_action( 'send_headers', array( __CLASS__, 'preview_headers' ), 1 );
		}

		/** Ключ из ссылки для проверки: /?kss_preview=ключ. */
		protected static function given_key() {
			if ( ! isset( $_GET['kss_preview'] ) || ! is_string( $_GET['kss_preview'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				return '';
			}
			$key = wp_unslash( $_GET['kss_preview'] ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
			return preg_match( '/^[A-Za-z0-9]{20,64}$/', $key ) ? $key : '';
		}

		/** Страница, открытая по ссылке для проверки, не должна попадать в кэш страниц. */
		public static function preview_headers() {
			try {
				$key = self::given_key();
				if ( '' === $key || ! KSS_Settings::key_matches( $key ) ) {
					return;
				}
				if ( ! defined( 'DONOTCACHEPAGE' ) ) {
					define( 'DONOTCACHEPAGE', true );
				}
				if ( ! headers_sent() ) {
					nocache_headers();
					header( 'X-Robots-Tag: noindex, nofollow' );
				}
			} catch ( \Throwable $e ) {
				kss_log( $e );
			}
		}

		public static function print_loader() {
			try {
				if ( is_admin() || is_feed() || ( function_exists( 'is_embed' ) && is_embed() ) || ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) ) {
					return;
				}
				$key = self::given_key();
				if ( ! KSS_Settings::allowed_for_current_user( $key ) ) {
					return;
				}
				$mode = KSS_Settings::mode();
				$ver  = rawurlencode( KSS_VERSION );
				$cfg  = array(
					'v'     => KSS_VERSION,
					'index' => KSS_Index::relative( rest_url( KSS_Rest::NS . '/index' ) ),
					'texts' => KSS_Settings::content() ? KSS_Index::relative( rest_url( KSS_Rest::NS . '/texts' ) ) : '',
					'app'   => KSS_Index::relative( plugins_url( 'assets/kss-app.js', KSS_FILE ) ) . '?ver=' . $ver,
					'home'  => KSS_Index::relative( home_url( '/' ) ),
				);
				if ( 'admin' === $mode ) {
					// в режиме проверки индекс отдаётся только администратору (запросу нужен nonce) или по ключу из ссылки для проверки
					$cfg['preview'] = 1;
					if ( KSS_Settings::key_matches( $key ) ) {
						$cfg['key'] = $key;
					} else {
						$cfg['nonce'] = wp_create_nonce( 'wp_rest' );
					}
				}
				$json = wp_json_encode( $cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES );
				if ( ! is_string( $json ) ) {
					return;
				}
				$src = KSS_Index::relative( plugins_url( 'assets/kss-loader.js', KSS_FILE ) ) . '?ver=' . $ver;
				echo "\n" . '<script type="application/json" id="kss-config">' . $json . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
				echo '<script defer src="' . esc_url( $src ) . '" id="kss-loader-js"></script>' . "\n"; // phpcs:ignore WordPress.WP.EnqueuedResources
			} catch ( \Throwable $e ) {
				kss_log( $e );
			}
		}
	}

endif;
