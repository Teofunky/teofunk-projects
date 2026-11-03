<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Будущие концерты артиста из Kozlov Core: подбор по ключевым словам в названии и текстах концерта.
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

	if ( ! function_exists( 'kozlov_concerts' ) ) { return array(); }

	$include = ka_lines( $artist_id, 'ka_keywords' );
	if ( ! $include ) { return array(); }
	$exclude    = ka_lines( $artist_id, 'ka_exclude' );
	$title_only = (bool) get_post_meta( $artist_id, 'ka_title_only', true );

	// будущие концерты Kozlov Core: тот же порог, что в афише (идущий сейчас концерт ещё показывается)
	$out = array();
	foreach ( kozlov_concerts( array( 'limit' => 600 ) ) as $c ) {
		$title = (string) $c['title'];
		$full  = $title . ' ' . ka_concert_text( $c['id'] );
		$hay   = $title_only ? $title : $full;
		$hit   = false;
		foreach ( $include as $kw ) { if ( false !== mb_stripos( $hay, $kw ) ) { $hit = true; break; } }
		if ( ! $hit ) { continue; }
		foreach ( $exclude as $kw ) { if ( false !== mb_stripos( $full, $kw ) ) { continue 2; } }
		$out[] = array(
			'id'    => (int) $c['id'],
			'title' => $title,
			'url'   => (string) $c['url'],
			'ts'    => (int) strtotime( $c['start'] . ' UTC' ), // время клуба как «UTC-метка», как раньше в календаре
			'cost'  => (string) $c['price']['text'],
		);
		if ( count( $out ) >= 50 ) { break; }
	}
	set_transient( $cache_key, $out, 10 * MINUTE_IN_SECONDS );
	return $out;
}

/** Тексты концерта Kozlov Core одной строкой: описание, имена и тексты артистов, программа. */
function ka_concert_text( $post_id ) {
	$parts = array( (string) get_post_field( 'post_content', $post_id ), (string) get_post_meta( $post_id, 'kc_program', true ) );
	foreach ( array( 'kc_text_1', 'kc_text_2', 'kc_additional', 'kc_quote', 'kc_quote_author' ) as $k ) {
		$parts[] = (string) get_post_meta( $post_id, $k, true );
	}
	foreach ( (array) get_post_meta( $post_id, 'kc_artists', true ) as $a ) {
		if ( is_array( $a ) ) { $parts[] = (string) ( $a['name'] ?? '' ) . ' ' . (string) ( $a['text'] ?? '' ); }
	}
	return wp_strip_all_tags( implode( ' ', $parts ) );
}

/** Инвалидация без прямого SQL: меняем версию кэша (работает и с объектным кэшем) */
function ka_bump_events_cache() { update_option( 'ka_events_ver', time(), false ); }
add_action( 'save_post_concert', 'ka_bump_events_cache' );
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
