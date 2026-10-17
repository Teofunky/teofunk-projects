<?php
/**
 * Настройки панели: одна запись kaf_settings с режимом.
 * Режимы: off (выключена), preview (видна только администраторам), on (видна всем).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'KAF_Settings' ) ) :

	class KAF_Settings {

		const OPTION   = 'kaf_settings';
		const KSS_NEED = '1.0.4'; // в индексе есть длительность концерта, ошибка базы не превращается в пустую афишу

		public static function modes() {
			return array( 'off', 'preview', 'on' );
		}

		public static function defaults() {
			return array( 'mode' => 'off' );
		}

		public static function mode() {
			try {
				$o    = get_option( self::OPTION, array() );
				$mode = ( is_array( $o ) && isset( $o['mode'] ) ) ? (string) $o['mode'] : 'off';
				return in_array( $mode, self::modes(), true ) ? $mode : 'off';
			} catch ( \Throwable $e ) {
				kaf_log( $e );
				return 'off';
			}
		}

		/** Очистка значения из формы настроек: неизвестный режим становится «выключено». */
		public static function sanitize( $input ) {
			$mode = ( is_array( $input ) && isset( $input['mode'] ) && is_string( $input['mode'] ) ) ? $input['mode'] : 'off';
			return array( 'mode' => in_array( $mode, self::modes(), true ) ? $mode : 'off' );
		}

		/** Установлен ли Kozlov Smart Search подходящей версии. */
		public static function kss_ok() {
			return defined( 'KSS_VERSION' ) && version_compare( (string) KSS_VERSION, self::KSS_NEED, '>=' ) && class_exists( 'KSS_Settings' );
		}

		/** Режим Kozlov Smart Search: off, admin или on. Пустая строка, если узнать нельзя. */
		public static function kss_mode() {
			try {
				if ( class_exists( 'KSS_Settings' ) && method_exists( 'KSS_Settings', 'mode' ) ) {
					return (string) KSS_Settings::mode();
				}
			} catch ( \Throwable $e ) {
				kaf_log( $e );
			}
			return '';
		}

		/**
		 * Показывать ли панель текущему посетителю.
		 * Индекс отдаёт Kozlov Smart Search, поэтому его режим тоже учитывается:
		 * выключен поиск — панели нет; поиск в режиме проверки — индекс получают только администраторы.
		 */
		public static function visible_for_current_user() {
			$mode = self::mode();
			if ( 'off' === $mode || ! self::kss_ok() ) {
				return false;
			}
			$kss = self::kss_mode();
			if ( 'on' !== $kss && 'admin' !== $kss ) {
				return false;
			}
			$admin = function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
			if ( 'preview' === $mode || 'admin' === $kss ) {
				return $admin;
			}
			return true;
		}
	}

endif;
