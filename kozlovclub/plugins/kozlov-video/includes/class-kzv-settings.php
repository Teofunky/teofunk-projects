<?php
/**
 * Настройки плагина и служебное состояние (синхронизация, последние ошибки).
 */

defined( 'ABSPATH' ) || exit;

final class KZV_Settings {

	const OPTION = 'kzv_settings';
	const STATE  = 'kzv_state';

	/** Группа VK клуба: «Джаз Клуб Алексея Козлова». */
	const DEFAULT_VK_OWNER = -77211165;

	public static function defaults() {
		return array(
			'mode'         => 'off',   // off | admins | all
			'live_page'    => 0,       // ID страницы «Live»
			'archive_page' => 0,       // ID страницы «Видеоархив»
			'vk_owner'     => self::DEFAULT_VK_OWNER,
			'vk_public'    => 'https://vkvideo.ru/@kozlovclubfan',
			'yt_public'    => 'https://www.youtube.com/@kozlovclubtv',
			'tg_public'    => 'https://t.me/kozlovclub',
			'min_minutes'  => 30,      // короче не показываем (перезапуски эфира, проверки звука)
			'per_page'     => 24,
		);
	}

	/**
	 * @param string|null $key
	 * @return mixed
	 */
	public static function get( $key = null ) {
		$saved = get_option( self::OPTION, array() );
		$s     = array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
		if ( ! $s['live_page'] ) {
			$s['live_page'] = self::page_id_by_path( 'live' );
		}
		if ( ! $s['archive_page'] ) {
			$s['archive_page'] = self::page_id_by_path( 'videoarxiv' );
		}
		return null === $key ? $s : ( isset( $s[ $key ] ) ? $s[ $key ] : null );
	}

	private static function page_id_by_path( $path ) {
		static $cache = array();
		if ( ! isset( $cache[ $path ] ) ) {
			$p              = get_page_by_path( $path, OBJECT, 'page' );
			$cache[ $path ] = $p ? (int) $p->ID : 0;
		}
		return $cache[ $path ];
	}

	public static function sanitize( array $in ) {
		$d   = self::defaults();
		$out = array();

		$out['mode']         = in_array( $in['mode'] ?? '', array( 'off', 'admins', 'all' ), true ) ? $in['mode'] : $d['mode'];
		$out['live_page']    = max( 0, (int) ( $in['live_page'] ?? 0 ) );
		$out['archive_page'] = max( 0, (int) ( $in['archive_page'] ?? 0 ) );

		$owner           = (int) ( $in['vk_owner'] ?? $d['vk_owner'] );
		$out['vk_owner'] = $owner < 0 ? $owner : -abs( $owner ); // группа VK: отрицательный ID
		if ( 0 === $out['vk_owner'] ) {
			$out['vk_owner'] = $d['vk_owner'];
		}

		foreach ( array( 'vk_public', 'yt_public', 'tg_public' ) as $k ) {
			$url       = esc_url_raw( trim( (string) ( $in[ $k ] ?? '' ) ), array( 'https' ) );
			$out[ $k ] = '' !== $url ? $url : $d[ $k ];
		}

		$out['min_minutes'] = min( 180, max( 0, (int) ( $in['min_minutes'] ?? $d['min_minutes'] ) ) );
		$out['per_page']    = min( 60, max( 6, (int) ( $in['per_page'] ?? $d['per_page'] ) ) );
		return $out;
	}

	public static function update( array $values ) {
		update_option( self::OPTION, self::sanitize( array_merge( self::get(), $values ) ), false );
	}

	/** Видит ли текущий посетитель новые страницы. */
	public static function active_for_viewer() {
		$mode = (string) self::get( 'mode' );
		if ( 'all' === $mode ) {
			return true;
		}
		if ( 'admins' === $mode ) {
			return is_user_logged_in() && current_user_can( 'edit_pages' );
		}
		return false;
	}

	/** Часовой пояс клуба: тот же, что у Kozlov StreamVi, иначе Москва. */
	public static function tz() {
		if ( class_exists( 'KZSV_Settings' ) && method_exists( 'KZSV_Settings', 'tz' ) ) {
			try {
				$tz = KZSV_Settings::tz();
				if ( $tz instanceof DateTimeZone ) {
					return $tz;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}
		return new DateTimeZone( 'Europe/Moscow' );
	}

	/* ------------------------------------------------------------ состояние */

	public static function state() {
		$s = get_option( self::STATE, array() );
		return array_merge(
			array(
				'total'         => 0,  // трансляций в StreamVi
				'backfill'      => 0,  // сколько старых уже перебрали (по возрастанию)
				'backfill_done' => false,
				'last_run'      => 0,
				'last_ok'       => 0,
				'errors'        => array(),
			),
			is_array( $s ) ? $s : array()
		);
	}

	public static function patch_state( array $values ) {
		update_option( self::STATE, array_merge( self::state(), $values ), false );
	}

	public static function note_error( $msg ) {
		$st       = self::state();
		$errors   = is_array( $st['errors'] ) ? $st['errors'] : array();
		$errors[] = array( 't' => time(), 'm' => mb_substr( (string) $msg, 0, 300 ) );
		self::patch_state( array( 'errors' => array_slice( $errors, -10 ) ) );
	}
}
