<?php
/**
 * Концерты главной сцены из афиши. Источник тот же, что у Kozlov StreamVi
 * (All-in-One Event Calendar на текущем сайте или Kozlov Core на новом). Только чтение.
 */

defined( 'ABSPATH' ) || exit;

final class KZV_Concerts {

	/** Окно поиска концерта для трансляции: эфир начинается за 30 мин до и до 90 мин после начала по афише. */
	const BEFORE = 30 * MINUTE_IN_SECONDS;
	const AFTER  = 90 * MINUTE_IN_SECONDS;

	public static function available() {
		if ( ! class_exists( 'KZSV_Source' ) ) {
			return false;
		}
		try {
			return null !== KZSV_Source::get();
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Концерты главной сцены с началом в [$from, $to].
	 *
	 * @return array[] [ post_id, start, end, title, url, skip ]
	 */
	public static function events( $from, $to ) {
		if ( ! self::available() ) {
			return array();
		}
		try {
			$src = KZSV_Source::get();
			$out = array();
			foreach ( (array) $src->events( (int) $from, (int) $to ) as $e ) {
				$out[] = array(
					'post_id' => (int) ( $e['post_id'] ?? 0 ),
					'start'   => (int) ( $e['start'] ?? 0 ),
					'end'     => (int) ( $e['end'] ?? 0 ),
					'title'   => (string) ( $e['title'] ?? '' ),
					'url'     => (string) ( $e['url'] ?? '' ),
					'skip'    => ! empty( $e['skip'] ),
				);
			}
			return $out;
		} catch ( \Throwable $e ) {
			kzv_log_exception( $e );
			return array();
		}
	}

	/**
	 * Концерт для трансляции: ближайший по началу в окне. Концерты с отметкой «не транслируется»
	 * (правило или галочка в Kozlov StreamVi) не рассматриваются: их эфира не бывает.
	 *
	 * @param int     $started Начало эфира (Unix).
	 * @param array[] $events  Концерты, уже выбранные на нужный период.
	 * @return array|null
	 */
	public static function match( $started, array $events ) {
		$best  = null;
		$score = PHP_INT_MAX;
		foreach ( $events as $e ) {
			if ( $e['skip'] ) {
				continue;
			}
			$d = (int) $started - (int) $e['start']; // >0: эфир позже начала по афише
			if ( $d < -self::BEFORE || $d > self::AFTER ) {
				continue;
			}
			$s = abs( $d );
			if ( $s < $score ) {
				$score = $s;
				$best  = $e;
			}
		}
		return $best;
	}

	/**
	 * Жанры концерта из афиши (поле genre_categories, как в фильтрах афиши и поиске): коды через запятую.
	 * Пусто, если жанров нет; «-», чтобы не перечитывать концерт без жанров.
	 */
	public static function genres( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return '';
		}
		$raw = get_post_meta( $post_id, 'genre_categories', true );
		if ( is_string( $raw ) ) {
			$raw = maybe_unserialize( $raw );
		}
		$codes = array();
		foreach ( (array) $raw as $one ) {
			if ( ! is_string( $one ) || '' === trim( $one ) ) {
				continue;
			}
			$parts = explode( '|', $one );
			$code  = strtolower( trim( wp_strip_all_tags( isset( $parts[1] ) ? $parts[1] : $parts[0] ) ) );
			if ( '' === $code || 'free' === $code || strlen( $code ) > 40 || false !== strpos( $code, ',' ) ) {
				continue;
			}
			$codes[ $code ] = true;
		}
		$out = implode( ',', array_keys( $codes ) );
		return '' !== $out ? mb_substr( $out, 0, 255 ) : '-';
	}

	/**
	 * Следующий концерт, который будет в эфире (без отметки «не транслируется»),
	 * и тот, что идёт сейчас (начался не больше 3 часов назад).
	 *
	 * @return array [ current => array|null, next => array|null ]
	 */
	public static function around( $now ) {
		$events  = self::events( $now - 3 * HOUR_IN_SECONDS, $now + 14 * DAY_IN_SECONDS );
		$current = null;
		$next    = null;
		foreach ( $events as $e ) {
			if ( $e['skip'] ) {
				continue;
			}
			$end = $e['end'] > $e['start'] ? $e['end'] : $e['start'] + 3 * HOUR_IN_SECONDS;
			if ( $e['start'] <= $now + 15 * MINUTE_IN_SECONDS && $end > $now ) {
				$current = $e;
			} elseif ( $e['start'] > $now && null === $next ) {
				$next = $e;
			}
		}
		if ( $current && $next && $next['start'] <= $current['start'] ) {
			$next = null;
		}
		return array(
			'current' => $current,
			'next'    => $next,
		);
	}

	/**
	 * Ближайший концерт с тем же названием (артист снова на главной сцене).
	 *
	 * @return array|null
	 */
	public static function upcoming_same( $title, $now ) {
		$key = KZV_Util::title_key( $title );
		if ( '' === $key || mb_strlen( $key ) < 4 ) {
			return null;
		}
		$cache = 'kzv_upcoming_' . (int) floor( $now / ( 15 * MINUTE_IN_SECONDS ) );
		$list  = get_transient( $cache );
		if ( ! is_array( $list ) ) {
			$list = self::events( $now, $now + 120 * DAY_IN_SECONDS );
			set_transient( $cache, $list, 20 * MINUTE_IN_SECONDS );
		}
		foreach ( $list as $e ) {
			if ( $e['start'] > $now && KZV_Util::title_key( $e['title'] ) === $key ) {
				return $e;
			}
		}
		return null;
	}
}
