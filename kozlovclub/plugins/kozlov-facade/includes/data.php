<?php
/**
 * Settings, slides and how each slide turns into what the card shows.
 * Storage: options only (kzf_settings, kzf_slides, kzf_gazeta_cache) and one transient. No tables.
 * Page generation never makes HTTP requests: Джазета is refreshed by WP-Cron and on save.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const KZF_TYPES = array( 'event', 'image', 'gazeta' );
const KZF_MODES = array( 'off', 'editors', 'all' );

function kzf_default_settings() {
	return array(
		'mode'        => 'editors',
		'street_sync' => 1,
		'tc_meta_key' => '',
	);
}

function kzf_settings() {
	$s = get_option( 'kzf_settings', array() );
	$s = is_array( $s ) ? $s : array();
	$s = array_merge( kzf_default_settings(), $s );
	if ( ! in_array( $s['mode'], KZF_MODES, true ) ) {
		$s['mode'] = 'off';
	}
	return $s;
}

function kzf_slides() {
	$s = get_option( 'kzf_slides', array() );
	return is_array( $s ) ? array_values( $s ) : array();
}

/* ------------------------------------------------------------------ sanitizing */

function kzf_sanitize_slide( $raw, $from_request = false ) {
	$raw  = is_array( $raw ) ? ( $from_request ? wp_unslash( $raw ) : $raw ) : array();
	$type = isset( $raw['type'] ) && in_array( $raw['type'], KZF_TYPES, true ) ? $raw['type'] : 'event';
	$text = function ( $k ) use ( $raw ) {
		return isset( $raw[ $k ] ) ? sanitize_text_field( $raw[ $k ] ) : '';
	};
	$url  = function ( $k ) use ( $raw ) {
		return isset( $raw[ $k ] ) ? esc_url_raw( trim( $raw[ $k ] ) ) : '';
	};
	$date = isset( $raw['hide_after'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw['hide_after'] ) ? $raw['hide_after'] : '';
	return array(
		'type'        => $type,
		'enabled'     => empty( $raw['enabled'] ) ? 0 : 1,
		'label'       => $text( 'label' ),
		'event_url'   => $url( 'event_url' ),
		'title'       => $text( 'title' ),
		'subtitle'    => $text( 'subtitle' ),
		'date_text'   => $text( 'date_text' ),
		'meta_text'   => $text( 'meta_text' ),
		'tc_id'       => isset( $raw['tc_id'] ) ? preg_replace( '/[^a-f0-9]/', '', strtolower( $raw['tc_id'] ) ) : '',
		'image_id'    => isset( $raw['image_id'] ) ? absint( $raw['image_id'] ) : 0,
		'link'        => $url( 'link' ),
		'button_text' => $text( 'button_text' ),
		'hide_after'  => $date,
	);
}

/** Only links on this site are accepted for the Джазета source. */
function kzf_same_site( $url ) {
	$home = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
	$host = wp_parse_url( $url, PHP_URL_HOST );
	return $url && $home && $host && strtolower( $home ) === strtolower( $host );
}

/* ------------------------------------------------------------------ events */

/** Find the event post behind a link like https://kozlovclub.ru/event/some-slug/ */
function kzf_event_post_id( $url ) {
	if ( ! $url || ! kzf_same_site( $url ) ) {
		return 0;
	}
	$id = url_to_postid( $url );
	if ( $id ) {
		return (int) $id;
	}
	$path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
	if ( ! $path ) {
		return 0;
	}
	$slug  = basename( $path );
	$types = array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment', 'page', 'post' ) ) );
	if ( ! $types ) {
		return 0;
	}
	$found = get_posts(
		array(
			'name'             => $slug,
			'post_type'        => $types,
			'post_status'      => 'publish',
			'numberposts'      => 1,
			'fields'           => 'ids',
			'suppress_filters' => true,
		)
	);
	return $found ? (int) $found[0] : 0;
}

/** Start, end, venue and price from the All-in-One Event Calendar table, when it exists. */
function kzf_ai1ec_row( $post_id ) {
	global $wpdb;
	static $has_table = null;
	$table = $wpdb->prefix . 'ai1ec_events';
	if ( null === $has_table ) {
		$has_table = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );
	}
	if ( ! $has_table ) {
		return null;
	}
	$prev = $wpdb->suppress_errors( true );
	$row  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE post_id = %d LIMIT 1", $post_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->suppress_errors( $prev );
	return is_array( $row ) ? $row : null;
}

/**
 * Ticketscloud event id. Taken only from a field we can name:
 * the key set in the plugin settings, or else a field whose name says it is Ticketscloud.
 * Returns array( id, key ) or array( '', '' ). Never guesses from unrelated fields.
 */
function kzf_tc_id( $post_id ) {
	$valid = function ( $v ) {
		return is_string( $v ) && preg_match( '/^[a-f0-9]{24}$/', trim( $v ) ) ? trim( $v ) : '';
	};
	$key = trim( (string) kzf_settings()['tc_meta_key'] );
	if ( '' !== $key ) {
		return array( $valid( get_post_meta( $post_id, $key, true ) ), $key );
	}
	$meta = get_post_meta( $post_id );
	if ( is_array( $meta ) ) {
		foreach ( $meta as $k => $values ) {
			if ( ! preg_match( '/ticketscloud|tc[_-]?event|tc[_-]?id/i', $k ) ) {
				continue;
			}
			foreach ( (array) $values as $v ) {
				$id = $valid( $v );
				if ( $id ) {
					return array( $id, $k );
				}
			}
		}
	}
	return array( '', '' );
}

/** Russian part of a WPGlobus string: "[:ru]…[:en]…" or "{:ru}…{:}{:en}…{:}". Plain text passes through. */
function kzf_ru_text( $s ) {
	$s = (string) $s;
	if ( preg_match( '/\{:ru\}(.*?)\{:\}/su', $s, $m ) || preg_match( '/\[:ru\](.*?)(?=\[:[a-z]{0,2}\]|$)/su', $s, $m ) ) {
		$s = $m[1];
	}
	$s = preg_replace( '/\{:[a-z]{0,2}\}|\[:[a-z]{0,2}\]/u', '', $s );
	return trim( preg_replace( '/\s+/u', ' ', $s ) );
}

/**
 * Price from the calendar's cost field. The calendar keeps it serialized,
 * e.g. a:2:{s:4:"cost";s:..:"[:ru]от 1500 руб.[:en]…";s:7:"is_free";b:0;}
 */
function kzf_cost_text( $raw ) {
	$v    = is_string( $raw ) ? trim( $raw ) : $raw;
	$free = false;
	if ( is_string( $v ) && preg_match( '/^a:\d+:\{/', $v ) ) {
		$u = @unserialize( $v, array( 'allowed_classes' => false ) ); // phpcs:ignore -- calendar data, objects refused
		$v = is_array( $u ) ? $u : '';
	}
	if ( is_array( $v ) ) {
		$free = ! empty( $v['is_free'] );
		$v    = isset( $v['cost'] ) && is_scalar( $v['cost'] ) ? $v['cost'] : '';
	}
	$text = kzf_ru_text( is_scalar( $v ) ? $v : '' );
	if ( '' === $text ) {
		return $free ? 'Вход свободный' : '';
	}
	return kzf_price_text( $text );
}

/**
 * The club's stages. On kozlovclub.ru the stage of a concert is a set of Magic Fields checkboxes
 * on the announcement: location_main_scene, location_the_beat, location_mansarda, location_myasnitskaya
 * (checked on the live site in October 2026, the Event Schema plugin reads the same fields).
 */
function kzf_stage_names() {
	return array(
		'main_scene'   => 'Главная сцена',
		'the_beat'     => 'The Beat',
		'mansarda'     => 'Мансарда',
		'myasnitskaya' => 'Сцена на Мясницкой, 15',
	);
}

/** One of the known stage names if the value names a stage (by code or by name), else ''. */
function kzf_stage_match( $value ) {
	if ( ! is_string( $value ) ) {
		return '';
	}
	$key = mb_strtolower( kzf_ru_text( wp_strip_all_tags( $value ) ) );
	if ( 'mansard' === $key ) {
		$key = 'mansarda'; // the code used in the afisha links
	}
	foreach ( kzf_stage_names() as $code => $name ) {
		if ( $key === $code || $key === mb_strtolower( $name ) ) {
			return $name;
		}
	}
	return '';
}

/** Stage of a concert: the checked stage box; else a calendar category named after a stage; else the venue field. */
function kzf_stage( $post_id, $row ) {
	foreach ( kzf_stage_names() as $code => $name ) {
		$v = get_post_meta( $post_id, 'location_' . $code, true );
		$v = is_array( $v ) ? reset( $v ) : $v;
		$v = strtolower( trim( (string) $v ) );
		if ( '' !== $v && ! in_array( $v, array( '0', 'false', 'no', 'off' ), true ) ) {
			return $name;
		}
	}
	foreach ( (array) get_object_taxonomies( (string) get_post_type( $post_id ) ) as $tax ) {
		$terms = get_the_terms( $post_id, $tax );
		if ( is_array( $terms ) ) {
			foreach ( $terms as $t ) {
				$s = kzf_stage_match( $t->slug );
				$s = $s ? $s : kzf_stage_match( $t->name );
				if ( $s ) {
					return $s;
				}
			}
		}
	}
	return ( $row && isset( $row['venue'] ) ) ? kzf_stage_match( (string) $row['venue'] ) : '';
}

/**
 * The calendar stores the time an editor typed, counted in the event's zone (on this site usually "UTC").
 * That typed time is Moscow time, as the site shows it. Returns the real moment (unix time).
 */
function kzf_club_time( $ts, $tzname ) {
	try {
		$tz = ( '' === $tzname ) ? wp_timezone() : new DateTimeZone( $tzname );   // no zone: the calendar uses the site's zone
	} catch ( Exception $e ) {
		$tz = new DateTimeZone( 'UTC' );
	}
	$d = new DateTime( '@' . (int) $ts );
	$d->setTimezone( $tz );
	$m = new DateTime( $d->format( 'Y-m-d H:i:s' ), new DateTimeZone( 'Europe/Moscow' ) );
	return $m->getTimestamp();
}

function kzf_ru_date( $ts, $tz ) {
	$days   = array( 'Вс', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб' );
	$months = array( 1 => 'января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря' );
	$d      = new DateTime( '@' . (int) $ts );
	$d->setTimezone( $tz );
	return $days[ (int) $d->format( 'w' ) ] . ', ' . (int) $d->format( 'j' ) . ' ' . $months[ (int) $d->format( 'n' ) ] . ' · ' . $d->format( 'H:i' );
}

function kzf_price_text( $cost ) {
	$cost = trim( (string) $cost );
	if ( '' === $cost ) {
		return '';
	}
	$num = preg_replace( '/[^\d]/', '', $cost );
	if ( '' !== $num && preg_match( '/^\D*\d[\d\s]*\D*$/u', $cost ) ) {
		return 'от ' . number_format( (int) $num, 0, '', ' ' ) . ' ₽';
	}
	return $cost;
}

/** Everything the card can know about an event page. */
function kzf_event_data( $url ) {
	$id = kzf_event_post_id( $url );
	if ( ! $id || 'publish' !== get_post_status( $id ) ) {
		return null;
	}
	list( $tc, $tc_key ) = kzf_tc_id( $id );
	$out = array(
		'post_id' => $id,
		'title'   => kzf_ru_text( html_entity_decode( wp_strip_all_tags( get_the_title( $id ) ), ENT_QUOTES, 'UTF-8' ) ),
		'link'    => get_permalink( $id ),
		'image'   => '',
		'start'   => 0,
		'end'     => 0,
		'when'    => '',
		'stage'   => '',
		'price'   => '',
		'tc_id'   => $tc,
		'tc_key'  => $tc_key,
	);
	$size             = has_image_size( 'slider-thumb' ) ? 'slider-thumb' : 'medium';
	$out['image']     = (string) get_the_post_thumbnail_url( $id, $size );
	$out['image_src'] = $out['image'] ? 'изображение записи' : '';
	if ( ! $out['image'] ) {
		list( $out['image'], $out['image_src'] ) = kzf_mf_image( $id );
	}

	$row = kzf_ai1ec_row( $id );
	if ( $row && ! empty( $row['start'] ) ) {
		$tzname       = isset( $row['timezone_name'] ) ? trim( (string) $row['timezone_name'] ) : '';
		$out['start'] = kzf_club_time( (int) $row['start'], $tzname );
		$out['end']   = ! empty( $row['end'] ) ? kzf_club_time( (int) $row['end'], $tzname ) : 0;
		$out['when']  = kzf_ru_date( $out['start'], new DateTimeZone( 'Europe/Moscow' ) );
		$out['price'] = kzf_cost_text( isset( $row['cost'] ) ? $row['cost'] : '' );
	}
	$out['stage'] = kzf_stage( $id, $row );
	return $out;
}

/**
 * The announcement picture from Magic Fields when the post has no featured image.
 * The site keeps it as a file name in /wp-content/files_mf/ (field anons_thumb on the concert page),
 * or as an attachment id. Returns array( url, field name ) or array( '', '' ).
 */
function kzf_mf_image( $post_id ) {
	$meta = get_post_meta( $post_id );
	if ( ! is_array( $meta ) ) {
		return array( '', '' );
	}
	$keys = array_keys( $meta );
	usort( $keys, function ( $a, $b ) {           // the known field first, then other picture-like names
		return ( 'anons_thumb' === $b ) - ( 'anons_thumb' === $a );
	} );
	foreach ( $keys as $k ) {
		if ( 'anons_thumb' !== $k && ! preg_match( '/thumb|image|photo|foto|pic|img/i', $k ) ) {
			continue;
		}
		foreach ( (array) $meta[ $k ] as $v ) {
			$v = is_string( $v ) ? trim( $v ) : '';
			if ( '' === $v ) {
				continue;
			}
			if ( ctype_digit( $v ) ) {
				$url = (string) wp_get_attachment_image_url( (int) $v, 'medium' );
				if ( $url ) {
					return array( $url, $k );
				}
				continue;
			}
			if ( ! preg_match( '/\.(jpe?g|png|webp|gif)$/i', $v ) ) {
				continue;
			}
			if ( preg_match( '#^https?://#i', $v ) ) {
				return kzf_same_site( $v ) ? array( $v, $k ) : array( '', '' );
			}
			$path = ( false === strpos( $v, '/' ) ) ? content_url( '/files_mf/' . rawurlencode( $v ) ) : home_url( '/' . ltrim( $v, '/' ) );
			return array( $path, $k );
		}
	}
	return array( '', '' );
}

/** The moment an event card should disappear: the real end (plus half an hour), else start + 4 h. */
function kzf_event_gone_at( $e ) {
	if ( $e['end'] && $e['end'] > $e['start'] ) {
		return $e['end'] + 30 * MINUTE_IN_SECONDS;
	}
	return $e['start'] ? $e['start'] + 4 * HOUR_IN_SECONDS : 0;
}

/* ------------------------------------------------------------------ Джазета (cached, refreshed off the page) */

function kzf_gazeta_url( $link ) {
	return ( $link && kzf_same_site( $link ) ) ? $link : home_url( '/gazeta/' );
}

/** Read the last stored issue. Never fetches. */
function kzf_gazeta_cached( $url ) {
	$all = get_option( 'kzf_gazeta_cache', array() );
	$key = md5( $url );
	return ( is_array( $all ) && isset( $all[ $key ] ) && is_array( $all[ $key ] ) ) ? $all[ $key ] : null;
}

/** Fetch the issue page and store number, date and lead headline. Runs from cron and on save. */
function kzf_gazeta_refresh( $url ) {
	if ( ! kzf_same_site( $url ) ) {
		return null;
	}
	$res = wp_remote_get(
		$url,
		array(
			'timeout'     => 8,
			'redirection' => 2,
			'headers'     => array( 'Cookie' => 'beget=begetok' ),
			'user-agent'  => 'KozlovFacade/' . KZF_VER . '; ' . home_url( '/' ),
		)
	);
	if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
		return null;
	}
	$html = (string) wp_remote_retrieve_body( $res );
	$out  = kzf_gazeta_parse( $html );
	if ( ! $out ) {
		return null; // keep the previous good issue
	}
	$old = kzf_gazeta_cached( $url );
	$all                = get_option( 'kzf_gazeta_cache', array() );
	$all                = is_array( $all ) ? $all : array();
	$all[ md5( $url ) ] = $out;
	update_option( 'kzf_gazeta_cache', $all, false );
	if ( ! $old || $old['no'] !== $out['no'] || $old['headline'] !== $out['headline'] ) {
		kzf_flush_display_cache();
		kzf_purge_front_cache();   // a new issue: the cached home page must not keep the old one
	}
	return $out;
}

/**
 * Number, date and lead headline of an issue page. Read in order of reliability:
 * og:title (Ежедневная Джазета · № 009 · 29 октября 2026), the first "headline" in the page's
 * structured data (the lead story), then the story titles, then any longer h2.
 */
function kzf_gazeta_parse( $html ) {
	$out  = array( 'no' => '', 'date' => '', 'headline' => '', 'at' => time() );
	$name = '';
	if ( preg_match( '#<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)#iu', $html, $m ) ) {
		$name = html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' );
	} elseif ( preg_match( '#<title>(.*?)</title>#su', $html, $m ) ) {
		$name = html_entity_decode( wp_strip_all_tags( $m[1] ), ENT_QUOTES, 'UTF-8' );
	}
	if ( preg_match( '/№\s*([0-9]+)/u', $name, $n ) ) {
		$out['no'] = '№ ' . $n[1];
	}
	if ( preg_match( '/(\d{1,2})\s+([а-яё]+)\s+\d{4}/u', $name, $d ) ) {
		$out['date'] = $d[1] . ' ' . $d[2];
	}
	$clean = function ( $t ) {
		return trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( (string) $t ), ENT_QUOTES, 'UTF-8' ) ) );
	};
	if ( preg_match_all( '#<script[^>]+application/ld\+json[^>]*>(.*?)</script>#su', $html, $blocks ) ) {
		foreach ( $blocks[1] as $json ) {
			if ( preg_match( '/"headline"\s*:\s*("(?:[^"\\\\]|\\\\.)*")/u', $json, $h ) ) {
				$t = $clean( json_decode( $h[1] ) );
				if ( mb_strlen( $t ) > 12 ) {
					$out['headline'] = $t;
					break;
				}
			}
		}
	}
	if ( ! $out['headline'] && preg_match( '#<h2[^>]*class=["\'][^"\']*story__title[^"\']*["\'][^>]*>(.*?)</h2>#su', $html, $h ) ) {
		$out['headline'] = $clean( $h[1] );
	}
	if ( ! $out['headline'] && preg_match_all( '#<h2[^>]*>(.*?)</h2>#su', $html, $hs ) ) {
		$skip = array( 'оформление', 'коротко о номере', 'скрытая партитура', 'архив', 'подписка' );
		foreach ( $hs[1] as $h ) {
			$h = $clean( $h );
			if ( mb_strlen( $h ) > 20 && ! in_array( mb_strtolower( $h ), $skip, true ) ) {
				$out['headline'] = $h;
				break;
			}
		}
	}
	return $out['headline'] ? $out : null;
}

/** Cron task and save hook: refresh every Джазета slide's issue. */
function kzf_refresh_all_gazeta() {
	foreach ( kzf_slides() as $s ) {
		if ( is_array( $s ) && isset( $s['type'] ) && 'gazeta' === $s['type'] ) {
			kzf_gazeta_refresh( kzf_gazeta_url( isset( $s['link'] ) ? $s['link'] : '' ) );
		}
	}
	kzf_flush_display_cache();
}
add_action( 'kzf_refresh_gazeta', 'kzf_refresh_all_gazeta' );

/* ------------------------------------------------------------------ what the card shows */

/** End of a calendar day (YYYY-MM-DD) in the site's time zone. */
function kzf_day_end( $date ) {
	try {
		return ( new DateTime( $date . ' 23:59:59', wp_timezone() ) )->getTimestamp();
	} catch ( Exception $e ) {
		return 0;
	}
}

/** One slide resolved to display fields, or null when it should not show. */
function kzf_resolve( $s, $now = null ) {
	$now = $now ? $now : time();
	if ( empty( $s['enabled'] ) ) {
		return null;
	}
	if ( ! empty( $s['hide_after'] ) && kzf_day_end( $s['hide_after'] ) < $now ) {
		return null;
	}
	$v = array(
		'until'    => $s['hide_after'] ? kzf_day_end( $s['hide_after'] ) : 0,
		'kind'     => $s['type'],
		'label'    => $s['label'],
		'when'     => $s['date_text'],
		'title'    => $s['title'],
		'subtitle' => $s['subtitle'],
		'meta'     => $s['meta_text'],
		'image'    => $s['image_id'] ? (string) wp_get_attachment_image_url( $s['image_id'], 'medium' ) : '',
		'link'     => $s['link'],
		'button'   => $s['button_text'],
		'tc_id'    => $s['tc_id'],
		'tc_key'   => $s['tc_id'] ? 'вручную' : '',
		'paper'    => null,
	);

	if ( 'event' === $s['type'] ) {
		$e = kzf_event_data( $s['event_url'] );
		if ( ! $e ) {
			return null;
		}
		$gone = kzf_event_gone_at( $e );
		if ( ! $s['hide_after'] && $gone && $gone < $now ) {
			return null;
		}
		$title = $e['title'];
		$sub   = '';
		if ( false !== mb_strpos( $title, ':' ) ) {
			list( $title, $sub ) = array_map( 'trim', explode( ':', $title, 2 ) );
		}
		$v['label']    = $v['label'] ? $v['label'] : 'Концерт';
		$v['title']    = $v['title'] ? $v['title'] : $title;
		$v['subtitle'] = $v['subtitle'] ? $v['subtitle'] : $sub;
		$v['when']     = $v['when'] ? $v['when'] : $e['when'];
		$v['meta']     = $v['meta'] ? $v['meta'] : implode( ' · ', array_filter( array( $e['stage'], $e['price'] ) ) );
		$v['image']    = $v['image'] ? $v['image'] : $e['image'];
		$v['link']     = $e['link'];
		if ( ! $v['tc_id'] ) {
			$v['tc_id']  = $e['tc_id'];
			$v['tc_key'] = $e['tc_key'];
		}
		$v['button']    = $v['button'] ? $v['button'] : 'Купить билет';
		$v['until']     = $s['hide_after'] ? kzf_day_end( $s['hide_after'] ) : (int) $gone;
		$v['image_src'] = $v['image'] === $e['image'] ? $e['image_src'] : 'вручную';
	} elseif ( 'gazeta' === $s['type'] ) {
		$url = kzf_gazeta_url( $s['link'] );
		$g   = kzf_gazeta_cached( $url );
		$v['label']  = $v['label'] ? $v['label'] : 'Джазета · свежий номер';
		$v['title']  = $v['title'] ? $v['title'] : ( $g ? $g['headline'] : '' );
		$v['when']   = $v['when'] ? $v['when'] : ( $g ? $g['date'] : '' );
		$v['link']   = $url;
		$v['button'] = $v['button'] ? $v['button'] : 'Читать номер';
		$v['paper']  = array( 'no' => $g ? $g['no'] : '', 'date' => $g ? $g['date'] : '' );
		if ( ! $v['title'] ) {
			return null;
		}
	} else { // image with a link
		if ( ! $v['title'] && ! $v['image'] ) {
			return null;
		}
		$v['button'] = $v['button'] ? $v['button'] : 'Подробнее';
	}
	return $v;
}

function kzf_flush_display_cache() {
	delete_transient( 'kzf_display' );
}

/**
 * The site serves the home page from W3 Total Cache (disk). When the cards change (saved,
 * a concert has ended, a new Джазета issue) the cached home page is purged, only that page.
 * Other page caches can listen to the kzf_front_changed action.
 */
function kzf_purge_front_cache() {
	try {
		$home = home_url( '/' );
		if ( function_exists( 'w3tc_flush_url' ) ) {
			w3tc_flush_url( $home );
		}
		do_action( 'kzf_front_changed', $home );
	} catch ( Throwable $e ) {
		return;
	}
}

/** One job at the moment the next card expires: rebuild the cards and purge the cached home page. */
function kzf_schedule_expiry( $slides ) {
	$next = 0;
	foreach ( $slides as $v ) {
		if ( ! empty( $v['until'] ) && $v['until'] > time() && ( ! $next || $v['until'] < $next ) ) {
			$next = (int) $v['until'];
		}
	}
	$have = wp_next_scheduled( 'kzf_expire' );
	if ( $next && ( ! $have || abs( $have - ( $next + 60 ) ) > 60 ) ) {
		wp_clear_scheduled_hook( 'kzf_expire' );
		wp_schedule_single_event( $next + 60, 'kzf_expire' );
	}
}
add_action( 'kzf_expire', 'kzf_on_expire' );
function kzf_on_expire() {
	kzf_flush_display_cache();
	kzf_schedule_expiry( kzf_display_slides() );
	kzf_purge_front_cache();
}
// an edited concert (new date, price, photo) reaches the card at once, not after the 10-minute cache
add_action( 'save_post', 'kzf_on_save_post', 10, 1 );
function kzf_on_save_post( $post_id ) {
	kzf_flush_display_cache();
	try {
		if ( 'ai1ec_event' === get_post_type( $post_id ) ) {
			kzf_purge_front_cache(); // the cached home page gets the corrected card
		}
	} catch ( Throwable $e ) {
		return;
	}
}

/** Cards for the front end, cached for 10 minutes (events disappear on time within that step). */
function kzf_display_slides() {
	$cached = get_transient( 'kzf_display' );
	if ( is_array( $cached ) && isset( $cached['v'], $cached['slides'] ) && KZF_VER === $cached['v'] && is_array( $cached['slides'] ) ) {
		return $cached['slides']; // cards built by another plugin version are never reused
	}
	$out = array();
	foreach ( kzf_slides() as $s ) {
		try {
			$r = kzf_resolve( kzf_sanitize_slide( $s ) );
			if ( $r ) {
				$out[] = $r;
			}
		} catch ( Throwable $e ) {
			continue; // one broken slide never takes the block down
		}
	}
	set_transient( 'kzf_display', array( 'v' => KZF_VER, 'slides' => $out ), 10 * MINUTE_IN_SECONDS );
	try {
		kzf_schedule_expiry( $out );
	} catch ( Throwable $e ) {
		unset( $e ); // the browser also hides ended cards by itself
	}
	return $out;
}
