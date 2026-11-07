<?php
/**
 * Своя таблица записей трансляций. Существующие таблицы сайта не трогаются.
 *
 * Строка = одна трансляция StreamVi: время, ссылки VK и YouTube, концерт на сайте, ручные правки.
 */

defined( 'ABSPATH' ) || exit;

final class KZV_Store {

	const DB_VERSION = '1';
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
  hidden tinyint(1) NOT NULL DEFAULT 0,
  detailed tinyint(1) NOT NULL DEFAULT 0,
  tries tinyint(3) unsigned NOT NULL DEFAULT 0,
  updated_at int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY broadcast_id (broadcast_id),
  KEY started_at (started_at),
  KEY pending (detailed, finished_at)
) {$charset};"
		);
		update_option( self::DB_OPTION, self::DB_VERSION, false );
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
		$new = 0;
		foreach ( $items as $it ) {
			$bid = (int) $it['broadcast_id'];
			if ( $bid <= 0 ) {
				continue;
			}
			$start  = (int) $it['started_at'];
			$finish = (int) $it['finished_at'];
			$dur    = ( $finish > $start && $start > 0 ) ? $finish - $start : 0;
			$res    = $wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$t} (broadcast_id, started_at, finished_at, duration, updated_at) VALUES (%d, %d, %d, %d, %d)
					 ON DUPLICATE KEY UPDATE finished_at = IF(VALUES(finished_at) > 0, VALUES(finished_at), finished_at),
					   duration = IF(VALUES(duration) > 0, VALUES(duration), duration)",
					$bid,
					$start,
					$finish,
					$dur,
					time()
				)
			); // phpcs:ignore WordPress.DB
			if ( 1 === (int) $res ) {
				$new++;
			}
		}
		return $new;
	}

	/** Трансляции, у которых ещё нет ссылок (только завершённые). */
	public static function pending( $limit ) {
		global $wpdb;
		$t = self::table();
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$t} WHERE detailed = 0 AND finished_at > 0 AND tries < 5 ORDER BY started_at DESC LIMIT %d",
				max( 1, (int) $limit )
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB
	}

	public static function pending_count() {
		global $wpdb;
		$t = self::table();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE detailed = 0 AND finished_at > 0 AND tries < 5" ); // phpcs:ignore WordPress.DB
	}

	public static function save_detail( $broadcast_id, array $d ) {
		global $wpdb;
		$wpdb->update(
			self::table(),
			array(
				'vk_oid'     => (int) $d['vk_oid'],
				'vk_id'      => (int) $d['vk_id'],
				'yt_id'      => (string) $d['yt_id'],
				'post_id'    => (int) $d['post_id'],
				'title'      => mb_substr( (string) $d['title'], 0, 255 ),
				'detailed'   => 1,
				'updated_at' => time(),
			),
			array( 'broadcast_id' => (int) $broadcast_id ),
			array( '%d', '%d', '%s', '%d', '%s', '%d', '%d' ),
			array( '%d' )
		); // phpcs:ignore WordPress.DB
	}

	public static function bump_tries( $broadcast_id ) {
		global $wpdb;
		$t = self::table();
		$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET tries = tries + 1 WHERE broadcast_id = %d", (int) $broadcast_id ) ); // phpcs:ignore WordPress.DB
	}

	/** Повторно привязать к афише (после правки концерта или настроек). */
	public static function set_match( $broadcast_id, $post_id, $title ) {
		global $wpdb;
		$wpdb->update(
			self::table(),
			array(
				'post_id'    => (int) $post_id,
				'title'      => mb_substr( (string) $title, 0, 255 ),
				'updated_at' => time(),
			),
			array( 'broadcast_id' => (int) $broadcast_id ),
			array( '%d', '%s', '%d' ),
			array( '%d' )
		); // phpcs:ignore WordPress.DB
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
	public static function query( $offset, $limit, $q = '' ) {
		global $wpdb;
		$t     = self::table();
		$where = self::public_where();
		$args  = array();
		$q     = trim( (string) $q );
		if ( '' !== $q ) {
			$like    = '%' . $wpdb->esc_like( $q ) . '%';
			$where  .= ' AND (title LIKE %s OR title_custom LIKE %s)';
			$args[]  = $like;
			$args[]  = $like;
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
