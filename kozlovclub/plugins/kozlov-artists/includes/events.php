<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Будущие концерты артиста из All-in-One Event Calendar.
 * Берутся ЭКЗЕМПЛЯРЫ событий из {prefix}ai1ec_event_instances (так календарь хранит повторы),
 * если таблицы нет, запасной вариант: {prefix}ai1ec_events.
 * Структуру таблиц нужно проверить на тестовой копии сайта.
 *
 * @return array[] id, title, url, ts (unix), cost
 */
function ka_get_events( $artist_id, $limit = 20 ) {
	$all = ka_get_all_events( $artist_id );
	return array_slice( $all, 0, max( 1, (int) $limit ) );
}

/** Кэшируется ПОЛНЫЙ список (до 50), а не срез: размер среза не влияет на кэш */
function ka_get_all_events( $artist_id ) {
	$ver       = (int) get_option( 'ka_events_ver', 1 );
	$cache_key = 'ka_ev_' . $ver . '_' . (int) $artist_id;
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) ) { return $cached; }

	global $wpdb;
	$ev_table = $wpdb->prefix . 'ai1ec_events';
	$in_table = $wpdb->prefix . 'ai1ec_event_instances';
	$has_ev   = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $ev_table ) ) === $ev_table;
	$has_in   = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $in_table ) ) === $in_table;
	if ( ! $has_ev ) { return array(); }

	$include = ka_lines( $artist_id, 'ka_keywords' );
	if ( ! $include ) { return array(); }
	$exclude    = ka_lines( $artist_id, 'ka_exclude' );
	$title_only = (bool) get_post_meta( $artist_id, 'ka_title_only', true );

	$args  = array();
	$parts = array();
	foreach ( $include as $kw ) {
		$like = '%' . $wpdb->esc_like( $kw ) . '%';
		if ( $title_only ) {
			$parts[] = 'p.post_title LIKE %s';
			$args[]  = $like;
		} else {
			$parts[] = '(p.post_title LIKE %s OR p.post_content LIKE %s)';
			$args[]  = $like;
			$args[]  = $like;
		}
	}
	$sql_inc = '(' . implode( ' OR ', $parts ) . ')';

	$sql_exc = '';
	foreach ( $exclude as $kw ) {
		$like     = '%' . $wpdb->esc_like( $kw ) . '%';
		$sql_exc .= ' AND p.post_title NOT LIKE %s AND p.post_content NOT LIKE %s';
		$args[]   = $like;
		$args[]   = $like;
	}

	$args[] = ka_now_local() - 3 * HOUR_IN_SECONDS; // идущий сейчас концерт ещё показываем
	$args[] = 50;

	if ( $has_in ) {
		$from  = "{$in_table} i INNER JOIN {$wpdb->posts} p ON p.ID = i.post_id INNER JOIN {$ev_table} e ON e.post_id = p.ID";
		$start = 'i.start';
	} else {
		$from  = "{$ev_table} e INNER JOIN {$wpdb->posts} p ON p.ID = e.post_id";
		$start = 'e.start';
	}

	$sql = "SELECT p.ID, p.post_title, {$start} AS start_ts, e.cost
	        FROM {$from}
	        WHERE p.post_type = 'ai1ec_event' AND p.post_status = 'publish'
	        AND {$sql_inc}{$sql_exc}
	        AND {$start} >= %d
	        ORDER BY {$start} ASC LIMIT %d";

	$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	$out  = array();
	foreach ( (array) $rows as $r ) {
		$out[] = array(
			'id'    => (int) $r->ID,
			'title' => $r->post_title,
			'url'   => get_permalink( $r->ID ),
			'ts'    => (int) $r->start_ts,
			'cost'  => (string) $r->cost,
		);
	}
	set_transient( $cache_key, $out, 10 * MINUTE_IN_SECONDS );
	return $out;
}

/** Инвалидация без прямого SQL: меняем версию кэша (работает и с объектным кэшем) */
function ka_bump_events_cache() { update_option( 'ka_events_ver', time(), false ); }
add_action( 'save_post_ai1ec_event', 'ka_bump_events_cache' );
add_action( 'deleted_post', 'ka_bump_events_cache' );
add_action( 'trashed_post', 'ka_bump_events_cache' );

/** Формат даты по Москве */
function ka_date( $ts, $fmt ) {
	// Календарь хранит местное (московское) время как «UTC-метку»: показываем без сдвига пояса
	return wp_date( $fmt, $ts, new DateTimeZone( 'UTC' ) );
}

/** Московское «сейчас» в той же шкале, что и метки календаря */
function ka_now_local() {
	$tz = new DateTimeZone( 'Europe/Moscow' );
	return time() + $tz->getOffset( new DateTime( 'now', $tz ) );
}

/** Ссылка на видео -> адрес для iframe */
function ka_video_embed( $url ) {
	$url = trim( (string) $url );
	if ( ! $url ) { return ''; }
	if ( preg_match( '~rutube\.ru/(?:video|play/embed)/([a-f0-9]{32})~i', $url, $m ) ) {
		return 'https://rutube.ru/play/embed/' . $m[1];
	}
	if ( preg_match( '~vk(?:video)?\.(?:ru|com)/video(-?\d+)_(\d+)~i', $url, $m ) ) {
		return 'https://vkvideo.ru/video_ext.php?oid=' . $m[1] . '&id=' . $m[2] . '&hd=2';
	}
	if ( preg_match( '~(?:youtube\.com/watch\?v=|youtu\.be/)([\w-]{11})~', $url, $m ) ) {
		return 'https://www.youtube-nocookie.com/embed/' . $m[1];
	}
	return '';
}
