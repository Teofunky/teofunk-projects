<?php
/**
 * Данные меню: тип записи «Раздел меню», блюда в мета kzm_items.
 *
 * Блюдо: title, desc, image (ID вложения), variants [{size, price}], kcal, hidden.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KZM_Data {

	const GROUPS = array(
		'kitchen' => 'Кухня',
		'brunch'  => 'Бранч',
		'bar'     => 'Бар',
	);

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
	}

	public static function register() {
		register_post_type(
			KZM_PT,
			array(
				'labels'          => array(
					'name'          => 'Меню ресторана',
					'singular_name' => 'Раздел меню',
					'menu_name'     => 'Меню ресторана',
					'add_new'       => 'Добавить раздел',
					'add_new_item'  => 'Новый раздел меню',
					'edit_item'     => 'Раздел меню',
					'all_items'     => 'Все разделы',
					'search_items'  => 'Найти раздел',
					'not_found'     => 'Разделов нет',
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => true,
				'menu_position'   => 22,
				'menu_icon'       => 'dashicons-food',
				'supports'        => array( 'title', 'page-attributes' ),
				'capability_type' => 'page',
				'map_meta_cap'    => true,
				'hierarchical'    => false,
			)
		);
	}

	/** Блюда раздела, очищенные. */
	public static function items( $post_id ) {
		$raw = get_post_meta( $post_id, 'kzm_items', true );
		if ( is_string( $raw ) ) {
			$raw = json_decode( $raw, true );
		}
		return is_array( $raw ) ? array_values( array_filter( array_map( array( __CLASS__, 'clean_item' ), $raw ) ) ) : array();
	}

	public static function clean_item( $it ) {
		if ( ! is_array( $it ) ) {
			return null;
		}
		$title = trim( sanitize_text_field( $it['title'] ?? '' ) );
		if ( '' === $title ) {
			return null;
		}
		$vars = array();
		foreach ( (array) ( $it['variants'] ?? array() ) as $v ) {
			$size  = trim( sanitize_text_field( $v['size'] ?? '' ) );
			$price = preg_replace( '/[^\d]/', '', (string) ( $v['price'] ?? '' ) );
			if ( '' !== $size || '' !== $price ) {
				$vars[] = array( 'size' => $size, 'price' => $price );
			}
		}
		return array(
			'title'    => $title,
			'desc'     => trim( sanitize_textarea_field( $it['desc'] ?? '' ) ),
			'image'    => absint( $it['image'] ?? 0 ),
			'variants' => $vars,
			'kcal'     => trim( sanitize_text_field( $it['kcal'] ?? '' ) ),
			'hidden'   => ! empty( $it['hidden'] ),
		);
	}

	public static function group( $post_id ) {
		$g = (string) get_post_meta( $post_id, 'kzm_group', true );
		return isset( self::GROUPS[ $g ] ) ? $g : 'kitchen';
	}

	/** Все опубликованные разделы по порядку. */
	public static function sections( $with_hidden = false ) {
		$posts = get_posts(
			array(
				'post_type'   => KZM_PT,
				'post_status' => 'publish',
				'numberposts' => -1,
				'orderby'     => array( 'menu_order' => 'ASC', 'ID' => 'ASC' ),
			)
		);
		$out = array();
		foreach ( $posts as $p ) {
			$items = self::items( $p->ID );
			if ( ! $with_hidden ) {
				$items = array_values( array_filter( $items, fn( $i ) => ! $i['hidden'] ) );
			}
			if ( ! $items && ! $with_hidden ) {
				continue;
			}
			$out[] = array(
				'id'    => $p->ID,
				'slug'  => $p->post_name ? $p->post_name : 'razdel-' . $p->ID,
				'title' => get_the_title( $p ),
				'note'  => (string) get_post_meta( $p->ID, 'kzm_note', true ),
				'group' => self::group( $p->ID ),
				'items' => $items,
			);
		}
		return $out;
	}

	public static function notice() {
		return (string) get_option( 'kzm_notice', 'Уважаемые гости, во время концертов обслуживание происходит в перерывах между композициями и отделениями.' );
	}

	public static function price( $p ) {
		return '' === $p ? '' : number_format( (int) $p, 0, '', ' ' ) . ' ₽';
	}
}
