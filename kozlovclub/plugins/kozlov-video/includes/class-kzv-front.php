<?php
/**
 * Kozlov TV на сайте: страница /tv/ (а пока её нет, страницы «Live» и «Видеоархив»).
 * Шапка и подвал остаются темы. Когда Kozlov TV включён для всех и страница /tv/ опубликована,
 * старые адреса /live/ и /videoarxiv/ отвечают постоянным перенаправлением на /tv/.
 * Если что-то пошло не так, страница показывается как раньше.
 */

defined( 'ABSPATH' ) || exit;

final class KZV_Front {

	/** @var string Готовый HTML для шорткода-заглушки. */
	private static $html = '';

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'redirect' ), 5 );
		add_filter( 'the_content', array( __CLASS__, 'takeover' ), 1 );
		add_shortcode( 'kozlov_video', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 100 );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
	}

	/** Какая из наших страниц открыта: tv | live | archive | ''. */
	public static function view() {
		if ( ! is_page() ) {
			return '';
		}
		$id = (int) get_queried_object_id();
		if ( ! $id ) {
			return '';
		}
		$s = KZV_Settings::get();
		if ( $s['tv_page'] && $id === (int) $s['tv_page'] ) {
			return 'tv';
		}
		if ( $s['live_page'] && $id === (int) $s['live_page'] ) {
			return 'live';
		}
		if ( $s['archive_page'] && $id === (int) $s['archive_page'] ) {
			return 'archive';
		}
		return '';
	}

	/** Страница /tv/ опубликована и Kozlov TV включён для всех: старые адреса ведут на неё. */
	public static function tv_public() {
		$tv = (int) KZV_Settings::get( 'tv_page' );
		return 'all' === KZV_Settings::get( 'mode' ) && $tv && 'publish' === get_post_status( $tv );
	}

	public static function redirect() {
		try {
			$view = self::view();
			if ( ( 'live' !== $view && 'archive' !== $view ) || ! self::tv_public() ) {
				return;
			}
			$args = array();
			foreach ( array( 'v', 'q', 'genre' ) as $k ) {
				if ( isset( $_GET[ $k ] ) && '' !== $_GET[ $k ] ) { // phpcs:ignore WordPress.Security.NonceVerification
					$args[ $k ] = 'v' === $k ? absint( $_GET[ $k ] ) : sanitize_text_field( wp_unslash( $_GET[ $k ] ) ); // phpcs:ignore WordPress.Security.NonceVerification
				}
			}
			$to = (string) get_permalink( (int) KZV_Settings::get( 'tv_page' ) );
			if ( '' === $to ) {
				return;
			}
			wp_safe_redirect( $args ? add_query_arg( array_map( 'rawurlencode', $args ), $to ) : $to, 301, 'Kozlov Video' );
			exit;
		} catch ( \Throwable $e ) {
			kzv_log_exception( $e );
		}
	}

	/** Показывать ли Kozlov TV на открытой странице этому посетителю. */
	private static function active() {
		return '' !== self::view() && KZV_Settings::active_for_viewer();
	}

	public static function takeover( $content ) {
		$loop = in_the_loop() || ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() );
		if ( ! $loop || ! is_main_query() || (int) get_the_ID() !== (int) get_queried_object_id() ) {
			return $content;
		}
		if ( ! self::active() ) {
			return $content;
		}
		try {
			// phpcs:disable WordPress.Security.NonceVerification
			$args = array(
				'v'     => isset( $_GET['v'] ) ? absint( $_GET['v'] ) : 0,
				'q'     => isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '',
				'genre' => isset( $_GET['genre'] ) ? sanitize_key( wp_unslash( $_GET['genre'] ) ) : '',
				'focus' => ( 'archive' === self::view() || isset( $_GET['q'] ) || isset( $_GET['genre'] ) ) ? 'archive' : '',
			);
			// phpcs:enable
			self::$html = KZV_TV::page( $args );
			if ( 'all' !== KZV_Settings::get( 'mode' ) ) {
				self::$html = '<p class="kzv-preview-note">Kozlov TV видят только редакторы. Включить для всех: «Трансляции → Kozlov TV».</p>' . self::$html;
				if ( ! defined( 'DONOTCACHEPAGE' ) ) {
					define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
				}
			}
			KZV_Sync::maybe_run_later();
			return '[kozlov_video]';
		} catch ( \Throwable $e ) {
			kzv_log_exception( $e );
			self::$html = '';
			return $content;
		}
	}

	public static function shortcode() {
		$out        = self::$html;
		self::$html = '';
		return $out;
	}

	public static function body_class( $classes ) {
		if ( self::active() ) {
			$classes[] = 'kzv-tv-page';
		}
		return $classes;
	}

	public static function assets() {
		if ( ! self::active() ) {
			return;
		}
		$min = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';
		wp_enqueue_style( 'kozlov-video', KZV_URL . 'assets/tv' . $min . '.css', array(), KZV_VERSION );
		wp_enqueue_script( 'kozlov-video', KZV_URL . 'assets/tv' . $min . '.js', array(), KZV_VERSION, true );
		if ( function_exists( 'wp_script_add_data' ) ) {
			wp_script_add_data( 'kozlov-video', 'strategy', 'defer' );
		}
		// старая галерея YouTube на этих страницах больше не нужна: её скрипты не грузим
		foreach ( array( '__ytprefs__', '__ytprefsfitvids__', '__ytprefs__bar' ) as $h ) {
			wp_dequeue_script( $h );
		}
		foreach ( array( '__EPYT__style', '__ytprefs__bar' ) as $h ) {
			wp_dequeue_style( $h );
		}
	}

	/** Сбросить кэш страниц Kozlov TV (W3 Total Cache), чтобы посетители сразу увидели изменения. */
	public static function purge() {
		if ( ! function_exists( 'w3tc_flush_post' ) ) {
			return;
		}
		$s = KZV_Settings::get();
		foreach ( array( (int) $s['tv_page'], (int) $s['live_page'], (int) $s['archive_page'] ) as $id ) {
			if ( $id ) {
				w3tc_flush_post( $id );
			}
		}
	}
}
