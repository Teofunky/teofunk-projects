<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

function ka_is_artist() { return is_singular( 'kozlov_artist' ); }

/** Title по шаблону, если в Yoast не задан вручную */
add_filter( 'wpseo_title', function ( $title ) {
	if ( ! ka_is_artist() ) { return $title; }
	$id = get_queried_object_id();
	if ( get_post_meta( $id, '_yoast_wpseo_title', true ) ) { return $title; }
	return get_the_title( $id ) . ': афиша, билеты на концерты в Москве | Клуб Алексея Козлова';
}, 20 );

add_filter( 'wpseo_metadesc', function ( $desc ) {
	if ( ! ka_is_artist() ) { return $desc; }
	$id = get_queried_object_id();
	if ( get_post_meta( $id, '_yoast_wpseo_metadesc', true ) ) { return $desc; }
	$ev = ka_get_events( $id, 1 );
	$s  = get_the_title( $id ) . ' в Клубе Алексея Козлова.';
	if ( $ev ) { $s .= ' Ближайший концерт ' . ka_date( $ev[0]['ts'], 'j F' ) . '.'; }
	$s .= ' Афиша, билеты, видео и история коллектива.';
	return $s;
}, 20 );

/** JSON-LD: MusicGroup + список ближайших Event (минимум; полные Event уже на страницах концертов) */
add_action( 'wp_head', function () {
	if ( ! ka_is_artist() ) { return; }
	$id    = get_queried_object_id();
	$group = array(
		'@type'       => 'MusicGroup',
		'@id'         => get_permalink( $id ) . '#artist',
		'name'        => get_the_title( $id ),
		'url'         => get_permalink( $id ),
		'description' => wp_strip_all_tags( get_the_excerpt( $id ) ),
	);
	if ( has_post_thumbnail( $id ) ) { $group['image'] = get_the_post_thumbnail_url( $id, 'large' ); }
	if ( $g = get_post_meta( $id, 'ka_genre', true ) ) { $group['genre'] = $g; }
	if ( $f = get_post_meta( $id, 'ka_founded', true ) ) { $group['foundingDate'] = $f; }
	$events = array();
	foreach ( ka_get_events( $id, 10 ) as $e ) {
		$events[] = array(
			'@type'     => 'MusicEvent',
			'name'      => $e['title'],
			'url'       => $e['url'],
			'startDate' => ka_date( $e['ts'], 'c' ),
			'performer' => array( '@id' => $group['@id'] ),
		);
	}
	if ( $events ) { $group['event'] = $events; }
	echo '<script type="application/ld+json">' . wp_json_encode( array( '@context' => 'https://schema.org' ) + $group, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP ) . "</script>\n";
}, 30 );
