<?php
/**
 * Settings, slides and how each slide turns into what the card shows.
 * Storage: two options (kzf_settings, kzf_slides). No tables of its own.
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

/* ------------------------------------------------------------------ events */

/** Find the event post behind a link like https://kozlovclub.ru/event/some-slug/ */
function kzf_event_post_id( $url ) {
	if ( ! $url ) {
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
	$types = array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) );
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

/** Start time, timezone and price from the All-in-One Event Calendar table, when it exists. */
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

/** Ticketscloud event id: a 24-character hex value in the event's custom fields. */
function kzf_guess_tc_id( $post_id ) {
	$meta = get_post_meta( $post_id );
	if ( ! is_array( $meta ) ) {
		return '';
	}
	$best = '';
	foreach ( $meta as $key => $values ) {
		foreach ( (array) $values as $v ) {
			if ( is_string( $v ) && preg_match( '/^[a-f0-9]{24}$/', trim( $v ) ) ) {
				if ( preg_match( '/tc|ticket/i', $key ) ) {
					return trim( $v );
				}
				if ( ! $best ) {
					$best = trim( $v );
				}
			}
		}
	}
	return $best;
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
	if ( $num !== '' && preg_match( '/^\D*\d[\d\s]*\D*$/u', $cost ) ) {
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
	$out = array(
		'post_id' => $id,
		'title'   => wp_strip_all_tags( get_the_title( $id ) ),
		'link'    => get_permalink( $id ),
		'image'   => '',
		'start'   => 0,
		'when'    => '',
		'stage'   => '',
		'price'   => '',
		'tc_id'   => kzf_guess_tc_id( $id ),
	);
	$size         = has_image_size( 'slider-thumb' ) ? 'slider-thumb' : 'medium';
	$out['image'] = (string) get_the_post_thumbnail_url( $id, $size );

	$row = kzf_ai1ec_row( $id );
	if ( $row && ! empty( $row['start'] ) ) {
		$tzname = isset( $row['timezone_name'] ) ? trim( (string) $row['timezone_name'] ) : '';
		try {
			$tz = ( '' === $tzname ) ? new DateTimeZone( 'UTC' ) : new DateTimeZone( $tzname );
		} catch ( Exception $e ) {
			$tz = new DateTimeZone( 'UTC' );
		}
		$out['start'] = (int) $row['start'];
		$out['when']  = kzf_ru_date( (int) $row['start'], $tz );
		$out['price'] = kzf_price_text( isset( $row['cost'] ) ? $row['cost'] : '' );
	}
	foreach ( array( 'events_categories', 'events_tags' ) as $tax ) {
		if ( ! $out['stage'] && taxonomy_exists( $tax ) ) {
			$terms = get_the_terms( $id, $tax );
			if ( is_array( $terms ) && $terms ) {
				$out['stage'] = $terms[0]->name;
			}
		}
	}
	return $out;
}

/* ------------------------------------------------------------------ Джазета */

/** Latest issue: number, date and the lead headline, read from the /gazeta/ page (cached 30 min). */
function kzf_gazeta( $url ) {
	$key    = 'kzf_gazeta_' . md5( $url );
	$cached = get_transient( $key );
	if ( is_array( $cached ) ) {
		return $cached;
	}
	$out = array( 'no' => '', 'date' => '', 'headline' => '' );
	$res = wp_remote_get(
		$url,
		array(
			'timeout'     => 4,
			'redirection' => 2,
			'headers'     => array( 'Cookie' => 'beget=begetok' ),
			'user-agent'  => 'KozlovFacade/' . KZF_VER . '; ' . home_url( '/' ),
		)
	);
	if ( ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) ) {
		$html = (string) wp_remote_retrieve_body( $res );
		if ( preg_match( '#<title>(.*?)</title>#su', $html, $m ) ) {
			$t = html_entity_decode( wp_strip_all_tags( $m[1] ), ENT_QUOTES, 'UTF-8' );
			if ( preg_match( '/№\s*([0-9]+)/u', $t, $n ) ) {
				$out['no'] = '№ ' . $n[1];
			}
			if ( preg_match( '/(\d{1,2})\s+([а-яё]+)\s+\d{4}/u', $t, $d ) ) {
				$out['date'] = $d[1] . ' ' . $d[2];
			}
		}
		$skip = array( 'оформление', 'коротко о номере', 'скрытая партитура', 'архив', 'подписка' );
		if ( preg_match_all( '#<h2[^>]*>(.*?)</h2>#su', $html, $hs ) ) {
			foreach ( $hs[1] as $h ) {
				$h = trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $h ), ENT_QUOTES, 'UTF-8' ) ) );
				if ( mb_strlen( $h ) > 20 && ! in_array( mb_strtolower( $h ), $skip, true ) ) {
					$out['headline'] = $h;
					break;
				}
			}
		}
	}
	set_transient( $key, $out, $out['headline'] ? 30 * MINUTE_IN_SECONDS : 5 * MINUTE_IN_SECONDS );
	return $out;
}

/* ------------------------------------------------------------------ what the card shows */

/** One slide resolved to display fields, or null when it should not show. */
function kzf_resolve( $s, $now = null ) {
	$now = $now ? $now : time();
	if ( empty( $s['enabled'] ) ) {
		return null;
	}
	if ( ! empty( $s['hide_after'] ) && strtotime( $s['hide_after'] . ' 23:59:59' ) < $now ) {
		return null;
	}
	$v = array(
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
		'paper'    => null,
	);

	if ( 'event' === $s['type'] ) {
		$e = kzf_event_data( $s['event_url'] );
		if ( ! $e ) {
			return null;
		}
		if ( ! $s['hide_after'] && $e['start'] && $e['start'] + 6 * HOUR_IN_SECONDS < $now ) {
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
		$v['tc_id']    = $v['tc_id'] ? $v['tc_id'] : $e['tc_id'];
		$v['button']   = $v['button'] ? $v['button'] : 'Купить билет';
	} elseif ( 'gazeta' === $s['type'] ) {
		$url = $s['link'] ? $s['link'] : home_url( '/gazeta/' );
		$g   = kzf_gazeta( $url );
		$v['label']  = $v['label'] ? $v['label'] : 'Джазета · свежий номер';
		$v['title']  = $v['title'] ? $v['title'] : $g['headline'];
		$v['when']   = $v['when'] ? $v['when'] : $g['date'];
		$v['link']   = $url;
		$v['button'] = $v['button'] ? $v['button'] : 'Читать номер';
		$v['paper']  = array( 'no' => $g['no'], 'date' => $g['date'] );
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

function kzf_display_slides() {
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
	return $out;
}
