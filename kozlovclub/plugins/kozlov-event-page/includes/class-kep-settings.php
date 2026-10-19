<?php
/**
 * Настройки плагина: режим, телефон, подпись к цене, 3D-тур и адреса сцен.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KEP_Settings {

	const OPTION = 'kep_settings';

	/**
	 * Значения по умолчанию.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'mode'       => 'editors',
			'phone'      => '+7 (495) 296-12-00',
			'price_note' => 'без сервисного сбора',
			'tour'       => array(
				'g' => 'https://pro3d.pro/f8Cq3YyH9vN/?m=f8Cq3YyH9vN&ss=103&sr=-.07,-.41',
				'm' => 'https://pro3d.pro/f8Cq3YyH9vN/?m=f8Cq3YyH9vN&ss=33&sr=3.14,-1.48',
				'b' => 'https://pro3d.pro/f8Cq3YyH9vN/?m=f8Cq3YyH9vN&ss=164&sr=-2.27,-1.43',
				'u' => '',
			),
			'addr_main'  => 'ул. Маросейка, 9/2',
			'metro_main' => 'м. Китай-город, Лубянка, Кузнецкий мост',
			'addr_u'     => 'ул. Мясницкая, 15',
			'metro_u'    => '',
		);
	}

	/**
	 * Все настройки или одна.
	 *
	 * @param string|null $key Ключ.
	 * @return mixed
	 */
	public static function get( $key = null ) {
		$defaults = self::defaults();
		$saved    = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		$all         = array_merge( $defaults, $saved );
		$all['tour'] = array_merge( $defaults['tour'], isset( $saved['tour'] ) && is_array( $saved['tour'] ) ? $saved['tour'] : array() );
		if ( ! in_array( $all['mode'], array( 'off', 'editors', 'all' ), true ) ) {
			$all['mode'] = 'editors';
		}
		if ( null === $key ) {
			return $all;
		}
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * При активации, в том числе повторной: безопасный режим «Только редакторы».
	 * Остальные настройки сохраняются.
	 */
	public static function activate() {
		$saved = get_option( self::OPTION, false );
		if ( false === $saved || ! is_array( $saved ) ) {
			add_option( self::OPTION, self::defaults(), '', false );
			return;
		}
		if ( ! isset( $saved['mode'] ) || 'editors' !== $saved['mode'] ) {
			$saved['mode'] = 'editors';
			update_option( self::OPTION, $saved, false );
		}
	}

	/**
	 * Очистка данных формы настроек.
	 *
	 * @param mixed $in Данные формы.
	 * @return array
	 */
	public static function sanitize( $in ) {
		$old = self::get();
		if ( ! is_array( $in ) ) {
			return $old;
		}
		$out          = $old;
		$out['mode']  = ( isset( $in['mode'] ) && in_array( $in['mode'], array( 'off', 'editors', 'all' ), true ) ) ? $in['mode'] : $old['mode'];
		foreach ( array( 'phone', 'price_note', 'addr_main', 'metro_main', 'addr_u', 'metro_u' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$out[ $k ] = sanitize_text_field( wp_unslash( $in[ $k ] ) );
			}
		}
		if ( isset( $in['tour'] ) && is_array( $in['tour'] ) ) {
			foreach ( array_keys( self::stages() ) as $code ) {
				$out['tour'][ $code ] = isset( $in['tour'][ $code ] ) ? esc_url_raw( trim( wp_unslash( $in['tour'][ $code ] ) ) ) : '';
			}
		}
		return $out;
	}

	/**
	 * Сцены клуба: код из индекса Kozlov Smart Search и название.
	 *
	 * @return array
	 */
	public static function stages() {
		return array(
			'g' => 'Главная сцена',
			'm' => 'Мансарда',
			'b' => 'The Beat',
			'u' => 'Сцена на Мясницкой, 15',
		);
	}

	/**
	 * Код сцены по названию, как его пишет тема.
	 *
	 * @param string $name Название сцены.
	 * @return string
	 */
	public static function stage_code( $name ) {
		$n = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $name, 'UTF-8' ) : strtolower( (string) $name );
		if ( false !== strpos( $n, 'beat' ) ) {
			return 'b';
		}
		if ( false !== strpos( $n, 'мансард' ) ) {
			return 'm';
		}
		if ( false !== strpos( $n, 'мясницк' ) || false !== strpos( $n, 'unplugged' ) ) {
			return 'u';
		}
		if ( false !== strpos( $n, 'главн' ) ) {
			return 'g';
		}
		return '';
	}

	/**
	 * Адрес и метро для сцены.
	 *
	 * @param string $code Код сцены.
	 * @return array{addr:string,metro:string}
	 */
	public static function place( $code ) {
		$s = self::get();
		if ( 'u' === $code ) {
			return array( 'addr' => $s['addr_u'], 'metro' => $s['metro_u'] );
		}
		return array( 'addr' => $s['addr_main'], 'metro' => $s['metro_main'] );
	}

	/**
	 * Ссылка на 3D-тур для сцены.
	 *
	 * @param string $code Код сцены.
	 * @return string
	 */
	public static function tour( $code ) {
		$t = self::get( 'tour' );
		return ( $code && ! empty( $t[ $code ] ) ) ? $t[ $code ] : '';
	}
}
