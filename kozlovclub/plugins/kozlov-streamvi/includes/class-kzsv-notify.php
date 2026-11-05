<?php
/**
 * Письмо администратору, если переименование не удалось. Одна проблема: не чаще раза в 6 часов.
 * Активная проблема видна в админке, пока не исправится.
 */

defined( 'ABSPATH' ) || exit;

final class KZSV_Notify {

	const EVERY = 21600; // 6 часов

	/**
	 * @param string $code  auth | fail | source | config
	 * @param string $text  Что случилось, для человека.
	 */
	public static function problem( $code, $text ) {
		$state             = KZSV_Settings::state();
		$problems          = isset( $state['problems'] ) && is_array( $state['problems'] ) ? $state['problems'] : array();
		$problems[ $code ] = array(
			'text' => (string) $text,
			'at'   => time(),
		);
		$sent = isset( $state['notified'] ) && is_array( $state['notified'] ) ? $state['notified'] : array();
		$due  = empty( $sent[ $code ] ) || ( time() - (int) $sent[ $code ] ) > self::EVERY;
		if ( $due && KZSV_Settings::get( 'notify' ) ) {
			$to = (string) KZSV_Settings::get( 'notify_email' );
			if ( '' === $to ) {
				$to = (string) get_option( 'admin_email' );
			}
			if ( '' !== $to ) {
				$subject = 'Трансляция StreamVi не переименована';
				$body    = $text . "\n\nПроверить и исправить: " . KZSV_Settings::admin_url() . "\n\nПисьмо отправил плагин Kozlov StreamVi на " . home_url( '/' );
				$ok      = wp_mail( $to, $subject, $body );
				if ( $ok ) {
					$sent[ $code ] = time();
				}
			}
		}
		KZSV_Settings::patch_state(
			array(
				'problems' => $problems,
				'notified' => $sent,
			)
		);
	}

	public static function resolve( $code ) {
		$state = KZSV_Settings::state();
		if ( empty( $state['problems'][ $code ] ) ) {
			return;
		}
		unset( $state['problems'][ $code ] );
		if ( isset( $state['notified'][ $code ] ) ) {
			unset( $state['notified'][ $code ] );
		}
		KZSV_Settings::save_state( $state );
	}

	public static function active() {
		$state = KZSV_Settings::state();
		return isset( $state['problems'] ) && is_array( $state['problems'] ) ? $state['problems'] : array();
	}
}
