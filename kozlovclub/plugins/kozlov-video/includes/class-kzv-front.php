<?php
/**
 * Подмена содержимого страниц «Live» и «Видеоархив». Шапка, подвал и заголовок страницы остаются темы.
 * Если что-то пошло не так, страница показывается как раньше.
 */

defined( 'ABSPATH' ) || exit;

final class KZV_Front {

	/** @var string Готовый HTML для шорткода-заглушки. */
	private static $html = '';

	public static function init() {
		add_filter( 'the_content', array( __CLASS__, 'takeover' ), 1 );
		add_shortcode( 'kozlov_video', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 100 );
	}

	/** Какая из наших страниц открыта: live | archive | ''. */
	public static function view() {
		if ( ! is_page() ) {
			return '';
		}
		$id = (int) get_queried_object_id();
		if ( ! $id ) {
			return '';
		}
		$s = KZV_Settings::get();
		if ( $id === (int) $s['live_page'] ) {
			return 'live';
		}
		if ( $id === (int) $s['archive_page'] ) {
			return 'archive';
		}
		return '';
	}

	public static function takeover( $content ) {
		if ( ! in_the_loop() || ! is_main_query() || (int) get_the_ID() !== (int) get_queried_object_id() ) {
			return $content;
		}
		$view = self::view();
		if ( '' === $view || ! KZV_Settings::active_for_viewer() ) {
			return $content;
		}
		try {
			self::$html = 'live' === $view ? KZV_Render::page_live() : KZV_Render::page_archive();
			if ( 'all' !== KZV_Settings::get( 'mode' ) ) {
				self::$html = '<p class="kzv-preview-note">Новая версия страницы видна только редакторам. Включить для всех: «Трансляции → Видео на сайте».</p>' . self::$html;
			}
			if ( ! defined( 'DONOTCACHEPAGE' ) && 'all' !== KZV_Settings::get( 'mode' ) ) {
				define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
			}
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

	public static function assets() {
		$view = self::view();
		if ( '' === $view || ! KZV_Settings::active_for_viewer() ) {
			return;
		}
		wp_enqueue_style( 'kozlov-video', KZV_URL . 'assets/kzv.css', array(), KZV_VERSION );
		wp_enqueue_script( 'kozlov-video', KZV_URL . 'assets/kzv.js', array(), KZV_VERSION, true );
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
}
