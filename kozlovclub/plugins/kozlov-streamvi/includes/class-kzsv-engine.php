<?php
/**
 * Правила переключения. Чистая логика без WordPress: легко проверить тестами.
 *
 * Для каждого концерта главной сцены вычисляется момент переключения (act):
 *   1. за lead минут до начала (прежнее правило: за час);
 *   2. раньше, если предыдущий концерт закончился: через hold минут после его начала
 *      или в его время окончания по афише, что позже (правило «после окончания»);
 *   3. не раньше начала предыдущего концерта (концерт не «перебивает» ещё не начавшийся).
 * В каждый момент в StreamVi должно стоять название концерта с последним наступившим act.
 * Концерты с отметкой «не транслируется» (галочка или правило) пропускаются целиком.
 */

defined( 'ABSPATH' ) || exit;

final class KZSV_Engine {

	/**
	 * @param array $events Концерты (key, start, end, skip ...).
	 * @param int   $lead   Секунд до начала.
	 * @param int   $hold   Секунд после начала, когда концерт считается законченным.
	 * @return array Те же концерты по порядку, с полями act (или null для пропущенных) и prev_key.
	 */
	public static function timeline( array $events, $lead, $hold ) {
		usort(
			$events,
			function ( $a, $b ) {
				if ( $a['start'] === $b['start'] ) {
					return strcmp( (string) $a['key'], (string) $b['key'] );
				}
				return ( $a['start'] < $b['start'] ) ? -1 : 1;
			}
		);
		$out  = array();
		$prev = null;
		foreach ( $events as $e ) {
			$e['prev_key'] = $prev ? $prev['key'] : '';
			if ( ! empty( $e['skip'] ) ) {
				$e['act'] = null;
				$out[]    = $e;
				continue;
			}
			$act = (int) $e['start'] - (int) $lead;
			if ( $prev ) {
				$act = min( $act, self::release( $prev, $hold ) );
				$act = max( $act, (int) $prev['start'] );
			}
			$e['act'] = $act;
			$out[]    = $e;
			$prev     = $e;
		}
		return $out;
	}

	/** Когда концерт считается законченным: через hold после начала или в конец по афише, что позже. */
	public static function release( array $e, $hold ) {
		$by_hold = (int) $e['start'] + (int) $hold;
		$by_end  = ! empty( $e['end'] ) ? (int) $e['end'] : 0;
		return max( $by_hold, $by_end );
	}

	/**
	 * Какой концерт должен стоять в StreamVi сейчас и какой будет следующим.
	 *
	 * @return array [ текущий|null, следующий|null ]
	 */
	public static function desired( array $timeline, $now ) {
		$cur  = null;
		$next = null;
		foreach ( $timeline as $e ) {
			if ( null === $e['act'] ) {
				continue;
			}
			if ( $e['act'] <= $now ) {
				$cur = $e;
			} elseif ( null === $next ) {
				$next = $e;
			}
		}
		return array( $cur, $next );
	}

	/**
	 * Защита идущего эфира при смене концерта. Работает «на закрытие»: переключение разрешено
	 * только когда StreamVi подтвердил, что эфира нет. Если эфир идёт или статус неизвестен
	 * (ошибка, непонятный ответ), название не меняется. Единственное исключение, если оно включено:
	 * за $force_before секунд до начала нового концерта название ставится в любом случае,
	 * чтобы концерт не начался под чужим названием.
	 *
	 * @param array      $target       Концерт, на который нужно переключить (start).
	 * @param array|null $live         [ 'state' => live|offline|unknown ] или null, если статус узнать не удалось.
	 * @param int        $now          Сейчас.
	 * @param int        $force_before Секунд до начала.
	 * @param bool       $force        Разрешено ли переключение «в крайнем случае».
	 * @return array [ bool можно, string причина: offline | live | unknown | deadline ]
	 */
	public static function guard( array $target, $live, $now, $force_before, $force = true ) {
		$state = ( is_array( $live ) && isset( $live['state'] ) ) ? (string) $live['state'] : 'unknown';
		if ( 'offline' === $state ) {
			return array( true, 'offline' );
		}
		if ( $force && $now >= (int) $target['start'] - (int) $force_before ) {
			return array( true, 'deadline' );
		}
		return array( false, 'live' === $state ? 'live' : 'unknown' );
	}

	/** Отпечаток содержимого: понять, что название или анонс текущего концерта поправили. */
	public static function hash( array $e ) {
		return md5( (string) $e['title'] . "\n" . (string) $e['desc'] );
	}
}
