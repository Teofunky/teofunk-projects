<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KC_Query {

	public static function init() {
		add_action( 'pre_get_posts', array( __CLASS__, 'order_archives' ) );
	}

	/** Архив концертов, сцены и жанры: только будущие, по дате. ?archive=1 показывает прошедшие, новые сверху. */
	public static function order_archives( $q ) {
		if ( is_admin() || ! $q->is_main_query() ) {
			return;
		}
		if ( ! ( $q->is_post_type_archive( KC_POST_TYPE ) || $q->is_tax( array( KC_TAX_STAGE, KC_TAX_GENRE, KC_TAX_TAG ) ) ) ) {
			return;
		}
		$past = ! empty( $_GET['archive'] );
		$q->set( 'post_type', KC_POST_TYPE );
		$q->set( 'meta_key', 'kc_start' );
		$q->set( 'orderby', 'meta_value' );
		$q->set( 'order', $past ? 'DESC' : 'ASC' );
		$q->set( 'posts_per_page', $past ? 48 : 500 );
		$q->set( 'meta_query', array( array( 'key' => 'kc_start', 'value' => self::now_cut(), 'compare' => $past ? '<' : '>=', 'type' => 'CHAR' ) ) );
	}

	/** Граница «актуального»: концерт остаётся в афише до 3 часов после начала. */
	public static function now_cut() {
		return wp_date( 'Y-m-d H:i:s', current_datetime()->getTimestamp() - 3 * HOUR_IN_SECONDS );
	}

	public static function concerts( $args = array() ) {
		$a = wp_parse_args( $args, array( 'from' => '', 'to' => '', 'stage' => '', 'genre' => '', 'q' => '', 'limit' => 500, 'past' => false, 'order' => 'ASC', 'ids_only' => false, 'slider' => false, 'status' => 'publish' ) );
		$meta = array( 'relation' => 'AND' );
		if ( $a['from'] ) {
			$meta[] = array( 'key' => 'kc_start', 'value' => $a['from'] . ( strlen( $a['from'] ) === 10 ? ' 00:00:00' : '' ), 'compare' => '>=', 'type' => 'CHAR' );
		} elseif ( ! $a['past'] ) {
			$meta[] = array( 'key' => 'kc_start', 'value' => self::now_cut(), 'compare' => '>=', 'type' => 'CHAR' );
		}
		if ( $a['to'] ) {
			$meta[] = array( 'key' => 'kc_start', 'value' => $a['to'] . ( strlen( $a['to'] ) === 10 ? ' 23:59:59' : '' ), 'compare' => '<=', 'type' => 'CHAR' );
		}
		if ( $a['slider'] ) {
			$meta[] = array( 'key' => 'kc_slider_show', 'value' => '1' );
		}
		$tax = array();
		if ( $a['stage'] ) {
			$tax[] = array( 'taxonomy' => KC_TAX_STAGE, 'field' => 'slug', 'terms' => (array) $a['stage'] );
		}
		if ( $a['genre'] ) {
			$tax[] = array( 'taxonomy' => KC_TAX_GENRE, 'field' => 'slug', 'terms' => (array) $a['genre'] );
		}
		$query = array(
			'post_type' => KC_POST_TYPE, 'post_status' => $a['status'], 'posts_per_page' => (int) $a['limit'], 'no_found_rows' => true,
			'meta_key' => 'kc_start', 'orderby' => 'meta_value', 'order' => 'DESC' === strtoupper( $a['order'] ) ? 'DESC' : 'ASC',
			'meta_query' => $meta, 'fields' => $a['ids_only'] ? 'ids' : 'all', 'update_post_term_cache' => ! $a['ids_only'],
		);
		if ( $tax ) {
			$query['tax_query'] = $tax;
		}
		if ( $a['q'] ) {
			$query['s'] = $a['q'];
		}
		$posts = get_posts( $query );
		if ( $a['ids_only'] ) {
			return $posts;
		}
		$out = array();
		foreach ( $posts as $p ) {
			$out[] = kozlov_concert( $p );
		}
		return $out;
	}
}
