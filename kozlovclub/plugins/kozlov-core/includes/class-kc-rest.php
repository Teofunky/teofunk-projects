<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KC_Rest {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route( 'kozlov/v1', '/concerts', array(
			'methods' => 'GET', 'callback' => array( __CLASS__, 'concerts' ), 'permission_callback' => '__return_true',
			'args' => array(
				'from' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'to' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'stage' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_title' ),
				'genre' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_title' ),
				'q' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'limit' => array( 'type' => 'integer', 'default' => 500, 'minimum' => 1, 'maximum' => 1000 ),
				'past' => array( 'type' => 'boolean', 'default' => false ),
			),
		) );
		register_rest_route( 'kozlov/v1', '/concerts/(?P<id>\d+)', array(
			'methods' => 'GET', 'callback' => array( __CLASS__, 'concert' ), 'permission_callback' => '__return_true',
		) );
		register_rest_route( 'kozlov/v1', '/dictionary', array(
			'methods' => 'GET', 'callback' => array( __CLASS__, 'dictionary' ), 'permission_callback' => '__return_true',
		) );
	}

	public static function concerts( WP_REST_Request $r ) {
		$items = kozlov_concerts( array(
			'from' => $r['from'], 'to' => $r['to'], 'stage' => $r['stage'], 'genre' => $r['genre'], 'q' => $r['q'],
			'limit' => $r['limit'], 'past' => $r['past'], 'order' => $r['past'] ? 'DESC' : 'ASC',
		) );
		$res = rest_ensure_response( array( 'count' => count( $items ), 'now' => wp_date( 'Y-m-d H:i:s' ), 'items' => $items ) );
		$res->header( 'Cache-Control', 'public, max-age=120' );
		return $res;
	}

	public static function concert( WP_REST_Request $r ) {
		$c = kozlov_concert( (int) $r['id'] );
		if ( ! $c || 'publish' !== get_post_status( (int) $r['id'] ) ) {
			return new WP_Error( 'kc_not_found', 'Концерт не найден', array( 'status' => 404 ) );
		}
		$c['content'] = apply_filters( 'the_content', get_post_field( 'post_content', $c['id'] ) );
		return rest_ensure_response( $c );
	}

	public static function dictionary() {
		$out = array( 'stages' => array(), 'genres' => array() );
		foreach ( get_terms( array( 'taxonomy' => KC_TAX_STAGE, 'hide_empty' => false ) ) as $t ) {
			$out['stages'][] = array( 'slug' => $t->slug, 'name' => $t->name, 'color' => get_term_meta( $t->term_id, 'kc_color', true ) ?: '#f24841', 'url' => get_term_link( $t ) );
		}
		foreach ( get_terms( array( 'taxonomy' => KC_TAX_GENRE, 'hide_empty' => false ) ) as $t ) {
			$out['genres'][] = array( 'slug' => $t->slug, 'name' => $t->name, 'count' => (int) $t->count, 'url' => get_term_link( $t ) );
		}
		return rest_ensure_response( $out );
	}
}
