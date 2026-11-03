<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KC_Post_Type {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ), 5 );
		add_action( 'init', array( __CLASS__, 'legacy_rewrites' ), 6 );
		add_action( 'template_redirect', array( __CLASS__, 'legacy_redirect' ), 1 );
		add_action( 'after_setup_theme', array( __CLASS__, 'image_sizes' ) );
		add_filter( 'post_type_link', array( __CLASS__, 'permalink' ), 10, 2 );
		// Концерты редактируются в классическом редакторе: так привыкли редакторы, и так работают поля ниже.
		add_filter( 'use_block_editor_for_post_type', function ( $use, $type ) { return KC_POST_TYPE === $type ? false : $use; }, 10, 2 );
	}

	public static function image_sizes() {
		add_theme_support( 'post-thumbnails' );
		add_image_size( 'kc-card', 450, 338, true );     // карточка афиши 4:3 (как category-thumb2)
		add_image_size( 'kc-card-small', 320, 240, true );
		add_image_size( 'kc-slider', 300, 400, true );   // слайдер главной
		add_image_size( 'kc-hero', 1600, 1000, false );  // большая фотография страницы концерта
	}

	public static function register() {
		register_post_type( KC_POST_TYPE, array(
			'labels' => array(
				'name' => 'Концерты', 'singular_name' => 'Концерт', 'menu_name' => 'Концерты', 'add_new' => 'Добавить',
				'add_new_item' => 'Новый концерт', 'edit_item' => 'Редактировать концерт', 'new_item' => 'Новый концерт',
				'view_item' => 'Смотреть концерт', 'search_items' => 'Найти концерт', 'not_found' => 'Концертов нет',
				'not_found_in_trash' => 'В корзине пусто', 'all_items' => 'Все концерты',
			),
			'public' => true, 'show_in_rest' => true, 'rest_base' => 'concerts', 'menu_position' => 5, 'menu_icon' => 'dashicons-tickets-alt',
			'supports' => array( 'title', 'editor', 'thumbnail', 'excerpt', 'revisions', 'author' ),
			'has_archive' => 'afisha', 'rewrite' => array( 'slug' => 'event', 'with_front' => false ),
			'capability_type' => 'post', 'map_meta_cap' => true, 'taxonomies' => array( KC_TAX_STAGE, KC_TAX_GENRE, KC_TAX_TAG ),
		) );

		register_taxonomy( KC_TAX_STAGE, KC_POST_TYPE, array(
			'labels' => array( 'name' => 'Сцены', 'singular_name' => 'Сцена', 'menu_name' => 'Сцены', 'all_items' => 'Все сцены', 'edit_item' => 'Сцена', 'add_new_item' => 'Новая сцена' ),
			'public' => true, 'hierarchical' => true, 'show_in_rest' => true, 'show_admin_column' => true,
			'rewrite' => array( 'slug' => 'stage', 'with_front' => false ), 'meta_box_cb' => false,
		) );
		register_taxonomy( KC_TAX_GENRE, KC_POST_TYPE, array(
			'labels' => array( 'name' => 'Жанры', 'singular_name' => 'Жанр', 'menu_name' => 'Жанры', 'all_items' => 'Все жанры', 'edit_item' => 'Жанр', 'add_new_item' => 'Новый жанр' ),
			'public' => true, 'hierarchical' => true, 'show_in_rest' => true, 'show_admin_column' => true,
			'rewrite' => array( 'slug' => 'genre', 'with_front' => false ),
		) );
		register_taxonomy( KC_TAX_TAG, KC_POST_TYPE, array(
			'labels' => array( 'name' => 'Теги концертов', 'singular_name' => 'Тег', 'menu_name' => 'Теги', 'all_items' => 'Все теги', 'edit_item' => 'Тег', 'add_new_item' => 'Новый тег' ),
			'public' => true, 'hierarchical' => false, 'show_in_rest' => true, 'show_admin_column' => false,
			'rewrite' => array( 'slug' => 'concert-tag', 'with_front' => false ),
		) );

		register_term_meta( KC_TAX_STAGE, 'kc_color', array( 'type' => 'string', 'single' => true, 'show_in_rest' => true, 'sanitize_callback' => 'sanitize_hex_color' ) );
		register_term_meta( KC_TAX_STAGE, 'kc_legacy_field', array( 'type' => 'string', 'single' => true, 'show_in_rest' => false, 'sanitize_callback' => 'sanitize_key' ) );
	}

	/** Сцены и жанры по умолчанию, только если их ещё нет. */
	public static function seed_terms() {
		foreach ( kc_default_stages() as $slug => $d ) {
			$t = term_exists( $slug, KC_TAX_STAGE );
			if ( ! $t ) {
				$t = wp_insert_term( $d[0], KC_TAX_STAGE, array( 'slug' => $slug ) );
			}
			if ( ! is_wp_error( $t ) ) {
				$tid = (int) ( is_array( $t ) ? $t['term_id'] : $t );
				if ( ! get_term_meta( $tid, 'kc_color', true ) ) {
					update_term_meta( $tid, 'kc_color', $d[1] );
				}
				update_term_meta( $tid, 'kc_legacy_field', $d[2] );
			}
		}
		foreach ( kc_default_genres() as $slug => $name ) {
			if ( ! term_exists( $slug, KC_TAX_GENRE ) ) {
				wp_insert_term( $name, KC_TAX_GENRE, array( 'slug' => $slug ) );
			}
		}
	}

	/** Адрес концерта всегда /event/slug/, как на старом сайте. */
	public static function permalink( $link, $post ) {
		return $link;
	}

	/** Старые адреса /ru/event/slug и /en/event/slug ведут на /event/slug/. */
	public static function legacy_rewrites() {
		add_rewrite_rule( '^(ru|en)/event/([^/]+)/?$', 'index.php?post_type=' . KC_POST_TYPE . '&name=$matches[2]&kc_legacy=1', 'top' );
		add_rewrite_tag( '%kc_legacy%', '([0-1])' );
	}

	public static function legacy_redirect() {
		if ( get_query_var( 'kc_legacy' ) && is_singular( KC_POST_TYPE ) ) {
			wp_safe_redirect( get_permalink( get_queried_object_id() ), 301 );
			exit;
		}
		// Старый фильтр главной: ?genre=jazz, ?sort=main_scene
		if ( is_front_page() && ! is_admin() ) {
			$genre = isset( $_GET['genre'] ) ? sanitize_title( wp_unslash( $_GET['genre'] ) ) : '';
			$sort  = isset( $_GET['sort'] ) ? sanitize_key( wp_unslash( $_GET['sort'] ) ) : '';
			$map   = array( 'main_scene' => 'main-scene', 'mansard' => 'mansarda', 'the_beat' => 'the-beat', 'myasnitskaya' => 'myasnitskaya' );
			if ( $genre && term_exists( $genre, KC_TAX_GENRE ) ) {
				wp_safe_redirect( get_term_link( $genre, KC_TAX_GENRE ), 301 );
				exit;
			}
			if ( $sort && isset( $map[ $sort ] ) && term_exists( $map[ $sort ], KC_TAX_STAGE ) ) {
				wp_safe_redirect( get_term_link( $map[ $sort ], KC_TAX_STAGE ), 301 );
				exit;
			}
		}
	}
}
