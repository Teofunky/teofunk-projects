<?php
/**
 * Выборка концертов для посадочных страниц.
 *
 * Только читает: таблицу All-in-One Event Calendar `{prefix}ai1ec_events`, записи
 * ai1ec_event и их поля Magic Fields. Ничего не записывает.
 *
 * Время: календарь хранит unix-время, посчитанное из введённого времени в поясе события
 * (на сайте это «UTC»). Восстанавливаем введённое «настенное» время и считаем его
 * московским, как это делает сайт (та же логика, что в плагине Kozlov Event Schema).
 *
 * @package Kozlov_Afisha_Pages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'Kozlov_Afisha_Data' ) ) {
	return;
}

/**
 * Data.
 */
class Kozlov_Afisha_Data {

	const POST_TYPE = 'ai1ec_event';

	const CLUB_TZ = 'Europe/Moscow';

	/** Если нет времени окончания, концерт считается идущим 4 часа после начала. */
	const DEFAULT_LENGTH = 14400;

	/** Окончание позже чем через неделю считаем ошибкой ввода. */
	const MAX_DURATION = 604800;

	/** Предел строк календаря за один запрос. */
	const MAX_ROWS = 1500;

	const DAY = 86400;

	/** Поле Magic Fields с жанрами. */
	const GENRE_META = 'genre_categories';

	/** Поле с ID события Ticketscloud. */
	const TICKETS_META = 'ticketscloud_link';

	/** Метки «бесплатно» в жанрах (после нормализации). */
	const FREE_TAGS = array( 'вход-свободный', 'free' );

	/**
	 * Кэш строк календаря на время запроса.
	 *
	 * @var array
	 */
	private static $rows_cache = array();

	/**
	 * Ошибка чтения календаря в последнем запросе (для подсказки редактору).
	 *
	 * @var string
	 */
	private static $last_error = '';

	/**
	 * Счётчики последней выборки: пропущенные повторяющиеся и с нечитаемой датой.
	 *
	 * @var array
	 */
	private static $stats = array(
		'rows'      => 0,
		'recurring' => 0,
		'bad_time'  => 0,
	);

	/**
	 * Сцены: ключ шорткода => поле Magic Fields и подпись (как на сайте).
	 *
	 * @return array
	 */
	public static function stages() {
		return array(
			'main_scene'   => array(
				'meta'  => 'location_main_scene',
				'label' => 'Главная сцена',
			),
			'mansarda'     => array(
				'meta'  => 'location_mansarda',
				'label' => 'Мансарда',
			),
			'the_beat'     => array(
				'meta'  => 'location_the_beat',
				'label' => 'The Beat',
			),
			'myasnitskaya' => array(
				'meta'  => 'location_myasnitskaya',
				'label' => 'Сцена на Мясницкой, 15',
			),
		);
	}

	/**
	 * Приводит параметры шорткода к проверенному виду.
	 *
	 * @param array $atts Параметры.
	 * @return array
	 */
	public static function normalize_args( array $atts ) {
		$get = function ( $key, $fallback ) use ( $atts ) {
			return isset( $atts[ $key ] ) && is_scalar( $atts[ $key ] ) ? trim( (string) $atts[ $key ] ) : $fallback;
		};

		// Замечания для редактора: видны только тем, кто может править страницы.
		$warnings = array();

		$genres = array();
		foreach ( explode( ',', $get( 'genre', '' ) ) as $genre ) {
			$genre = self::norm( $genre );
			if ( '' !== $genre && ! in_array( $genre, $genres, true ) ) {
				$genres[] = $genre;
			}
		}

		$aliases       = array(
			'main'         => 'main_scene',
			'main_scene'   => 'main_scene',
			'glavnaya'     => 'main_scene',
			'mansarda'     => 'mansarda',
			'the_beat'     => 'the_beat',
			'thebeat'      => 'the_beat',
			'beat'         => 'the_beat',
			'myasnitskaya' => 'myasnitskaya',
			'unplugged'    => 'myasnitskaya',
		);
		$stages        = array();
		$stage_invalid = false;
		foreach ( explode( ',', $get( 'stage', '' ) ) as $stage ) {
			$stage = self::lower( trim( str_replace( array( '-', ' ' ), '_', $stage ) ) );
			if ( '' === $stage ) {
				continue;
			}
			if ( isset( $aliases[ $stage ] ) ) {
				if ( ! in_array( $aliases[ $stage ], $stages, true ) ) {
					$stages[] = $aliases[ $stage ];
				}
			} else {
				$warnings[] = 'Неизвестная сцена «' . $stage . '». Можно: main_scene, mansarda, the_beat, myasnitskaya.';
				// Опечатка в сцене не должна молча выводить все сцены.
				$stage_invalid = true;
			}
		}

		$when = self::lower( $get( 'when', 'upcoming' ) );
		if ( ! in_array( $when, array( 'upcoming', 'today', 'weekend' ), true ) ) {
			$warnings[] = 'Неизвестное значение when="' . $when . '". Можно: upcoming, today, weekend. Сейчас выводятся ближайшие концерты.';
			$when       = 'upcoming';
		}

		$group = self::lower( $get( 'group', '' ) );
		if ( '' !== $group && ! in_array( $group, array( 'month', 'day', 'none' ), true ) ) {
			$warnings[] = 'Неизвестное значение group="' . $group . '". Можно: month, day, none.';
		}
		if ( ! in_array( $group, array( 'month', 'day', 'none' ), true ) ) {
			$group = 'upcoming' === $when ? 'month' : 'day';
		}

		$days_raw  = $get( 'days', '60' );
		$limit_raw = $get( 'limit', '60' );
		if ( ! preg_match( '/^\d{1,3}$/', $days_raw ) || (int) $days_raw < 1 || (int) $days_raw > 366 ) {
			$warnings[] = 'days="' . $days_raw . '": нужно число от 1 до 366.';
		}
		if ( ! preg_match( '/^\d{1,3}$/', $limit_raw ) || (int) $limit_raw < 1 || (int) $limit_raw > 200 ) {
			$warnings[] = 'limit="' . $limit_raw . '": нужно число от 1 до 200.';
		}
		$days  = (int) $days_raw;
		$limit = (int) $limit_raw;

		return array(
			'genre'         => $genres,
			'stage'         => $stages,
			'stage_invalid' => $stage_invalid,
			'when'          => $when,
			'free'          => in_array( self::lower( $get( 'free', '0' ) ), array( '1', 'yes', 'true', 'да' ), true ),
			'days'          => max( 1, min( 366, $days > 0 ? $days : 60 ) ),
			'limit'         => max( 1, min( 200, $limit > 0 ? $limit : 60 ) ),
			'group'         => $group,
			'empty'         => sanitize_text_field( $get( 'empty', '' ) ),
			'more'          => ! in_array( self::lower( $get( 'more', '1' ) ), array( '0', 'no', 'false', 'нет' ), true ),
			'warnings'      => $warnings,
		);
	}

	/**
	 * Нижний регистр с учётом кириллицы.
	 *
	 * @param string $text Текст.
	 * @return string
	 */
	public static function lower( $text ) {
		$text = (string) $text;
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
	}

	/**
	 * Концерты для шорткода.
	 *
	 * @param array    $args Нормализованные параметры.
	 * @param int|null $now  Текущее время (unix), для тестов.
	 * @return array {items: array[], fallback: string, total: int, from: string, to: string, error: string}
	 */
	public static function select( array $args, $now = null ) {
		$now   = null === $now ? time() : (int) $now;
		$club  = new DateTimeZone( self::CLUB_TZ );
		$today = new DateTime( '@' . $now );
		$today->setTimezone( $club );
		$today_ymd = $today->format( 'Y-m-d' );

		$result = array(
			'items'    => array(),
			'fallback' => '',
			'total'    => 0,
			'from'     => $today_ymd,
			'to'       => $today_ymd,
			'error'    => '',
		);

		if ( 'today' === $args['when'] ) {
			$horizon = 15;
		} elseif ( 'weekend' === $args['when'] ) {
			$horizon = 17;
		} else {
			$horizon = (int) $args['days'];
		}

		$all             = self::filtered( $args, $now, $horizon );
		$result['error'] = self::$last_error;

		if ( 'today' === $args['when'] ) {
			$picked = self::between( $all, $today_ymd, $today_ymd );
			if ( empty( $picked ) ) {
				// Сегодня концертов больше нет: показываем ближайший день с концертами.
				foreach ( $all as $item ) {
					if ( $item['date'] > $today_ymd ) {
						$picked             = self::between( $all, $item['date'], $item['date'] );
						$result['fallback'] = $item['date'];
						$result['from']     = $item['date'];
						$result['to']       = $item['date'];
						break;
					}
				}
			}
		} elseif ( 'weekend' === $args['when'] ) {
			list( $friday, $sunday ) = self::weekend( $today );
			$picked                  = self::between( $all, $friday, $sunday );
			$result['from']          = $friday;
			$result['to']            = $sunday;
			if ( empty( $picked ) && $today_ymd >= $friday ) {
				// Эти выходные закончились: следующие.
				$next = new DateTime( $friday . ' 12:00:00', $club );
				$next->modify( '+7 days' );
				list( $friday, $sunday ) = self::weekend( $next );
				$picked                  = self::between( $all, $friday, $sunday );
				$result['fallback']      = $friday;
				$result['from']          = $friday;
				$result['to']            = $sunday;
			}
		} else {
			$last = clone $today;
			$last->modify( '+' . (int) $args['days'] . ' days' );
			$result['to'] = $last->format( 'Y-m-d' );
			$picked       = self::between( $all, '0000-00-00', $result['to'] );
		}

		$result['total'] = count( $picked );
		$result['items'] = array_slice( $picked, 0, (int) $args['limit'] );
		return $result;
	}

	/**
	 * Пятница и воскресенье выходных: текущих (пт-вс) или ближайших (пн-чт).
	 *
	 * @param DateTime $day День по Москве.
	 * @return string[] [Y-m-d пятницы, Y-m-d воскресенья]
	 */
	public static function weekend( DateTime $day ) {
		$n      = (int) $day->format( 'N' );
		$friday = clone $day;
		$friday->setTime( 12, 0, 0 );
		if ( $n < 5 ) {
			$friday->modify( '+' . ( 5 - $n ) . ' days' );
		} elseif ( $n > 5 ) {
			$friday->modify( '-' . ( $n - 5 ) . ' days' );
		}
		$sunday = clone $friday;
		$sunday->modify( '+2 days' );
		return array( $friday->format( 'Y-m-d' ), $sunday->format( 'Y-m-d' ) );
	}

	/**
	 * Концерты с датой начала в диапазоне (включительно).
	 *
	 * @param array  $items Концерты.
	 * @param string $from  Y-m-d.
	 * @param string $to    Y-m-d.
	 * @return array
	 */
	private static function between( array $items, $from, $to ) {
		$out = array();
		foreach ( $items as $item ) {
			if ( $item['date'] >= $from && $item['date'] <= $to ) {
				$out[] = $item;
			}
		}
		return $out;
	}

	/**
	 * Все ещё не закончившиеся концерты на горизонте, отфильтрованные по жанру, сцене и цене.
	 *
	 * @param array $args    Параметры.
	 * @param int   $now     Текущее время.
	 * @param int   $horizon Дней вперёд.
	 * @return array
	 */
	private static function filtered( array $args, $now, $horizon ) {
		self::$last_error = '';
		self::$stats      = array(
			'rows'      => 0,
			'recurring' => 0,
			'bad_time'  => 0,
		);
		if ( ! empty( $args['stage_invalid'] ) ) {
			return array();
		}

		$club = new DateTimeZone( self::CLUB_TZ );
		$last = new DateTime( '@' . $now );
		$last->setTimezone( $club );
		$last->modify( '+' . (int) $horizon . ' days' );
		$last_ymd = $last->format( 'Y-m-d' );

		// Сырые значения в таблице сдвинуты относительно настоящего UTC на величину пояса события,
		// поэтому окно SQL берём с запасом в двое суток, а точно отбираем ниже.
		$rows                = self::rows( $now - 2 * self::DAY, $now + ( $horizon + 2 ) * self::DAY );
		self::$stats['rows'] = count( $rows );
		if ( empty( $rows ) ) {
			return array();
		}

		$ids = array();
		foreach ( $rows as $row ) {
			$ids[] = (int) $row['post_id'];
		}
		self::prime( $ids );

		$items = array();
		$seen  = array();
		foreach ( $rows as $row ) {
			$id = (int) $row['post_id'];
			if ( isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;

			if ( '' !== trim( (string) $row['recurrence_rules'] ) ) {
				// Повторяющиеся события календаря не выводим (на сайте их нет, счётчик виден в справке).
				++self::$stats['recurring'];
				continue;
			}
			$times = self::times( $row );
			if ( null === $times ) {
				++self::$stats['bad_time'];
				continue;
			}
			if ( $times['finish_ts'] < $now || $times['date'] > $last_ymd ) {
				continue;
			}

			$post = get_post( $id );
			if ( ! ( $post instanceof WP_Post ) || self::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status || '' !== (string) $post->post_password ) {
				continue;
			}

			$genres = self::genres( $id );
			$cost   = self::cost( isset( $row['cost'] ) ? $row['cost'] : '' );
			// Метка «Вход свободный» в жанрах считается, только если в стоимости нет цены или депозита.
			$free   = $cost['free'] || ( ! $cost['has_price'] && (bool) array_intersect( array_keys( $genres ), self::FREE_TAGS ) );
			$stages = self::stage_keys( $id );

			if ( ! empty( $args['genre'] ) && ! array_intersect( $args['genre'], array_keys( $genres ) ) ) {
				continue;
			}
			if ( ! empty( $args['stage'] ) && ! array_intersect( $args['stage'], $stages ) ) {
				continue;
			}
			if ( ! empty( $args['free'] ) && ! $free ) {
				continue;
			}

			$items[] = array(
				'id'       => $id,
				'post'     => $post,
				'start'    => $times['start'],
				'start_ts' => $times['start_ts'],
				'date'     => $times['date'],
				'allday'   => $times['allday'],
				'cost'     => $cost['text'],
				'free'     => $free,
				'stages'   => $stages,
				'genres'   => array_values( array_unique( array_values( $genres ) ) ),
				'tickets'  => self::tickets_id( $id ),
			);
		}

		usort(
			$items,
			function ( $a, $b ) {
				if ( $a['start_ts'] === $b['start_ts'] ) {
					return $a['id'] - $b['id'];
				}
				return $a['start_ts'] < $b['start_ts'] ? -1 : 1;
			}
		);

		return $items;
	}

	/**
	 * Строки календаря в окне сырых значений `start`.
	 *
	 * @param int $from Нижняя граница.
	 * @param int $to   Верхняя граница.
	 * @return array
	 */
	private static function rows( $from, $to ) {
		global $wpdb;

		$key = $from . ':' . $to;
		if ( isset( self::$rows_cache[ $key ] ) ) {
			self::$last_error = self::$rows_cache[ $key ]['error'];
			return self::$rows_cache[ $key ]['rows'];
		}

		$table = $wpdb->prefix . 'ai1ec_events';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- имя таблицы из $wpdb->prefix, значения через плейсхолдеры.
		$sql      = $wpdb->prepare( "SELECT e.`post_id`, e.`start`, e.`end`, e.`timezone_name`, e.`allday`, e.`instant_event`, e.`recurrence_rules`, e.`cost` FROM `{$table}` e INNER JOIN `{$wpdb->posts}` p ON p.`ID` = e.`post_id` WHERE p.`post_type` = %s AND p.`post_status` = 'publish' AND p.`post_password` = '' AND e.`start` >= %d AND e.`start` <= %d ORDER BY e.`start` ASC, e.`post_id` ASC LIMIT %d", self::POST_TYPE, (int) $from, (int) $to, self::MAX_ROWS );
		$suppress = $wpdb->suppress_errors( true );
		$rows     = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		$error    = (string) $wpdb->last_error;
		$wpdb->suppress_errors( $suppress );

		if ( ! is_array( $rows ) && '' === $error ) {
			$error = 'не удалось прочитать таблицу календаря';
		}
		$rows                     = is_array( $rows ) ? $rows : array();
		self::$last_error         = $error;
		self::$rows_cache[ $key ] = array(
			'rows'  => $rows,
			'error' => $error,
		);
		return $rows;
	}

	/**
	 * Загружает записи, поля и картинки пачкой, чтобы не делать запрос на каждую карточку.
	 *
	 * @param int[] $ids ID записей.
	 */
	private static function prime( array $ids ) {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( empty( $ids ) ) {
			return;
		}
		if ( function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( $ids, false, true );
		} else {
			update_meta_cache( 'post', $ids );
		}
		$thumbs = array();
		foreach ( $ids as $id ) {
			$thumb = (int) get_post_meta( $id, '_thumbnail_id', true );
			if ( $thumb > 0 ) {
				$thumbs[] = $thumb;
			}
		}
		if ( ! empty( $thumbs ) && function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( array_values( array_unique( $thumbs ) ), false, true );
		}
	}

	/**
	 * Начало, окончание и дата концерта по Москве.
	 *
	 * @param array $row Строка календаря.
	 * @return array|null
	 */
	public static function times( array $row ) {
		$start_ts = isset( $row['start'] ) && is_numeric( $row['start'] ) ? (int) $row['start'] : 0;
		if ( $start_ts <= 0 ) {
			return null;
		}
		$end_ts  = isset( $row['end'] ) && is_numeric( $row['end'] ) ? (int) $row['end'] : 0;
		$allday  = ! empty( $row['allday'] );
		$instant = ! empty( $row['instant_event'] );

		try {
			$source = self::source_timezone( isset( $row['timezone_name'] ) ? $row['timezone_name'] : '' );
			$club   = new DateTimeZone( self::CLUB_TZ );
			$start  = self::wall_clock( $start_ts, $source, $club );
			$finish = null;
			if ( ! $instant && $end_ts > $start_ts && ( $end_ts - $start_ts ) <= self::MAX_DURATION ) {
				$finish = self::wall_clock( $end_ts, $source, $club )->getTimestamp();
			}
		} catch ( Exception $e ) {
			return null;
		}

		if ( $allday ) {
			$start->setTime( 0, 0, 0 );
			if ( null === $finish ) {
				$finish = $start->getTimestamp() + self::DAY;
			}
		} elseif ( null === $finish ) {
			$finish = $start->getTimestamp() + self::DEFAULT_LENGTH;
		}

		return array(
			'start'     => $start,
			'start_ts'  => $start->getTimestamp(),
			'finish_ts' => $finish,
			'date'      => $start->format( 'Y-m-d' ),
			'allday'    => $allday,
		);
	}

	/**
	 * Unix-время -> то же «настенное» время по Москве.
	 *
	 * @param int          $ts     Unix-время из календаря.
	 * @param DateTimeZone $source Пояс события.
	 * @param DateTimeZone $club   Пояс клуба.
	 * @return DateTime
	 */
	private static function wall_clock( $ts, DateTimeZone $source, DateTimeZone $club ) {
		$dt = new DateTime( '@' . (int) $ts );
		$dt->setTimezone( $source );
		return new DateTime( $dt->format( 'Y-m-d H:i:s' ), $club );
	}

	/**
	 * Пояс события; если пустой или неизвестный: пояс сайта, затем UTC.
	 *
	 * @param string $name Имя пояса.
	 * @return DateTimeZone
	 */
	private static function source_timezone( $name ) {
		$candidates = array( trim( (string) $name ), trim( (string) get_option( 'timezone_string' ) ), 'UTC' );
		foreach ( $candidates as $candidate ) {
			if ( '' === $candidate ) {
				continue;
			}
			try {
				return new DateTimeZone( $candidate );
			} catch ( Exception $e ) {
				continue;
			}
		}
		return new DateTimeZone( 'UTC' );
	}

	/**
	 * Жанры анонса: нормализованный ключ => подпись (русская часть).
	 * Ключи и русской, и английской части: «Джаз|Jazz» даёт «джаз» и «jazz».
	 *
	 * @param int $post_id ID записи.
	 * @return array
	 */
	public static function genres( $post_id ) {
		$raw  = get_post_meta( $post_id, self::GENRE_META, false );
		$flat = array();
		$walk = function ( $value ) use ( &$walk, &$flat ) {
			$value = maybe_unserialize( $value );
			if ( is_array( $value ) ) {
				foreach ( $value as $item ) {
					$walk( $item );
				}
			} elseif ( is_scalar( $value ) ) {
				$flat[] = (string) $value;
			}
		};
		$walk( $raw );

		$out = array();
		foreach ( $flat as $value ) {
			$parts = explode( '|', $value );
			$label = self::plain_text( $parts[0] );
			if ( '' === $label ) {
				continue;
			}
			foreach ( $parts as $part ) {
				$key = self::norm( $part );
				if ( '' !== $key && ! isset( $out[ $key ] ) ) {
					$out[ $key ] = $label;
				}
			}
		}
		return $out;
	}

	/**
	 * Все жанры ближайших концертов со счётчиками (для справки в админке).
	 *
	 * @param int $days Дней вперёд.
	 * @return array [подпись => [keys => string[], count => int]]
	 */
	public static function genre_stats( $days = 120 ) {
		$args          = self::normalize_args( array() );
		$args['days']  = (int) $days;
		$args['limit'] = 200;
		$now           = time();
		$items         = self::filtered( $args, $now, (int) $days );
		$stats         = array();
		foreach ( $items as $item ) {
			foreach ( self::genres( $item['id'] ) as $key => $label ) {
				if ( ! isset( $stats[ $label ] ) ) {
					$stats[ $label ] = array(
						'keys'  => array(),
						'count' => 0,
						'ids'   => array(),
					);
				}
				if ( ! in_array( $key, $stats[ $label ]['keys'], true ) ) {
					$stats[ $label ]['keys'][] = $key;
				}
				$stats[ $label ]['ids'][ $item['id'] ] = true;
			}
		}
		foreach ( $stats as $label => $row ) {
			$stats[ $label ]['count'] = count( $row['ids'] );
			unset( $stats[ $label ]['ids'] );
		}
		uasort(
			$stats,
			function ( $a, $b ) {
				return $b['count'] - $a['count'];
			}
		);
		return $stats;
	}

	/**
	 * Проверка данных для справки: сколько строк календаря прочитано, сколько концертов выводится,
	 * сколько пропущено и почему.
	 *
	 * @param int $days Дней вперёд.
	 * @return array {rows, shown, recurring, bad_time, error}
	 */
	public static function diagnostics( $days = 60 ) {
		$args  = self::normalize_args( array() );
		$items = self::filtered( $args, time(), (int) $days );
		return array(
			'rows'      => self::$stats['rows'],
			'shown'     => count( $items ),
			'recurring' => self::$stats['recurring'],
			'bad_time'  => self::$stats['bad_time'],
			'error'     => self::$last_error,
		);
	}

	/**
	 * Отмеченные сцены (ключи шорткода).
	 *
	 * @param int $post_id ID записи.
	 * @return string[]
	 */
	public static function stage_keys( $post_id ) {
		$found = array();
		foreach ( self::stages() as $key => $stage ) {
			$value = get_post_meta( $post_id, $stage['meta'], true );
			if ( is_scalar( $value ) && '1' === trim( (string) $value ) ) {
				$found[] = $key;
			}
		}
		return $found;
	}

	/**
	 * ID события Ticketscloud для кнопки «Купить билет» или пустая строка.
	 *
	 * @param int $post_id ID записи.
	 * @return string
	 */
	public static function tickets_id( $post_id ) {
		$value = get_post_meta( $post_id, self::TICKETS_META, true );
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		if ( '' === $value ) {
			return '';
		}
		if ( preg_match( '/^[A-Za-z0-9_-]{6,64}$/', $value ) ) {
			return $value;
		}
		if ( preg_match( '/(?<![a-f0-9])([a-f0-9]{24})(?![a-f0-9])/i', $value, $m ) ) {
			return $m[1];
		}
		return '';
	}

	/**
	 * Текст стоимости для карточки и признак бесплатного входа.
	 * Бесплатным считается только текст без цены и депозита: «1500 руб., детям бесплатно» не бесплатно.
	 *
	 * @param mixed $raw Значение поля «Стоимость».
	 * @return array {text: string, free: bool, has_price: bool}
	 */
	public static function cost( $raw ) {
		$value   = maybe_unserialize( $raw );
		$is_free = false;
		if ( is_array( $value ) ) {
			$is_free = ! empty( $value['is_free'] );
			$value   = isset( $value['cost'] ) && is_scalar( $value['cost'] ) ? (string) $value['cost'] : '';
		}
		$text      = self::plain_text( is_scalar( $value ) ? (string) $value : '' );
		$text      = trim( str_replace( array( "\xC2\xA0", "\xE2\x80\xAF", "\xE2\x80\x89", "\xE2\x80\x8A" ), ' ', $text ) );
		$has_price = (bool) preg_match( '/депозит|deposit|\d\s*(руб|₽|р\.|rub)|(?<!\d)\d{3,}/iu', $text );
		$free      = ! $has_price && ( $is_free || ( '' !== $text && preg_match( '/(вход\s*свободн|свободн\S*\s+вход|бесплатн|free\s+entry|вход\s+free)/iu', $text ) ) );
		if ( '' === $text && $free ) {
			$text = 'Вход свободный';
		}
		return array(
			'text'      => $text,
			'free'      => (bool) $free,
			'has_price' => $has_price,
		);
	}

	/**
	 * Нормализация для сравнения жанров: нижний регистр, ё -> е, пробелы и дефисы -> «-».
	 *
	 * @param string $text Текст.
	 * @return string
	 */
	public static function norm( $text ) {
		$text = self::plain_text( $text );
		$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
		$text = str_replace( 'ё', 'е', $text );
		$text = preg_replace( '/[\s_\-–—]+/u', '-', $text );
		return trim( (string) $text, '-' );
	}

	/**
	 * Русская часть текста с языковыми метками WPGlobus / qTranslate.
	 *
	 * @param string $text Текст.
	 * @return string
	 */
	public static function ru_text( $text ) {
		$text = is_scalar( $text ) ? (string) $text : '';
		if ( '' === $text ) {
			return '';
		}
		if ( false !== strpos( $text, '{:' ) && preg_match( '/\{:ru\}(.*?)\{:\}/su', $text, $m ) ) {
			return $m[1];
		}
		if ( false !== strpos( $text, '[:' ) && preg_match( '/\[:ru\](.*?)(?=\[:[a-z]{0,2}\]|$)/su', $text, $m ) ) {
			return $m[1];
		}
		if ( false !== strpos( $text, '<!--:' ) && preg_match( '/<!--:ru-->(.*?)<!--:-->/su', $text, $m ) ) {
			return $m[1];
		}
		$clean = preg_replace( '/\{:[a-z]{0,2}\}|\[:[a-z]{0,2}\]|<!--:[a-z]{0,2}-->/u', '', $text );
		return null === $clean ? $text : $clean;
	}

	/**
	 * HTML -> чистый текст одной строкой.
	 *
	 * @param string $html Текст.
	 * @return string
	 */
	public static function plain_text( $html ) {
		$text = self::ru_text( $html );
		if ( '' === $text ) {
			return '';
		}
		$text   = wp_strip_all_tags( $text );
		$text   = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$single = preg_replace( '/\s+/u', ' ', $text );
		$text   = null === $single ? preg_replace( '/\s+/', ' ', $text ) : $single;
		return trim( (string) $text );
	}
}
