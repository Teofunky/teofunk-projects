<?php
/**
 * Витрина: подключает навигатор на нужных страницах и отключает там старый виджет.
 * Тему, шаблоны и другие плагины не трогает: один отложенный скрипт и JSON с настройками.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KNAV_Front {
	private static $show = null;

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 999 );
		add_action( 'wp_print_footer_scripts', array( __CLASS__, 'dequeue_old' ), 1 );
		add_action( 'wp_footer', array( __CLASS__, 'print_config' ), 5 );
	}

	/** Показывать ли навигатор на этой странице этому посетителю. */
	public static function should_show() {
		if ( null !== self::$show ) {
			return self::$show;
		}
		$s          = KNAV_Settings::get();
		self::$show = false;
		if ( empty( $s['enabled'] ) || is_admin() || is_feed() || is_robots() || is_embed() || wp_doing_ajax() ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}
		if ( 'all' !== $s['audience'] && ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		if ( ! self::is_home() && 'button' !== $s['others'] ) {
			return false;
		}
		self::$show = (bool) apply_filters( 'knav_should_show', true );
		return self::$show;
	}

	/** Главная (афиша). На остальных страницах навигатор бывает только плавающей кнопкой. */
	public static function is_home() {
		return is_front_page();
	}

	public static function enqueue() {
		self::dequeue_old();
		if ( ! self::should_show() ) {
			return;
		}
		$args = array( 'in_footer' => true, 'strategy' => 'defer' );
		wp_enqueue_script( 'kozlov-navigator', KNAV_URL . 'assets/navigator.js', array(), KNAV_VERSION, $args );
	}

	/**
	 * Старый виджет снимается там, где показан новый, а при «на всём сайте» и на остальных страницах
	 * (тем же посетителям, которым виден новый: в режиме проверки только администраторам).
	 * Файл старого виджета и тема не меняются. Откат: снять галочку или выключить плагин.
	 */
	public static function old_should_go() {
		$s = KNAV_Settings::get();
		if ( empty( $s['enabled'] ) || empty( $s['replace_old'] ) || empty( $s['old_handle'] ) || is_admin() ) {
			return false;
		}
		if ( self::should_show() ) {
			return true;
		}
		if ( empty( $s['old_everywhere'] ) ) {
			return false;
		}
		return 'all' === $s['audience'] || current_user_can( 'manage_options' );
	}

	public static function dequeue_old() {
		if ( ! self::old_should_go() ) {
			return;
		}
		$s = KNAV_Settings::get();
		wp_dequeue_script( $s['old_handle'] );
	}

	public static function print_config() {
		if ( ! self::should_show() ) {
			return;
		}
		$s   = KNAV_Settings::get();
		$cfg = array(
			'v'          => KNAV_VERSION,
			'mode'       => 'kss',
			'index'      => wp_make_link_relative( rest_url( 'kozlov-search/v1/index' ) ),
			'texts'      => wp_make_link_relative( rest_url( 'kozlov-search/v1/texts' ) ),
			'track'      => ! empty( $s['stats'] ) ? wp_make_link_relative( rest_url( 'kozlov-navigator/v1/event' ) ) : '',
			'fonts'      => KNAV_URL . 'assets/fonts/',
			'site'       => untrailingslashit( home_url( '' ) ),
			'ym'         => $s['ym'],
			'phone'      => $s['phone'],
			'launcher'   => ! empty( $s['launcher'] ),
			'strip'      => ! empty( $s['strip'] ),
			'stripAfter' => $s['strip_after'],
			'dismissLauncher' => ! empty( $s['dismiss_launcher'] ),
			'dismissStrip'    => ! empty( $s['dismiss_strip'] ),
			'dismissDays' => (int) $s['dismiss_days'],
		);
		if ( ! self::is_home() ) {
			// Остальные страницы: без полосы, кнопка появляется при прокрутке и остаётся, как у прежнего виджета.
			$cfg['launcher']     = true;
			$cfg['strip']        = false;
			$cfg['launcherMode'] = 'scroll';
		}
		$cfg = apply_filters( 'knav_config', $cfg );
		echo "\n<script type=\"application/json\" id=\"knav-config\">" . wp_json_encode( $cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP ) . "</script>\n";
	}
}
