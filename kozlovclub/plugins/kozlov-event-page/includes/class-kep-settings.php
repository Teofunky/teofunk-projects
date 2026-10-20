<?php
/**
 * Настройки плагина: режим, телефон, подпись к цене, 3D-тур, адреса и карты сцен, «Важно знать» по сцене.
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
			'map_main'   => '',
			'map_u'      => '',
			// «Важно знать» по сцене: t — заголовок жирным, x — текст. Пусто: ничего не добавляется.
			'stage_notes' => array(
				'g' => array( 't' => '', 'x' => '' ),
				'm' => array( 't' => '', 'x' => '' ),
				'b' => array( 't' => '', 'x' => '' ),
				'u' => array(
					't' => 'Концерт не на Маросейке',
					'x' => 'Сцена «Unplugged» находится по адресу ул. Мясницкая, 15 (вход через Lions Head Pub)',
				),
			),
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
		$notes       = isset( $saved['stage_notes'] ) && is_array( $saved['stage_notes'] ) ? $saved['stage_notes'] : array();
		foreach ( $defaults['stage_notes'] as $code => $def ) {
			$one                         = isset( $notes[ $code ] ) && is_array( $notes[ $code ] ) ? $notes[ $code ] : array();
			$all['stage_notes'][ $code ] = array(
				't' => isset( $one['t'] ) ? (string) $one['t'] : $def['t'],
				'x' => isset( $one['x'] ) ? (string) $one['x'] : $def['x'],
			);
		}
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
		update_option( 'kep_version', KEP_VERSION, true );
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
	 * Обновление плагина без активации (загрузка новой версии поверх старой):
	 * режим «Для всех» временно меняется на «Только редакторы», в админке появляется
	 * уведомление с кнопкой вернуть прежний режим.
	 */
	public static function maybe_upgrade() {
		$v = (string) get_option( 'kep_version', '' );
		if ( KEP_VERSION === $v ) {
			return;
		}
		update_option( 'kep_version', KEP_VERSION, true );
		$saved = get_option( self::OPTION, false );
		if ( ! is_array( $saved ) ) {
			return; // первая установка: настройки создаст активация
		}
		$prev = isset( $saved['mode'] ) ? $saved['mode'] : 'editors';
		if ( 'all' === $prev ) { // «Выключено» и так безопасно, его не трогаем
			$saved['mode'] = 'editors';
			update_option( self::OPTION, $saved, false );
			KEP_Data::flush_all();
		}
		set_transient(
			'kep_updated',
			array(
				'from' => '' === $v ? 'старой версии' : $v,
				'prev' => $prev,
			),
			30 * DAY_IN_SECONDS
		);
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
		foreach ( array( 'map_main', 'map_u' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$out[ $k ] = esc_url_raw( trim( wp_unslash( $in[ $k ] ) ) );
			}
		}
		if ( isset( $in['stage_notes'] ) && is_array( $in['stage_notes'] ) ) {
			foreach ( array_keys( self::stages() ) as $code ) {
				$one = isset( $in['stage_notes'][ $code ] ) && is_array( $in['stage_notes'][ $code ] ) ? $in['stage_notes'][ $code ] : array();
				$t   = isset( $one['t'] ) ? sanitize_text_field( wp_unslash( $one['t'] ) ) : '';
				$x   = isset( $one['x'] ) ? KEP_Data::inline( str_replace( array( "\r", "\n" ), ' ', wp_unslash( $one['x'] ) ) ) : '';
				$out['stage_notes'][ $code ] = array(
					't' => mb_substr( $t, 0, 80, 'UTF-8' ),
					'x' => mb_substr( $x, 0, 400, 'UTF-8' ),
				);
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

	/**
	 * Ссылка на карту для сцены: своя из настроек или поиск адреса в Яндекс Картах.
	 *
	 * @param string $code Код сцены.
	 * @return string
	 */
	public static function map_url( $code ) {
		$s   = self::get();
		$own = 'u' === $code ? $s['map_u'] : $s['map_main'];
		if ( '' !== (string) $own ) {
			return $own;
		}
		$place = self::place( $code );
		return '' !== $place['addr'] ? 'https://yandex.ru/maps/?text=' . rawurlencode( 'Москва, ' . $place['addr'] ) : '';
	}

	/**
	 * «Важно знать» для сцены: заголовок, текст, карта. null, если для сцены ничего не задано.
	 *
	 * @param string $code Код сцены.
	 * @return array{title:string,text:string,map:string}|null
	 */
	public static function stage_note( $code ) {
		$all = self::get( 'stage_notes' );
		if ( '' === (string) $code || ! isset( $all[ $code ] ) ) {
			return null;
		}
		$t = trim( (string) $all[ $code ]['t'] );
		$x = trim( (string) $all[ $code ]['x'] );
		if ( '' === $t && '' === $x ) {
			return null;
		}
		return array(
			'title' => $t,
			'text'  => $x,
			'map'   => self::map_url( $code ),
			'addr'  => self::place( $code )['addr'],
		);
	}

	/**
	 * Задан ли текст «Важно знать» хоть для одной сцены.
	 *
	 * @return bool
	 */
	public static function has_stage_notes() {
		foreach ( array_keys( self::stages() ) as $code ) {
			if ( self::stage_note( $code ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Добавлять ли текст сцены на страницы: режим не «Выключено» и текст задан хоть для одной сцены.
	 *
	 * @return bool
	 */
	public static function stage_notes_on() {
		return 'off' !== self::get( 'mode' ) && self::has_stage_notes();
	}

	/**
	 * Деактивация: сбросить кэш страниц, чтобы гости сразу получили страницы темы без плагина.
	 */
	public static function deactivate() {
		if ( class_exists( 'KEP_Data' ) ) {
			KEP_Data::flush_all();
		}
	}
}
