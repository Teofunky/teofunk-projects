<?php
/**
 * Статистика: анонимные действия в навигаторе (без имён, телефонов и IP) и отчёт для админки.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KNAV_Stats {
	const EVENTS = array( 'open', 'close', 'step_1', 'step_2', 'step_3', 'step_4', 'skip', 'results', 'more', 'buy_click', 'table_click', 'event_page', 'afisha_link', 'call_click', 'phone_copy', 'edit_answer', 'dismiss', 'dismiss_undo' );

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'knav_events';
	}

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'knav_cleanup', array( __CLASS__, 'cleanup' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_install' ) );
	}

	public static function activate() {
		self::install();
		add_option( KNAV_Settings::OPTION, KNAV_Settings::defaults() );
		if ( ! wp_next_scheduled( 'knav_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'knav_cleanup' );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'knav_cleanup' );
	}

	public static function maybe_install() {
		if ( get_option( 'knav_db' ) !== KNAV_DB_VERSION ) {
			self::install();
		}
		if ( ! wp_next_scheduled( 'knav_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'knav_cleanup' );
		}
	}

	/** Отдельная таблица плагина. Существующие таблицы сайта не меняются. */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t       = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE {$t} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			ts datetime NOT NULL,
			sid varchar(32) NOT NULL DEFAULT '',
			ev varchar(32) NOT NULL DEFAULT '',
			data text NULL,
			PRIMARY KEY  (id),
			KEY ts (ts),
			KEY ev_ts (ev, ts),
			KEY sid_ts (sid, ts)
		) {$charset};" );
		update_option( 'knav_db', KNAV_DB_VERSION, false );
	}

	public static function routes() {
		register_rest_route( 'kozlov-navigator/v1', '/event', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'receive' ),
			'permission_callback' => '__return_true',
		) );
	}

	/** Приём события. Любая ошибка здесь не влияет на работу навигатора и сайта. */
	public static function receive( $req ) {
		$ok = new WP_REST_Response( null, 204 );
		$s  = KNAV_Settings::get();
		if ( empty( $s['stats'] ) || empty( $s['enabled'] ) ) {
			return $ok;
		}
		$p = $req->get_json_params();
		if ( ! is_array( $p ) ) {
			$p = json_decode( (string) $req->get_body(), true );
		}
		if ( ! is_array( $p ) ) {
			return $ok;
		}
		$sid = isset( $p['sid'] ) ? (string) $p['sid'] : '';
		$ev  = isset( $p['ev'] ) ? (string) $p['ev'] : '';
		if ( ! preg_match( '/^[a-z0-9]{8,32}$/', $sid ) || ! in_array( $ev, self::EVENTS, true ) ) {
			return $ok;
		}
		global $wpdb;
		$t    = self::table();
		$hour = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - HOUR_IN_SECONDS );
		// защита от накрутки: не больше 200 событий на одну сессию в час и 30 000 на весь сайт в час
		$per_sid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE sid = %s AND ts >= %s", $sid, $hour ) ); // phpcs:ignore
		if ( $per_sid >= 200 ) {
			return $ok;
		}
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE ts >= %s", $hour ) ); // phpcs:ignore
		if ( $total >= 30000 ) {
			return $ok;
		}

		$data = isset( $p['d'] ) ? self::clean( $p['d'], 0 ) : array();
		$json = wp_json_encode( $data, JSON_UNESCAPED_UNICODE );
		if ( strlen( (string) $json ) > 3000 ) {
			$json = '{}';
		}
		$wpdb->insert( $t, array(
			'ts'   => current_time( 'mysql' ),
			'sid'  => $sid,
			'ev'   => $ev,
			'data' => $json,
		), array( '%s', '%s', '%s', '%s' ) );
		return $ok;
	}

	/** Оставляет только простые значения, короткие строки и небольшие списки. */
	private static function clean( $v, $depth ) {
		if ( $depth > 3 ) {
			return null;
		}
		if ( is_array( $v ) ) {
			$out = array();
			$i   = 0;
			foreach ( $v as $k => $x ) {
				if ( ++$i > 12 ) {
					break;
				}
				$k         = is_int( $k ) ? $k : substr( preg_replace( '/[^a-z0-9_]/i', '', (string) $k ), 0, 20 );
				$out[ $k ] = self::clean( $x, $depth + 1 );
			}
			return $out;
		}
		if ( is_bool( $v ) || is_int( $v ) || is_float( $v ) || null === $v ) {
			return $v;
		}
		return mb_substr( wp_strip_all_tags( (string) $v ), 0, 120 );
	}

	public static function cleanup() {
		global $wpdb;
		$s    = KNAV_Settings::get();
		$days = max( 30, (int) $s['keep_days'] );
		$t    = self::table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE ts < %s", gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $days * DAY_IN_SECONDS ) ) ); // phpcs:ignore
	}

	/** Отчёт за период: воронка, ответы, советы и клики, спрос без предложения, по дням. */
	public static function report( $days ) {
		global $wpdb;
		$t     = self::table();
		$since = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $days * DAY_IN_SECONDS );
		$r     = array( 'funnel' => array(), 'answers' => array(), 'picks' => array(), 'buys' => array(), 'tables' => array(), 'unmet' => array(), 'daily' => array(), 'results' => 0, 'sources' => array(), 'pages' => array(), 'dismissed' => array() );

		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ev, COUNT(DISTINCT sid) AS n FROM {$t} WHERE ts >= %s GROUP BY ev", $since ) ); // phpcs:ignore
		foreach ( (array) $rows as $row ) {
			$r['funnel'][ $row->ev ] = (int) $row->n;
		}

		$res = $wpdb->get_col( $wpdb->prepare( "SELECT data FROM {$t} WHERE ev = 'results' AND ts >= %s ORDER BY id DESC LIMIT 20000", $since ) ); // phpcs:ignore
		$r['results'] = count( $res );
		foreach ( (array) $res as $json ) {
			$d = json_decode( (string) $json, true );
			if ( ! is_array( $d ) ) {
				continue;
			}
			$a = isset( $d['a'] ) && is_array( $d['a'] ) ? $d['a'] : array();
			foreach ( array( 'm' => 'moods', 't' => 'times', 'i' => 'instruments', 'f' => 'formats' ) as $k => $name ) {
				foreach ( ( isset( $a[ $k ] ) && is_array( $a[ $k ] ) ) ? $a[ $k ] : array() as $v ) {
					self::inc( $r['answers'][ $name ], (string) $v );
				}
			}
			if ( ! empty( $a['w'] ) ) {
				self::inc( $r['answers']['when'], (string) $a['w'] );
			}
			if ( ! empty( $a['x'] ) ) {
				self::inc( $r['answers']['experience'], (string) $a['x'] );
			}
			if ( ! empty( $a['c'] ) ) {
				self::inc( $r['answers']['company'], (string) $a['c'] );
			}
			$b = isset( $a['b'] ) ? (int) $a['b'] : 0;
			self::inc( $r['answers']['budget'], $b ? ( $b <= 1000 ? 'до 1 000' : ( $b <= 1500 ? 'до 1 500' : ( $b <= 2000 ? 'до 2 000' : ( $b <= 3000 ? 'до 3 000' : 'больше 3 000' ) ) ) ) : 'любой' );
			foreach ( ( isset( $d['p'] ) && is_array( $d['p'] ) ) ? $d['p'] : array() as $pk ) {
				if ( is_array( $pk ) && ! empty( $pk['id'] ) ) {
					self::inc( $r['picks'], (string) $pk['id'], isset( $pk['t'] ) ? (string) $pk['t'] : '' );
				}
			}
			if ( isset( $d['exact'] ) && 0 === (int) $d['exact'] && 'skip' !== ( isset( $d['r'] ) ? $d['r'] : '' ) ) {
				$sig = self::signature( $a );
				if ( '' !== $sig ) {
					self::inc( $r['unmet'], $sig );
				}
			}
		}

		foreach ( array( 'buy_click' => 'buys', 'table_click' => 'tables' ) as $ev => $key ) {
			$list = $wpdb->get_col( $wpdb->prepare( "SELECT data FROM {$t} WHERE ev = %s AND ts >= %s ORDER BY id DESC LIMIT 20000", $ev, $since ) ); // phpcs:ignore
			foreach ( (array) $list as $json ) {
				$d = json_decode( (string) $json, true );
				if ( is_array( $d ) && ! empty( $d['id'] ) ) {
					self::inc( $r[ $key ], (string) $d['id'], isset( $d['t'] ) ? (string) $d['t'] : '' );
				}
			}
		}

		// Откуда открывают: полоса и кнопка на главной, кнопка на остальных страницах, ссылки #navigator.
		$opens = $wpdb->get_col( $wpdb->prepare( "SELECT data FROM {$t} WHERE ev = 'open' AND ts >= %s ORDER BY id DESC LIMIT 20000", $since ) ); // phpcs:ignore
		foreach ( (array) $opens as $json ) {
			$d    = json_decode( (string) $json, true );
			$src  = is_array( $d ) && ! empty( $d['src'] ) ? (string) $d['src'] : 'launcher';
			$path = is_array( $d ) && isset( $d['path'] ) ? (string) $d['path'] : '/';
			$home = '' === trim( $path, '/' );
			if ( 'strip' === $src || 'chip' === $src ) {
				$key = $src;
			} elseif ( 'link' === $src ) {
				$key = 'link';
			} elseif ( 'auto' === $src ) {
				$key = 'auto';
			} else {
				$key = $home ? 'launcher_home' : 'launcher_other';
			}
			self::inc( $r['sources'], $key );
			if ( ! $home ) {
				self::inc( $r['pages'], $path );
			}
		}

		// Скрыли крестиком: плашку под поиском, плавающую кнопку; сколько раз вернули кнопкой «Вернуть».
		foreach ( array( 'dismiss' => '', 'dismiss_undo' => '_undo' ) as $ev => $suffix ) {
			$list = $wpdb->get_col( $wpdb->prepare( "SELECT data FROM {$t} WHERE ev = %s AND ts >= %s ORDER BY id DESC LIMIT 20000", $ev, $since ) ); // phpcs:ignore
			foreach ( (array) $list as $json ) {
				$d    = json_decode( (string) $json, true );
				$what = is_array( $d ) && isset( $d['what'] ) && 'strip' === $d['what'] ? 'strip' : 'launcher';
				self::inc( $r['dismissed'], $what . $suffix );
			}
		}

		$daily = $wpdb->get_results( $wpdb->prepare( "SELECT DATE(ts) AS d, ev, COUNT(DISTINCT sid) AS n FROM {$t} WHERE ts >= %s AND ev IN ('open','results','buy_click','table_click') GROUP BY DATE(ts), ev ORDER BY d", $since ) ); // phpcs:ignore
		foreach ( (array) $daily as $row ) {
			$r['daily'][ $row->d ][ $row->ev ] = (int) $row->n;
		}

		foreach ( array( 'picks', 'buys', 'tables', 'unmet', 'sources', 'pages' ) as $k ) {
			uasort( $r[ $k ], array( __CLASS__, 'by_count' ) );
		}
		foreach ( $r['answers'] as $k => $v ) {
			uasort( $r['answers'][ $k ], array( __CLASS__, 'by_count' ) );
		}
		return $r;
	}

	private static function inc( &$bucket, $key, $title = '' ) {
		if ( ! is_array( $bucket ) ) {
			$bucket = array();
		}
		if ( ! isset( $bucket[ $key ] ) ) {
			$bucket[ $key ] = array( 'n' => 0, 't' => $title );
		}
		$bucket[ $key ]['n']++;
		if ( $title && ! $bucket[ $key ]['t'] ) {
			$bucket[ $key ]['t'] = $title;
		}
	}

	public static function by_count( $a, $b ) {
		return $b['n'] - $a['n'];
	}

	/** Короткое описание запроса, под который в афише ничего не нашлось. */
	private static function signature( $a ) {
		$parts = array();
		foreach ( ( isset( $a['m'] ) && is_array( $a['m'] ) ) ? $a['m'] : array() as $m ) {
			$parts[] = KNAV_Admin::label( 'moods', (string) $m );
		}
		if ( ! empty( $a['w'] ) ) {
			$parts[] = 'date' === $a['w'] && ! empty( $a['d'] ) ? (string) $a['d'] : KNAV_Admin::label( 'when', (string) $a['w'] );
		}
		foreach ( ( isset( $a['t'] ) && is_array( $a['t'] ) ) ? $a['t'] : array() as $x ) {
			$parts[] = KNAV_Admin::label( 'times', (string) $x );
		}
		foreach ( ( isset( $a['f'] ) && is_array( $a['f'] ) ) ? $a['f'] : array() as $x ) {
			$parts[] = KNAV_Admin::label( 'formats', (string) $x );
		}
		foreach ( ( isset( $a['i'] ) && is_array( $a['i'] ) ) ? $a['i'] : array() as $x ) {
			$parts[] = KNAV_Admin::label( 'instruments', (string) $x );
		}
		if ( ! empty( $a['b'] ) ) {
			$parts[] = 'до ' . number_format( (int) $a['b'], 0, ',', ' ' ) . ' ₽';
		}
		return implode( ' · ', $parts );
	}
}
