<?php
/**
 * Откуда берутся концерты главной сцены.
 *  - ai1ec: текущий сайт (All-in-One Event Calendar + поля темы, сцена в поле location_main_scene).
 *  - core:  новый сайт (Kozlov Core: тип записи concert, сцена main-scene).
 * Только чтение. Ничего в базе календаря не меняется.
 */

defined( 'ABSPATH' ) || exit;

abstract class KZSV_Source {

	/** Поля плагина в самом концерте (метабокс «Трансляция»). */
	const META_SKIP  = '_kzsv_skip';
	const META_TITLE = '_kzsv_title';
	const META_DESC  = '_kzsv_desc';

	abstract public function id();

	abstract public function label();

	abstract public function available();

	/** Типы записей концертов: для метабокса и для реакции на правку. */
	abstract public function post_types();

	/**
	 * Концерты главной сцены с началом в [$from, $to] (настоящее время, Unix).
	 *
	 * @return array[] Каждый: key, post_id, start, end (0 если не указано), title, desc, url, skip, custom.
	 */
	abstract public function events( $from, $to );

	/** Сцена этого концерта главная? (для подсказки в метабоксе) */
	abstract public function is_main_stage( $post_id );

	/** Тексты анонса по порядку вывода на странице концерта. */
	abstract protected function texts( $post_id );

	/**
	 * Выбранный источник: явно в настройках или автоматически (новый сайт первым).
	 *
	 * @return KZSV_Source|null
	 */
	public static function get() {
		static $cached = null;
		if ( null !== $cached ) {
			return $cached ? $cached : null;
		}
		$want = (string) KZSV_Settings::get( 'source' );
		$all  = array(
			'core'  => new KZSV_Source_Core(),
			'ai1ec' => new KZSV_Source_Ai1ec(),
		);
		$found = false;
		if ( isset( $all[ $want ] ) ) {
			$found = $all[ $want ]->available() ? $all[ $want ] : false;
		} elseif ( $all['core']->available() && $all['core']->has_upcoming() ) {
			$found = $all['core']; // новый сайт, и афиша в нём уже заполнена
		} elseif ( $all['ai1ec']->available() ) {
			$found = $all['ai1ec']; // Kozlov Core нет или он ещё пустой: остаёмся на календаре текущего сайта
		} elseif ( $all['core']->available() ) {
			$found = $all['core'];
		}
		$cached = $found;
		return $found ? $found : null;
	}

	/** Есть ли в источнике концерты главной сцены на ближайшие две недели. */
	public function has_upcoming() {
		try {
			$now = class_exists( 'KZSV_Runner' ) ? KZSV_Runner::now() : time();
			return count( $this->events( $now, $now + 14 * DAY_IN_SECONDS ) ) > 0;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/** Типы записей концертов обоих сайтов (метабокс и реакция на правку работают на любом из них). */
	public static function all_post_types() {
		return array( 'ai1ec_event', 'concert' );
	}

	/**
	 * Название и описание, которые уйдут в StreamVi для этого концерта.
	 *
	 * @return array [ title, desc, custom ] custom = заданы вручную в концерте.
	 */
	public function compose( $post_id, $title_raw, $url, $start ) {
		$s      = KZSV_Settings::get();
		$custom = false;

		$title = trim( (string) get_post_meta( $post_id, self::META_TITLE, true ) );
		if ( '' !== $title ) {
			$custom = true;
		} else {
			$title = KZSV_Text::title( $title_raw );
		}
		$title = KZSV_Text::clip( KZSV_Text::platform_safe( str_replace( "\n", ' ', $title ) ), (int) $s['title_max'] );

		$desc = trim( (string) get_post_meta( $post_id, self::META_DESC, true ) );
		if ( '' !== $desc ) {
			$custom = true;
		} else {
			$desc = KZSV_Text::first_paragraph( $this->texts( $post_id ), (int) $s['par_min'] );
		}
		if ( '' === $desc ) {
			$desc = (string) $s['desc_fallback'];
		}
		$suffix = trim( (string) $s['desc_suffix'] );
		if ( '' !== $suffix ) {
			$suffix = strtr(
				$suffix,
				array(
					'{url}'  => (string) $url,
					'{date}' => wp_date( 'd.m.Y', (int) $start, KZSV_Settings::tz() ),
					'{time}' => wp_date( 'H:i', (int) $start, KZSV_Settings::tz() ),
				)
			);
		}
		$desc = KZSV_Text::platform_safe( $desc );
		$max  = (int) $s['desc_max'];
		if ( '' !== $suffix ) {
			// строка в конце занимает не больше половины лимита, анонс получает остаток
			$suffix = KZSV_Text::clip( KZSV_Text::platform_safe( $suffix ), (int) floor( $max / 2 ) );
			$room   = $max - KZSV_Text::len( $suffix ) - 2;
			$desc   = ( '' !== $desc && $room >= 20 ) ? KZSV_Text::clip( $desc, $room, true ) . "\n\n" . $suffix : ( '' !== $desc ? KZSV_Text::clip( $desc, $max, true ) : $suffix );
		} else {
			$desc = KZSV_Text::clip( $desc, $max, true );
		}
		if ( KZSV_Text::len( $desc ) > $max ) {
			$desc = KZSV_Text::clip( $desc, $max );
		}
		if ( '' === trim( $desc ) ) {
			$desc = $title;
		}
		return array( $title, $desc, $custom );
	}

	/**
	 * Концерт подпадает под правило «Не транслируются» (настройки плагина): название на афише содержит строку из списка.
	 * Регистр, «ё» и лишние пробелы не важны.
	 */
	public static function excluded( $title_raw ) {
		$title = self::norm( KZSV_Text::title( $title_raw ) );
		if ( '' === $title ) {
			return false;
		}
		foreach ( preg_split( '/\R/u', (string) KZSV_Settings::get( 'exclude' ) ) as $line ) {
			$needle = self::norm( $line );
			if ( '' !== $needle && false !== strpos( $title, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	private static function norm( $s ) {
		$s = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $s, 'UTF-8' ) : strtolower( (string) $s );
		$s = str_replace( 'ё', 'е', $s );
		$s = preg_replace( '/\s+/u', ' ', $s );
		return trim( (string) $s );
	}

	protected function item( $key, $post_id, $start, $end, $title_raw, $url ) {
		list( $title, $desc, $custom ) = $this->compose( $post_id, $title_raw, $url, $start );
		$rule                          = self::excluded( $title_raw );
		return array(
			'key'     => $key,
			'post_id' => (int) $post_id,
			'start'   => (int) $start,
			'end'     => ( $end > $start ) ? (int) $end : 0,
			'title'   => $title,
			'desc'    => $desc,
			'url'     => (string) $url,
			'skip'    => $rule || (bool) get_post_meta( $post_id, self::META_SKIP, true ),
			'rule'    => $rule,
			'custom'  => $custom,
		);
	}

	protected static function meta_values( $post_id, $key ) {
		$v = get_post_meta( $post_id, $key, false );
		$out = array();
		foreach ( (array) $v as $one ) {
			if ( is_string( $one ) && '' !== trim( $one ) ) {
				$out[] = $one;
			}
		}
		return $out;
	}
}

/**
 * Текущий сайт: All-in-One Event Calendar.
 *
 * Время: афиша показывает концерт через date() по часам PHP (UTC), а редакторы ставят пояс «UTC»,
 * поэтому в базе лежит «время на афише». Переводим его в настоящий момент по часам клуба
 * (тот же расчёт, что у темы и Kozlov Smart Search: 20:00 на афише = 20:00 по Москве).
 */
final class KZSV_Source_Ai1ec extends KZSV_Source {

	const STAGE_META = 'location_main_scene';

	/** @var bool|null */
	private $has_end = null;

	public function id() {
		return 'ai1ec';
	}

	public function label() {
		return 'All-in-One Event Calendar (текущий сайт)';
	}

	public function post_types() {
		return array( 'ai1ec_event' );
	}

	public function available() {
		global $wpdb;
		static $ok = null;
		if ( null === $ok ) {
			$ok = true;
			foreach ( array( 'ai1ec_event_instances', 'ai1ec_events' ) as $name ) {
				$table = $wpdb->prefix . $name;
				if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
					$ok = false;
					break;
				}
			}
		}
		return $ok;
	}

	private function has_end() {
		global $wpdb;
		if ( null === $this->has_end ) {
			$col           = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$wpdb->prefix}ai1ec_event_instances LIKE %s", 'end' ) );
			$this->has_end = ! empty( $col );
		}
		return $this->has_end;
	}

	/** «Время на афише» из базы календаря в настоящий момент. */
	public static function real( $raw ) {
		$raw = (int) $raw;
		// phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
		$wall = date( 'Y-m-d H:i:s', $raw );
		$dt   = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $wall, KZSV_Settings::tz() );
		return $dt ? $dt->getTimestamp() : $raw;
	}

	public function is_main_stage( $post_id ) {
		return ! empty( get_post_meta( $post_id, self::STAGE_META, true ) );
	}

	protected function texts( $post_id ) {
		return array_merge(
			self::meta_values( $post_id, 'textblock_1_description' ),
			self::meta_values( $post_id, 'artist_description' ),
			self::meta_values( $post_id, 'textblock_2_description' ),
			array( (string) get_post_field( 'post_content', $post_id ) )
		);
	}

	public function events( $from, $to ) {
		global $wpdb;
		if ( ! $this->available() ) {
			throw new RuntimeException( 'таблицы календаря ai1ec не найдены' );
		}
		// в базе «время на афише»: берём с запасом в сутки и уточняем после перевода
		$pad     = DAY_IN_SECONDS;
		$end_sql = $this->has_end() ? ', i.`end` AS end_raw' : '';
		$rows    = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT i.post_id AS post_id, i.start AS start_raw{$end_sql}, p.post_title AS post_title
				 FROM {$wpdb->prefix}ai1ec_event_instances AS i
				 INNER JOIN {$wpdb->posts} AS p ON p.ID = i.post_id
				 WHERE i.start >= %d AND i.start <= %d
				   AND p.post_status = 'publish' AND p.post_password = ''
				 ORDER BY i.start ASC, i.post_id ASC
				 LIMIT 1000",
				(int) $from - $pad,
				(int) $to + $pad
			)
		);
		if ( '' !== (string) $wpdb->last_error ) {
			throw new RuntimeException( 'ошибка базы календаря: ' . $wpdb->last_error );
		}
		if ( ! is_array( $rows ) ) {
			return array();
		}
		$ids = array();
		foreach ( $rows as $row ) {
			$ids[ (int) $row->post_id ] = true;
		}
		if ( $ids ) {
			update_meta_cache( 'post', array_keys( $ids ) );
		}
		$out = array();
		foreach ( $rows as $row ) {
			$pid = (int) $row->post_id;
			if ( ! $this->is_main_stage( $pid ) ) {
				continue;
			}
			$start = self::real( $row->start_raw );
			if ( $start < $from || $start > $to ) {
				continue;
			}
			$end = ( isset( $row->end_raw ) && (int) $row->end_raw > (int) $row->start_raw ) ? self::real( $row->end_raw ) : 0;
			$url = get_permalink( $pid );
			$out[] = $this->item( 'ai1ec:' . $pid . ':' . (int) $row->start_raw, $pid, $start, $end, $row->post_title, $url ? $url : '' );
		}
		return $out;
	}
}

/**
 * Новый сайт: Kozlov Core (тип записи concert, начало в kc_start по московскому времени, сцена в таксономии kc_stage).
 */
final class KZSV_Source_Core extends KZSV_Source {

	const POST_TYPE = 'concert';
	const TAX       = 'kc_stage';
	const STAGE     = 'main-scene';

	public function id() {
		return 'core';
	}

	public function label() {
		return 'Kozlov Core (новый сайт)';
	}

	public function post_types() {
		return array( self::POST_TYPE );
	}

	public function available() {
		return post_type_exists( self::POST_TYPE ) && taxonomy_exists( self::TAX );
	}

	public function is_main_stage( $post_id ) {
		return has_term( self::STAGE, self::TAX, $post_id );
	}

	protected function texts( $post_id ) {
		$texts   = self::meta_values( $post_id, 'kc_text_1' );
		$artists = get_post_meta( $post_id, 'kc_artists', true );
		if ( is_array( $artists ) ) {
			foreach ( $artists as $a ) {
				if ( is_array( $a ) && ! empty( $a['text'] ) && is_string( $a['text'] ) ) {
					$texts[] = $a['text'];
				}
			}
		}
		return array_merge( $texts, self::meta_values( $post_id, 'kc_text_2' ), array( (string) get_post_field( 'post_content', $post_id ) ) );
	}

	private static function parse( $value, $base = 0 ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return 0;
		}
		try {
			$tz = KZSV_Settings::tz();
			if ( $base && preg_match( '/^\d{1,2}:\d{2}(:\d{2})?$/', $value ) ) {
				$day = wp_date( 'Y-m-d', $base, $tz );
				$ts  = ( new DateTimeImmutable( $day . ' ' . $value, $tz ) )->getTimestamp();
				return ( $ts <= $base ) ? $ts + DAY_IN_SECONDS : $ts;
			}
			return ( new DateTimeImmutable( $value, $tz ) )->getTimestamp();
		} catch ( \Throwable $e ) {
			return 0;
		}
	}

	public function events( $from, $to ) {
		if ( ! $this->available() ) {
			throw new RuntimeException( 'Kozlov Core не найден' );
		}
		$tz = KZSV_Settings::tz();
		$q  = new WP_Query(
			array(
				'post_type'              => self::POST_TYPE,
				'post_status'            => 'publish',
				'has_password'           => false,
				'posts_per_page'         => 300,
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'suppress_filters'       => true,
				'update_post_term_cache' => true,
				'meta_key'               => 'kc_start', // phpcs:ignore WordPress.DB.SlowDBQuery
				'orderby'                => 'meta_value',
				'order'                  => 'ASC',
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'     => 'kc_start',
						'value'   => array( wp_date( 'Y-m-d H:i:s', (int) $from, $tz ), wp_date( 'Y-m-d H:i:s', (int) $to, $tz ) ),
						'compare' => 'BETWEEN',
						'type'    => 'CHAR',
					),
				),
				'tax_query'              => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'taxonomy' => self::TAX,
						'field'    => 'slug',
						'terms'    => array( self::STAGE ),
					),
				),
			)
		);
		$out = array();
		foreach ( $q->posts as $post ) {
			$pid = (int) $post->ID;
			if ( get_post_meta( $pid, 'kc_cancelled', true ) ) {
				continue;
			}
			$start = self::parse( get_post_meta( $pid, 'kc_start', true ) );
			if ( ! $start ) {
				continue;
			}
			$end = self::parse( get_post_meta( $pid, 'kc_end', true ), $start );
			$url = get_permalink( $pid );
			$out[] = $this->item( 'core:' . $pid . ':' . $start, $pid, $start, $end, $post->post_title, $url ? $url : '' );
		}
		return $out;
	}
}
