<?php
/**
 * Настройки плагина: одна опция knav_settings.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KNAV_Settings {
	const OPTION = 'knav_settings';

	public static function defaults() {
		return array(
			'enabled'      => 1,          // общий выключатель
			'audience'     => 'admins',   // admins: видят только администраторы (проверка); all: все посетители
			'launcher'     => 1,          // главная: плавающая кнопка
			'strip'        => 1,          // главная: полоса входа под панелью фильтров
			'dismiss_launcher' => 1,      // крестик на плавающей кнопке: посетитель сворачивает её в язычок у себя
			'dismiss_strip'    => 1,      // крестик на строке у фильтров (телефон): посетитель сворачивает её в чип «Подбор»
			'dismiss_days' => 30,         // сколько дней помнить, что скрыто
			'others'       => 'off',      // остальные страницы: off: навигатора нет; button: только плавающая кнопка, появляется при прокрутке
			'strip_after'  => '#kaf-filters',
			'replace_old'  => 1,          // отключать старый виджет там, где показан новый
			'old_everywhere' => 1,        // и на остальных страницах тоже (FAQ, контакты), когда навигатор включён
			'old_handle'   => 'kozlov-chat-widget-v2',
			'ym'           => '44419291', // счётчик Метрики
			'phone'        => '+7 (495) 296-12-00',
			'stats'        => 1,          // своя статистика в админке
			'keep_days'    => 400,
		);
	}

	public static function get() {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		// 1.0.1 и раньше: «Все страницы сайта» означало навигатор и на остальных страницах.
		if ( ! isset( $saved['others'] ) && isset( $saved['pages'] ) && 'all' === $saved['pages'] ) {
			$saved['others'] = 'button';
		}
		unset( $saved['pages'] );
		// 1.0.14 и раньше: одна галочка «Крестик» на кнопке и на строке сразу. Обе новые берут её значение.
		if ( isset( $saved['dismiss'] ) ) {
			foreach ( array( 'dismiss_launcher', 'dismiss_strip' ) as $k ) {
				if ( ! isset( $saved[ $k ] ) ) {
					$saved[ $k ] = $saved['dismiss'] ? 1 : 0;
				}
			}
		}
		unset( $saved['dismiss'] );
		return array_merge( self::defaults(), $saved );
	}

	public static function sanitize( $in ) {
		$d   = self::defaults();
		$in  = is_array( $in ) ? $in : array();
		$out = array();
		foreach ( array( 'enabled', 'launcher', 'strip', 'dismiss_launcher', 'dismiss_strip', 'replace_old', 'old_everywhere', 'stats' ) as $k ) {
			$out[ $k ] = empty( $in[ $k ] ) ? 0 : 1;
		}
		$out['audience']    = ( isset( $in['audience'] ) && 'all' === $in['audience'] ) ? 'all' : 'admins';
		$out['others']      = ( isset( $in['others'] ) && 'button' === $in['others'] ) ? 'button' : 'off';
		$sel                = isset( $in['strip_after'] ) ? trim( wp_strip_all_tags( (string) $in['strip_after'] ) ) : $d['strip_after'];
		$out['strip_after'] = ( '' !== $sel && preg_match( '/^[#.\w\s>:\-\[\]="]{1,120}$/u', $sel ) ) ? $sel : $d['strip_after'];
		$h                  = isset( $in['old_handle'] ) ? sanitize_key( $in['old_handle'] ) : $d['old_handle'];
		$out['old_handle']  = $h ? $h : $d['old_handle'];
		$ym                 = isset( $in['ym'] ) ? preg_replace( '/\D/', '', (string) $in['ym'] ) : '';
		$out['ym']          = $ym;
		$out['phone']       = isset( $in['phone'] ) ? sanitize_text_field( $in['phone'] ) : $d['phone'];
		$dd                  = isset( $in['dismiss_days'] ) ? (int) $in['dismiss_days'] : $d['dismiss_days'];
		$out['dismiss_days'] = max( 1, min( 365, $dd ) );
		$kd                 = isset( $in['keep_days'] ) ? (int) $in['keep_days'] : $d['keep_days'];
		$out['keep_days']   = max( 30, min( 1000, $kd ) );
		return $out;
	}
}
