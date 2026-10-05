<?php
/**
 * Настройки плагина: единственная собственная запись в wp_options.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'KSS_Settings' ) ) :

	class KSS_Settings {

		const OPTION = 'kss_settings';

		/**
		 * Режимы: off (выключен), admin (виден только администраторам), on (включён для всех).
		 */
		public static function modes() {
			return array( 'off', 'admin', 'on' );
		}

		public static function defaults() {
			return array(
				'mode'    => 'off',
				'content' => 1,
				'key'     => '',
			);
		}

		public static function get() {
			$saved = get_option( self::OPTION, array() );
			return self::sanitize( is_array( $saved ) ? $saved : array() );
		}

		public static function mode() {
			$s = self::get();
			return $s['mode'];
		}

		/** Искать ли в текстах анонсов. */
		public static function content() {
			$s = self::get();
			return ! empty( $s['content'] );
		}

		/**
		 * Приводит настройки к допустимым значениям.
		 *
		 * @param mixed $in Сырые значения.
		 * @return array
		 */
		public static function sanitize( $in ) {
			$out = self::defaults();
			if ( ! is_array( $in ) ) {
				return $out;
			}
			if ( isset( $in['mode'] ) && in_array( $in['mode'], self::modes(), true ) ) {
				$out['mode'] = $in['mode'];
			}
			if ( array_key_exists( 'content', $in ) ) {
				$out['content'] = empty( $in['content'] ) ? 0 : 1;
			}
			if ( isset( $in['key'] ) && is_string( $in['key'] ) && preg_match( '/^[A-Za-z0-9]{20,64}$/', $in['key'] ) ) {
				$out['key'] = $in['key'];
			}
			return $out;
		}

		/**
		 * Обработка формы настроек: ключ ссылки для проверки в форме не передаётся, он сохраняется прежним
		 * или создаётся заново, если его ещё нет.
		 */
		public static function sanitize_form( $in ) {
			$out = self::sanitize( $in );
			$old = get_option( self::OPTION, array() );
			$key = ( is_array( $old ) && isset( $old['key'] ) && is_string( $old['key'] ) && preg_match( '/^[A-Za-z0-9]{20,64}$/', $old['key'] ) ) ? $old['key'] : $out['key'];
			if ( is_array( $in ) && ! empty( $in['new_key'] ) ) {
				$key = '';
			}
			if ( '' === $key && function_exists( 'wp_generate_password' ) ) {
				$key = wp_generate_password( 28, false, false );
			}
			$out['key'] = $key;
			return $out;
		}

		/** Ключ ссылки для проверки поиска без входа в админку. Действует только в режиме проверки. */
		public static function key() {
			$s = self::get();
			return $s['key'];
		}

		/** Подходит ли переданный ключ: только в режиме проверки, сравнение за постоянное время. */
		public static function key_matches( $given ) {
			$key = self::key();
			return 'admin' === self::mode() && '' !== $key && is_string( $given ) && '' !== $given && hash_equals( $key, $given );
		}

		/**
		 * Доступен ли поиск текущему посетителю.
		 *
		 * @param string $given_key Ключ ссылки для проверки, если он передан в запросе.
		 */
		public static function allowed_for_current_user( $given_key = '' ) {
			$mode = self::mode();
			if ( 'on' === $mode ) {
				return true;
			}
			if ( 'admin' === $mode ) {
				if ( self::key_matches( $given_key ) ) {
					return true;
				}
				return function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
			}
			return false;
		}
	}

endif;
