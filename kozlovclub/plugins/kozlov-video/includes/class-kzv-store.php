<?php
/**
 * Своя таблица записей трансляций. Существующие таблицы сайта не трогаются.
 *
 * Строка = одна трансляция StreamVi: время, ссылки VK и YouTube, концерт на сайте, ручные правки.
 */

defined( 'ABSPATH' ) || exit;

final class KZV_Store {

	const DB_VERSION = '3';
	const DB_OPTION  = 'kzv_db_version';

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'kzv_videos';
	}

	public static function maybe_upgrade() {
		if ( get_option( self::DB_OPTION ) === self::DB_VERSION ) {
			return;
		}
		self::install();
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t       = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$t} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  broadcast_id bigint(20) unsigned NOT NULL,
  started_at int(10) unsigned NOT NULL DEFAULT 0,
  finished_at int(10) unsigned NOT NULL DEFAULT 0,
  duration int(10) unsigned NOT NULL DEFAULT 0,
  vk_oid bigint(20) NOT NULL DEFAULT 0,
  vk_id bigint(20) unsigned NOT NULL DEFAULT 0,
  yt_id varchar(16) NOT NULL DEFAULT '',
  post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  title varchar(255) NOT NULL DEFAULT '',
  title_custom varchar(255) NOT NULL DEFAULT '',
  sv_title varchar(255) NOT NULL DEFAULT '',
  genres varchar(255) NOT NULL DEFAULT '',
  hidden tinyint(1) NOT NULL DEFAULT 0,
  detailed tinyint(1) NOT NULL DEFAULT 0,
  tries tinyint(3) unsigned NOT NULL DEFAULT 0,
  next_try int(10) unsigned NOT NULL DEFAULT 0,
  updated_at int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY broadcast_id (broadcast_id),
  KEY started_at (started_at),
  KEY pending (detailed, finished_at)
) {$charset};"
		);
		// версию схемы записываем, только если таблица действительно есть и в ней новые поля
		$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$t}", 0 ); // phpcs:ignore WordPress.DB
		if ( is_array( $cols ) && in_array( 'next_try', $cols, true ) && in_array( 'sv_title', $cols, true ) ) {
			update_option( self::DB_OPTION, self::DB_VERSION, false );
		} else {
			KZV_Settings::note_error( 'таблица ' . $t . ' не создана или не обновлена: ' . (string) $wpdb->last_error );
		}
	}

	/** Таблица готова к работе (схема текущей версии). */
	public static function ready() {
		return get_option( self::DB_OPTION ) === self::DB_VERSION;
	}

	public static function drop() {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table() ); // phpcs:ignore WordPress.DB
		delete_option( self::DB_OPTION );
	}

	/**
	 * Добавить трансляции из списка StreamVi (новые вставляются, у известных обновляется время окончания).
	 *
	 * @param array[] $items [ broadcast_id, started_at, finished_at ]
	 * @return int Сколько новых.
	 */
	public static function upsert_list( array $items ) {
		global $wpdb;
		$t   = self::table();
		$ids = array();
		foreach ( $items as $it ) {
			if ( (int) $it['broadcast_id'] > 0 ) {
				$ids[] = (int) $it['broadcast_id'];
			}
		}
		if ( ! $ids ) {
			return 0;
		}
		// какие уже есть: считаем новые по таблице, а не по ответу базы (он разный у MySQL и других движков)
		$known = array_map( 'intval', (array) $wpdb->get_col( "SELECT broadcast_id FROM {$t} WHERE broadcast_id IN (" . implode( ',', array_unique( $ids ) ) . ')' ) ); // phpcs:ignore WordPress.DB
		$known = array_flip( $known );
		$new   = 0;
		foreach ( $items as $it ) {
			$bid = (int) $it['broadcast_id'];
			if ( $bid <= 0 ) {
				continue;
			}
			$start  = (int) $it['started_at'];
			$finish = (int) $it['finished_at'];
			$dur    = ( $finish > $start && $start > 0 ) ? $finish - $start : 0;
			if ( isset( $known[ $bid ] ) ) {
				if ( $finish > 0 ) {
					$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET finished_at = %d, duration = %d WHERE broadcast_id = %d AND finished_at <> %d", $finish, $dur, $bid, $finish ) ); // phpcs:ignore WordPress.DB
				}
				continue;
			}
			$ins = $wpdb->insert(
				$t,
				array(
					'broadcast_id' => $bid,
					'started_at'   => $start,
					'finished_at'  => $finish,
					'duration'     => $dur,
					'updated_at'   => time(),
				),
				array( '%d', '%d', '%d', '%d', '%d' )
			); // phpcs:ignore WordPress.DB
			if ( $ins ) {
				$known[ $bid ] = true;
				$new++;
			}
		}
		return $new;
	}

	/**
	 * Завершённые трансляции, которые пора (пере)проверить: ещё нет ссылки на VK или был сбой.
	 * Навсегда из очереди ничего не выпадает: после сбоев проверка просто реже (см. KZV_Sync).
	 */
	public static function pending( $limit, $now = null ) {
		global $wpdb;
		$t   = self::table();
		$now = null === $now ? time() : (int) $now;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$t} WHERE detailed = 0 AND finished_at > 0 AND next_try <= %d ORDER BY started_at DESC LIMIT %d",
				$now,
				max( 1, (int) $limit )
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB
	}

	/** Сколько записей ещё ждут ссылок (в том числе отложенные). */
	public static function pending_count() {
		global $wpdb;
		$t = self::table();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE detailed = 0 AND finished_at > 0" ); // phpcs:ignore WordPress.DB
	}

	/** Отложить следующую проверку трансляции. */
	public static function defer( $broadcast_id, $seconds, $count_try ) {
		global $wpdb;
		$t = self::table();
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$t} SET next_try = %d, tries = LEAST(tries + %d, 250), updated_at = %d WHERE broadcast_id = %d",
				time() + max( 60, (int) $seconds ),
				$count_try ? 1 : 0,
				time(),
				(int) $broadcast_id
			)
		); // phpcs:ignore WordPress.DB
	}

	/** Проверить трансляцию заново при следующей синхронизации (кнопка в админке). */
	public static function recheck( $broadcast_id ) {
		global $wpdb;
		$wpdb->update( self::table(), array( 'detailed' => 0, 'next_try' => 0, 'tries' => 0 ), array( 'broadcast_id' => (int) $broadcast_id ), array( '%d', '%d', '%d' ), array( '%d' ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Ссылки, концерт и жанры трансляции.
	 *
	 * @param bool $final true: разбор закончен (detailed = 1); false: сохранить найденное и проверить позже.
	 */
	public static function save_detail( $broadcast_id, array $d, $final = true ) {
		global $wpdb;
		$wpdb->update(
			self::table(),
			array(
				'vk_oid'     => (int) $d['vk_oid'],
				'vk_id'      => (int) $d['vk_id'],
				'yt_id'      => (string) $d['yt_id'],
				'post_id'    => (int) $d['post_id'],
				'title'      => mb_substr( (string) $d['title'], 0, 255 ),
				'sv_title'   => mb_substr( (string) ( $d['sv_title'] ?? '' ), 0, 255 ),
				'genres'     => mb_substr( (string) ( $d['genres'] ?? '' ), 0, 255 ),
				'detailed'   => $final ? 1 : 0,
				'updated_at' => time(),
			),
			array( 'broadcast_id' => (int) $broadcast_id ),
			array( '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%d', '%d' ),
			array( '%d' )
		); // phpcs:ignore WordPress.DB
	}

	/** Привязать к концерту афиши (автоматически или вручную из админки). */
	public static function set_match( $broadcast_id, $post_id, $title, $genres = '' ) {
		global $wpdb;
		$wpdb->update(
			self::table(),
			array(
				'post_id'    => (int) $post_id,
				'title'      => mb_substr( (string) $title, 0, 255 ),
				'genres'     => mb_substr( (string) $genres, 0, 255 ),
				'updated_at' => time(),
			),
			array( 'broadcast_id' => (int) $broadcast_id ),
			array( '%d', '%s', '%s', '%d' ),
			array( '%d' )
		); // phpcs:ignore WordPress.DB
	}

	/** Привязанные к концерту записи без жанров (после обновления плагина). */
	public static function missing_genres( $limit ) {
		global $wpdb;
		$t = self::table();
		return $wpdb->get_results( $wpdb->prepare( "SELECT broadcast_id, post_id FROM {$t} WHERE post_id > 0 AND genres = '' ORDER BY started_at DESC LIMIT %d", max( 1, (int) $limit ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	public static function set_genres( $broadcast_id, $genres ) {
		global $wpdb;
		$wpdb->update( self::table(), array( 'genres' => mb_substr( (string) $genres, 0, 255 ) ), array( 'broadcast_id' => (int) $broadcast_id ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB
	}

	public static function set_hidden( $broadcast_id, $hidden ) {
		global $wpdb;
		$wpdb->update( self::table(), array( 'hidden' => $hidden ? 1 : 0 ), array( 'broadcast_id' => (int) $broadcast_id ), array( '%d' ), array( '%d' ) ); // phpcs:ignore WordPress.DB
	}

	public static function set_custom_title( $broadcast_id, $title ) {
		global $wpdb;
		$wpdb->update( self::table(), array( 'title_custom' => mb_substr( trim( (string) $title ), 0, 255 ) ), array( 'broadcast_id' => (int) $broadcast_id ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB
	}

	/** Условие «показывается на сайте». */
	private static function public_where() {
		$min = (int) KZV_Settings::get( 'min_minutes' ) * 60;
		return 'hidden = 0 AND detailed = 1 AND vk_id > 0 AND duration >= ' . $min;
	}

	/**
	 * Записи для сайта, новые сверху.
	 *
	 * @return array [ items => array[], total => int ]
	 */
	public static function query( $offset, $limit, $q = '', $genre = array() ) {
		global $wpdb;
		$t     = self::table();
		$where = self::public_where();
		$args  = array();
		$q     = trim( (string) $q );
		if ( '' !== $q ) {
			// названия в афише заглавными: ищем и так, и так (на случай регистрозависимого сравнения в базе)
			$variants = array_unique( array( $q, mb_strtoupper( $q, 'UTF-8' ), mb_strtolower( $q, 'UTF-8' ) ) );
			$parts    = array();
			foreach ( $variants as $vq ) {
				$like    = '%' . $wpdb->esc_like( $vq ) . '%';
				$parts[] = 'title LIKE %s OR title_custom LIKE %s OR sv_title LIKE %s';
				array_push( $args, $like, $like, $like );
			}
			$where .= ' AND (' . implode( ' OR ', $parts ) . ')';
		}
		// $genre: список кодов жанров афиши (jazz, blues…); подходит запись с любым из них
		$codes = array();
		foreach ( (array) $genre as $g ) {
			$g = strtolower( trim( (string) $g ) );
			if ( '' !== $g && preg_match( '/^[a-z0-9 -]{2,40}$/', $g ) ) {
				$codes[] = $g;
			}
		}
		if ( $codes ) {
			$parts = array();
			foreach ( $codes as $c ) {
				$parts[] = "CONCAT(',', genres, ',') LIKE %s";
				$args[]  = '%,' . $wpdb->esc_like( $c ) . ',%';
			}
			$where .= ' AND (' . implode( ' OR ', $parts ) . ')';
		}
		$count_sql = "SELECT COUNT(*) FROM {$t} WHERE {$where}";
		$total     = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $count_sql, $args ) ) : $wpdb->get_var( $count_sql ) ); // phpcs:ignore WordPress.DB
		$sql       = "SELECT * FROM {$t} WHERE {$where} ORDER BY started_at DESC LIMIT %d OFFSET %d";
		$rows      = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $args, array( max( 1, (int) $limit ), max( 0, (int) $offset ) ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array(
			'items' => is_array( $rows ) ? $rows : array(),
			'total' => $total,
		);
	}

	/** Запись, если она показывается на сайте (те же условия, что у списка). */
	public static function get_public( $broadcast_id ) {
		global $wpdb;
		$t   = self::table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE broadcast_id = %d AND " . self::public_where(), (int) $broadcast_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return is_array( $row ) ? $row : null;
	}

	public static function get( $broadcast_id ) {
		global $wpdb;
		$t   = self::table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE broadcast_id = %d", (int) $broadcast_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return is_array( $row ) ? $row : null;
	}

	/** Последняя запись для показа. */
	public static function latest() {
		$r = self::query( 0, 1 );
		return $r['items'] ? $r['items'][0] : null;
	}

	/** Сводка для админки. */
	public static function stats() {
		global $wpdb;
		$t   = self::table();
		$min = (int) KZV_Settings::get( 'min_minutes' ) * 60;
		$row = $wpdb->get_row(
			"SELECT COUNT(*) AS total,
			        SUM(detailed = 1) AS detailed,
			        SUM(detailed = 1 AND vk_id > 0) AS with_vk,
			        SUM(detailed = 1 AND post_id > 0) AS matched,
			        SUM(hidden = 1) AS hidden,
			        SUM(detailed = 1 AND duration < {$min}) AS short_ones,
			        SUM(detailed = 0 AND tries >= 5) AS failed,
		        SUM(detailed = 1 AND vk_id = 0) AS no_vk,
			        MIN(started_at) AS first_ts
			 FROM {$t}",
			ARRAY_A
		); // phpcs:ignore WordPress.DB
		$out = array();
		foreach ( (array) $row as $k => $v ) {
			$out[ $k ] = (int) $v;
		}
		$out['public'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE " . self::public_where() ); // phpcs:ignore WordPress.DB
		return $out;
	}

	/** Последние записи для админки (включая скрытые и короткие). */
	public static function recent( $limit ) {
		global $wpdb;
		$t = self::table();
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} ORDER BY started_at DESC LIMIT %d", max( 1, (int) $limit ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/** Строки, которые стоит заново привязать к афише (без концерта). */
	public static function unmatched( $limit ) {
		global $wpdb;
		$t = self::table();
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE detailed = 1 AND post_id = 0 ORDER BY started_at DESC LIMIT %d", max( 1, (int) $limit ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}
}
