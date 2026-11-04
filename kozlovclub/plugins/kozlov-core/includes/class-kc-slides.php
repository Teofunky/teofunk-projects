<?php
/**
 * Слайды на главной: баннеры без концерта (Новый год, абонементы, акции).
 * Заголовок, подпись, ссылка, картинка (миниатюра записи), показ до даты. Идут в слайдере перед концертами.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KC_Slides {

	const PT = 'kc_slide';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'add_meta_boxes_' . self::PT, array( __CLASS__, 'box' ) );
		add_action( 'save_post_' . self::PT, array( __CLASS__, 'save' ) );
		add_filter( 'manage_' . self::PT . '_posts_columns', array( __CLASS__, 'cols' ) );
		add_action( 'manage_' . self::PT . '_posts_custom_column', array( __CLASS__, 'col' ), 10, 2 );
	}

	public static function register() {
		register_post_type(
			self::PT,
			array(
				'labels'          => array( 'name' => 'Слайды на главной', 'singular_name' => 'Слайд', 'add_new' => 'Добавить слайд', 'add_new_item' => 'Новый слайд', 'edit_item' => 'Слайд', 'all_items' => 'Слайды на главной', 'not_found' => 'Слайдов нет' ),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'edit.php?post_type=' . KC_POST_TYPE,
				'supports'        => array( 'title', 'thumbnail', 'page-attributes' ),
				'capability_type' => 'post',
				'map_meta_cap'    => true,
			)
		);
	}

	public static function box() {
		add_meta_box( 'kc_slide', 'Слайд', array( __CLASS__, 'render_box' ), self::PT, 'normal', 'high' );
	}

	public static function render_box( $post ) {
		wp_nonce_field( 'kc_slide', 'kc_slide_nonce' );
		$v = fn( $k ) => esc_attr( (string) get_post_meta( $post->ID, $k, true ) );
		echo '<p><label>Подпись под заголовком<br><input type="text" class="widefat" name="kc_slide_sub" value="' . $v( 'kc_slide_sub' ) . '" placeholder="Праздничная программа 31 декабря"></label></p>';
		echo '<p><label>Ссылка<br><input type="text" class="widefat" name="kc_slide_link" value="' . $v( 'kc_slide_link' ) . '" placeholder="/novyj-god/"></label></p>';
		echo '<p><label>Показывать до (включительно)<br><input type="date" name="kc_slide_until" value="' . $v( 'kc_slide_until' ) . '"></label> <span class="description">Пусто: без срока.</span></p>';
		echo '<p class="description">Картинка: блок «Изображение записи» справа, лучше 600×800 (вертикальная, как у концертов). Порядок: поле «Порядок» в «Атрибутах». Слайды идут перед концертами, у которых включено «Показывать на главной».</p>';
	}

	public static function save( $post_id ) {
		if ( ! isset( $_POST['kc_slide_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['kc_slide_nonce'] ), 'kc_slide' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		update_post_meta( $post_id, 'kc_slide_sub', sanitize_text_field( wp_unslash( $_POST['kc_slide_sub'] ?? '' ) ) );
		update_post_meta( $post_id, 'kc_slide_link', esc_url_raw( wp_unslash( $_POST['kc_slide_link'] ?? '' ) ) );
		$until = sanitize_text_field( wp_unslash( $_POST['kc_slide_until'] ?? '' ) );
		update_post_meta( $post_id, 'kc_slide_until', preg_match( '/^\d{4}-\d{2}-\d{2}$/', $until ) ? $until : '' );
	}

	public static function cols( $c ) {
		$c['kc_slide_until'] = 'Показывать до';
		return $c;
	}

	public static function col( $col, $id ) {
		if ( 'kc_slide_until' === $col ) {
			$u = (string) get_post_meta( $id, 'kc_slide_until', true );
			echo $u ? esc_html( wp_date( 'j M Y', strtotime( $u ) ) ) . ( $u < wp_date( 'Y-m-d' ) ? ' <span style="color:#b32d2e">(закончился)</span>' : '' ) : '—';
		}
	}

	/** Активные слайды: id, title, sub, url, image. */
	public static function active() {
		$today = wp_date( 'Y-m-d' );
		$out   = array();
		foreach ( get_posts( array( 'post_type' => self::PT, 'post_status' => 'publish', 'numberposts' => 20, 'orderby' => array( 'menu_order' => 'ASC', 'date' => 'DESC' ) ) ) as $p ) {
			$until = (string) get_post_meta( $p->ID, 'kc_slide_until', true );
			if ( $until && $until < $today ) {
				continue;
			}
			$link = (string) get_post_meta( $p->ID, 'kc_slide_link', true );
			if ( $link && '/' === $link[0] ) {
				$link = home_url( $link );
			}
			$out[] = array( 'id' => $p->ID, 'title' => get_the_title( $p ), 'sub' => (string) get_post_meta( $p->ID, 'kc_slide_sub', true ), 'url' => $link, 'image' => (int) get_post_thumbnail_id( $p ) );
		}
		return $out;
	}
}
