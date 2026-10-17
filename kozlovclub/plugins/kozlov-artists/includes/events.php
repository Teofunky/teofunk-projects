<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Будущие концерты артиста из All-in-One Event Calendar.
 * ВАЖНО: структура таблицы {prefix}ai1ec_events (post_id, start, cost, ticket_url) взята
 * из знания о плагине и ЕЩЁ НЕ проверена на сайте клуба. Проверить на тестовой копии.
 *
 * @return array[] id, title, url, ts (unix), cost
 */
function ka_get_events( $artist_id, $limit = 20 ) {
	$cache_key = 'ka_events_' . $artist_id;
	$cached    = get_transient( $cache_key );
	if ( false !== $cached ) { return $cached; }

	global $wpdb;
	$table = $wpdb->prefix . 'ai1ec_events';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
		return array();
	}

	$include = ka_lines( $artist_id, 'ka_keywords' );
	if ( ! $include ) { return array(); }
	$exclude = ka_lines( $artist_id, 'ka_exclude' );

	$where = array();
	$args  = array();
	foreach ( $include as $kw ) {
		$like    = '%' . $wpdb->esc_like( $kw ) . '%';
		$where[] = '(p.post_title LIKE %s OR p.post_content LIKE %s)';
		$args[]  = $like;
		$args[]  = $like;
	}
	$sql_inc = '(' . implode( ' OR ', $where ) . ')';

	$sql_exc = '';
	foreach ( $exclude as $kw ) {
		$like     = '%' . $wpdb->esc_like( $kw ) . '%';
		$sql_exc .= ' AND p.post_title NOT LIKE %s AND p.post_content NOT LIKE %s';
		$args[]   = $like;
		$args[]   = $like;
	}

	$args[] = time() - 3 * HOUR_IN_SECONDS; // концерт, который идёт прямо сейчас, ещё показываем
	$args[] = (int) $limit;

	$sql = "SELECT p.ID, p.post_title, e.start, e.cost
	        FROM {$wpdb->posts} p
	        INNER JOIN {$table} e ON e.post_id = p.ID
	        WHERE p.post_type = 'ai1ec_event' AND p.post_status = 'publish'
	        AND {$sql_inc}{$sql_exc}
	        AND e.start >= %d
	        ORDER BY e.start ASC LIMIT %d";

	$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	$out  = array();
	foreach ( (array) $rows as $r ) {
		$out[] = array(
			'id'    => (int) $r->ID,
			'title' => $r->post_title,
			'url'   => get_permalink( $r->ID ),
			'ts'    => (int) $r->start,
			'cost'  => (string) $r->cost,
		);
	}
	set_transient( $cache_key, $out, 10 * MINUTE_IN_SECONDS );
	return $out;
}

/** Сбрасываем кэш всех артистов при изменении любого концерта */
add_action( 'save_post_ai1ec_event', function () {
	global $wpdb;
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_ka\_events\_%' OR option_name LIKE '\_transient\_timeout\_ka\_events\_%'" ); // phpcs:ignore
} );

/** Формат даты по Москве */
function ka_date( $ts, $fmt ) {
	return wp_date( $fmt, $ts, new DateTimeZone( 'Europe/Moscow' ) );
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
