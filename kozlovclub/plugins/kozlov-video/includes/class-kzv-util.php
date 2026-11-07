<?php
/**
 * Чистые функции без обращения к базе: разбор ссылок, даты, длительность, ключ названия.
 */

defined( 'ABSPATH' ) || exit;

final class KZV_Util {

	const MONTHS = array( 1 => 'января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря' );

	/**
	 * Ссылка на видео VK: vk.com/video-77211165_456245684, vkvideo.ru/video-…, video_ext.php?oid=…&id=…
	 *
	 * @return int[]|null [ oid, id ]
	 */
	public static function parse_vk( $url ) {
		$url = (string) $url;
		if ( '' === $url ) {
			return null;
		}
		if ( ! preg_match( '~^https?://(?:[a-z0-9-]+\.)?(?:vk\.com|vk\.ru|vkvideo\.ru)(?:/|$)~i', $url ) ) {
			return null;
		}
		if ( preg_match( '~(?<![a-z_])video(-?\d+)_(\d+)~i', rawurldecode( $url ), $m ) ) {
			return array( (int) $m[1], (int) $m[2] );
		}
		if ( preg_match( '~/video_ext\.php\?~i', $url ) ) {
			$q = array();
			parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
			if ( isset( $q['oid'], $q['id'] ) && preg_match( '/^-?\d+$/', (string) $q['oid'] ) && preg_match( '/^\d+$/', (string) $q['id'] ) ) {
				return array( (int) $q['oid'], (int) $q['id'] );
			}
		}
		return null;
	}

	/** ID видео YouTube из ссылки watch?v=, youtu.be/, /live/, /embed/. */
	public static function parse_yt( $url ) {
		$url = (string) $url;
		if ( preg_match( '~^https?://(?:www\.|m\.)?(?:youtube\.com/(?:watch\?(?:.*&)?v=|live/|embed/|shorts/)|youtu\.be/)([A-Za-z0-9_-]{11})(?![A-Za-z0-9_-])~', $url, $m ) ) {
			return $m[1];
		}
		return null;
	}

	public static function vk_embed( $oid, $id, $autoplay = false ) {
		$args = array(
			'oid' => (int) $oid,
			'id'  => (int) $id,
			'hd'  => 2,
		);
		if ( $autoplay ) {
			$args['autoplay'] = 1;
		}
		return 'https://vkvideo.ru/video_ext.php?' . http_build_query( $args, '', '&' );
	}

	public static function vk_watch( $oid, $id ) {
		return 'https://vkvideo.ru/video' . (int) $oid . '_' . (int) $id;
	}

	public static function yt_watch( $yt ) {
		return 'https://www.youtube.com/watch?v=' . rawurlencode( (string) $yt );
	}

	public static function yt_thumb( $yt ) {
		return 'https://i.ytimg.com/vi/' . rawurlencode( (string) $yt ) . '/hqdefault.jpg';
	}

	/** ISO-время StreamVi (UTC) в Unix. */
	public static function ts( $iso ) {
		$iso = trim( (string) $iso );
		if ( '' === $iso ) {
			return 0;
		}
		try {
			return ( new DateTimeImmutable( $iso, new DateTimeZone( 'UTC' ) ) )->getTimestamp();
		} catch ( \Throwable $e ) {
			return 0;
		}
	}

	/** «2 ч 11 мин», «47 мин». */
	public static function duration_label( $seconds ) {
		$min = (int) round( max( 0, (int) $seconds ) / 60 );
		if ( $min < 60 ) {
			return $min . ' мин';
		}
		$h = intdiv( $min, 60 );
		$m = $min % 60;
		return $h . ' ч' . ( $m ? ' ' . $m . ' мин' : '' );
	}

	/** ISO 8601 для разметки: PT2H11M. */
	public static function duration_iso( $seconds ) {
		$s = max( 0, (int) $seconds );
		$h = intdiv( $s, 3600 );
		$m = intdiv( $s % 3600, 60 );
		return 'PT' . ( $h ? $h . 'H' : '' ) . ( $m || ! $h ? $m . 'M' : '' );
	}

	/** «7 ноября 2026» по часам клуба. */
	public static function date_label( $ts, DateTimeZone $tz, $with_year = true ) {
		$d = ( new DateTimeImmutable( '@' . (int) $ts ) )->setTimezone( $tz );
		return (int) $d->format( 'j' ) . ' ' . self::MONTHS[ (int) $d->format( 'n' ) ] . ( $with_year ? ' ' . $d->format( 'Y' ) : '' );
	}

	/** «сегодня в 20:00», «завтра в 20:00», «12 ноября в 20:00». */
	public static function when_label( $ts, $now, DateTimeZone $tz ) {
		$d     = ( new DateTimeImmutable( '@' . (int) $ts ) )->setTimezone( $tz );
		$today = ( new DateTimeImmutable( '@' . (int) $now ) )->setTimezone( $tz )->setTime( 0, 0 );
		$day   = $d->setTime( 0, 0 );
		$diff  = (int) round( ( $day->getTimestamp() - $today->getTimestamp() ) / DAY_IN_SECONDS );
		$time  = $d->format( 'H:i' );
		if ( 0 === $diff ) {
			return 'сегодня в ' . $time;
		}
		if ( 1 === $diff ) {
			return 'завтра в ' . $time;
		}
		return self::date_label( $ts, $tz, $d->format( 'Y' ) !== $today->format( 'Y' ) ) . ' в ' . $time;
	}

	/**
	 * Ключ названия для сравнения концертов одного артиста:
	 * регистр, «ё», кавычки, номера повторов и всё после двоеточия не важны.
	 */
	public static function title_key( $title ) {
		$t = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $title, 'UTF-8' ) : strtolower( (string) $title );
		$t = str_replace( 'ё', 'е', $t );
		$t = preg_replace( '/\[[^\]]*\]|\([^)]*\)/u', ' ', $t );
		$t = preg_replace( '/:.*$/u', '', $t );
		$t = preg_replace( '/[«»"“”„\'’`.,!?]+/u', ' ', $t );
		$t = preg_replace( '/\s+/u', ' ', $t );
		return trim( (string) $t );
	}

	const MONTHS_NOM = array( 1 => 'Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь' );
	const WEEKDAYS   = array( 1 => 'пн', 'вт', 'ср', 'чт', 'пт', 'сб', 'вс' );

	/** «Ноябрь 2026» для заголовков месяцев в архиве. */
	public static function month_label( $ts, DateTimeZone $tz ) {
		$d = ( new DateTimeImmutable( '@' . (int) $ts ) )->setTimezone( $tz );
		return self::MONTHS_NOM[ (int) $d->format( 'n' ) ] . ' ' . $d->format( 'Y' );
	}

	/** День для программы эфиров: «Сегодня», «Завтра», «Пн, 9 ноября». */
	public static function day_label( $ts, $now, DateTimeZone $tz ) {
		$d     = ( new DateTimeImmutable( '@' . (int) $ts ) )->setTimezone( $tz );
		$today = ( new DateTimeImmutable( '@' . (int) $now ) )->setTimezone( $tz )->setTime( 0, 0 );
		$diff  = (int) round( ( $d->setTime( 0, 0 )->getTimestamp() - $today->getTimestamp() ) / DAY_IN_SECONDS );
		if ( 0 === $diff ) {
			return 'Сегодня';
		}
		if ( 1 === $diff ) {
			return 'Завтра';
		}
		return self::ucfirst( self::WEEKDAYS[ (int) $d->format( 'N' ) ] ) . ', ' . (int) $d->format( 'j' ) . ' ' . self::MONTHS[ (int) $d->format( 'n' ) ];
	}

	public static function time_label( $ts, DateTimeZone $tz ) {
		return ( new DateTimeImmutable( '@' . (int) $ts ) )->setTimezone( $tz )->format( 'H:i' );
	}

	/** Заглавная первая буква (для русских строк). */
	public static function ucfirst( $s ) {
		$s = (string) $s;
		return mb_strtoupper( mb_substr( $s, 0, 1 ) ) . mb_substr( $s, 1 );
	}

	/** Название для показа: убираем хвосты вроде «[https://kozlovclub.ru/…]». */
	public static function clean_title( $title ) {
		$t = preg_replace( '~\s*\[https?://[^\]]*\]~u', '', (string) $title );
		$t = preg_replace( '/\s+/u', ' ', (string) $t );
		return trim( (string) $t );
	}
}
