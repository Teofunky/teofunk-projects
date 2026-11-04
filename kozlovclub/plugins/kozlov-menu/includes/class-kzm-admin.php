<?php
/**
 * Админка меню: редактор блюд раздела (фото, название, описание, объём и цена, скрыть), колонки списка, текст сверху страницы.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KZM_Admin {

	public static function init() {
		add_action( 'add_meta_boxes_' . KZM_PT, array( __CLASS__, 'boxes' ) );
		add_action( 'save_post_' . KZM_PT, array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'manage_' . KZM_PT . '_posts_columns', array( __CLASS__, 'cols' ) );
		add_action( 'manage_' . KZM_PT . '_posts_custom_column', array( __CLASS__, 'col' ), 10, 2 );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'order' ) );
	}

	public static function order( $q ) {
		if ( is_admin() && $q->is_main_query() && KZM_PT === $q->get( 'post_type' ) && ! $q->get( 'orderby' ) ) {
			$q->set( 'orderby', array( 'menu_order' => 'ASC', 'ID' => 'ASC' ) );
		}
	}

	public static function menu() {
		add_submenu_page( 'edit.php?post_type=' . KZM_PT, 'Настройки меню', 'Настройки', 'edit_pages', 'kzm-settings', array( __CLASS__, 'settings' ) );
	}

	public static function settings() {
		if ( isset( $_POST['kzm_notice'] ) && check_admin_referer( 'kzm_settings' ) ) {
			update_option( 'kzm_notice', sanitize_textarea_field( wp_unslash( $_POST['kzm_notice'] ) ) );
			echo '<div class="notice notice-success"><p>Сохранено.</p></div>';
		}
		$page = get_page_by_path( 'menu' );
		echo '<div class="wrap"><h1>Ресторан: настройки</h1><form method="post">';
		wp_nonce_field( 'kzm_settings' );
		echo '<p><label><strong>Текст над меню</strong><br><textarea name="kzm_notice" rows="3" class="large-text">' . esc_textarea( KZM_Data::notice() ) . '</textarea></label></p>';
		echo '<p class="description">Меню выводится на странице «' . ( $page ? esc_html( get_the_title( $page ) ) : 'menu' ) . '» само. На любой другой странице можно вставить шорткод <code>[kozlov_menu]</code>.</p>';
		submit_button( 'Сохранить' );
		echo '</form>';
		if ( $page ) {
			echo '<p><a class="button" href="' . esc_url( get_permalink( $page ) ) . '" target="_blank">Открыть меню на сайте ↗</a></p>';
		}
		echo '</div>';
	}

	public static function cols( $c ) {
		$new = array();
		foreach ( $c as $k => $v ) {
			$new[ $k ] = $v;
			if ( 'title' === $k ) {
				$new['kzm_group'] = 'Группа';
				$new['kzm_count'] = 'Позиций';
				$new['kzm_order'] = 'Порядок';
			}
		}
		unset( $new['date'] );
		return $new;
	}

	public static function col( $col, $id ) {
		if ( 'kzm_group' === $col ) {
			echo esc_html( KZM_Data::GROUPS[ KZM_Data::group( $id ) ] );
		} elseif ( 'kzm_count' === $col ) {
			$items  = KZM_Data::items( $id );
			$hidden = count( array_filter( $items, fn( $i ) => $i['hidden'] ) );
			echo (int) ( count( $items ) - $hidden ) . ( $hidden ? ' <span style="color:#888">+ ' . (int) $hidden . ' скрыто</span>' : '' );
		} elseif ( 'kzm_order' === $col ) {
			echo (int) get_post_field( 'menu_order', $id );
		}
	}

	public static function assets( $hook ) {
		$s = get_current_screen();
		if ( ! $s || KZM_PT !== $s->post_type || 'post' !== $s->base ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'kzm-admin', KZM_URL . 'assets/admin.css', array(), KZM_VER );
		wp_enqueue_script( 'kzm-admin', KZM_URL . 'assets/admin.js', array( 'jquery' ), KZM_VER, true );
	}

	public static function boxes( $post ) {
		add_meta_box( 'kzm_items', 'Блюда и напитки', array( __CLASS__, 'box_items' ), KZM_PT, 'normal', 'high' );
		add_meta_box( 'kzm_section', 'Раздел', array( __CLASS__, 'box_section' ), KZM_PT, 'side', 'high' );
	}

	public static function box_section( $post ) {
		$g = KZM_Data::group( $post->ID );
		echo '<p><label>Группа<br><select name="kzm_group" class="widefat">';
		foreach ( KZM_Data::GROUPS as $k => $v ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $g, $k, false ) . '>' . esc_html( $v ) . '</option>';
		}
		echo '</select></label></p>';
		echo '<p><label>Пометка к названию<br><input type="text" name="kzm_note" class="widefat" value="' . esc_attr( (string) get_post_meta( $post->ID, 'kzm_note', true ) ) . '" placeholder="по выходным с 12:00 до 16:00"></label></p>';
		echo '<p class="description">Порядок раздела на странице задаётся полем «Порядок» в блоке «Атрибуты».</p>';
	}

	public static function box_items( $post ) {
		wp_nonce_field( 'kzm_save', 'kzm_nonce' );
		$items = KZM_Data::items( $post->ID );
		foreach ( $items as &$it ) {
			$it['thumb'] = $it['image'] ? (string) wp_get_attachment_image_url( $it['image'], 'thumbnail' ) : '';
		}
		unset( $it );
		echo '<input type="hidden" name="kzm_items" value="' . esc_attr( wp_json_encode( $items, JSON_UNESCAPED_UNICODE ) ) . '" data-kzm-json>';
		echo '<div class="kzm-a" data-kzm-list></div>';
		echo '<p><button type="button" class="button button-primary" data-kzm-add>+ Добавить позицию</button> <span class="description">Цена только числом. Если у позиции несколько объёмов (300 мл, 1 л), добавьте строки «Объём и цена». Скрытые позиции на сайте не видны.</span></p>';
	}

	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST['kzm_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['kzm_nonce'] ), 'kzm_save' ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( isset( $_POST['kzm_items'] ) ) {
			$raw   = json_decode( wp_unslash( $_POST['kzm_items'] ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$items = is_array( $raw ) ? array_values( array_filter( array_map( array( 'KZM_Data', 'clean_item' ), $raw ) ) ) : array();
			update_post_meta( $post_id, 'kzm_items', wp_slash( wp_json_encode( $items, JSON_UNESCAPED_UNICODE ) ) );
		}
		if ( isset( $_POST['kzm_group'] ) ) {
			$g = sanitize_key( wp_unslash( $_POST['kzm_group'] ) );
			update_post_meta( $post_id, 'kzm_group', isset( KZM_Data::GROUPS[ $g ] ) ? $g : 'kitchen' );
		}
		if ( isset( $_POST['kzm_note'] ) ) {
			update_post_meta( $post_id, 'kzm_note', sanitize_text_field( wp_unslash( $_POST['kzm_note'] ) ) );
		}
	}
}
