<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Поля артиста: ключ => [подпись, тип, подсказка] */
function ka_fields() {
	return array(
		'ka_keywords' => array( 'Ключевые слова для поиска в афише', 'textarea', 'По одному в строке. Например: Востоков, БДО. Ищутся в названии и описании концерта.' ),
		'ka_exclude'  => array( 'Исключения', 'textarea', 'По одному в строке. Если слово есть в концерте, он НЕ попадёт к артисту. Например: Дениса Мельникова.' ),
		'ka_genre'    => array( 'Жанр', 'text', 'Например: биг-бэнд' ),
		'ka_tags'     => array( 'Теги (через запятую)', 'text', 'Для блока «Вам может понравиться». Из справочника: биг-бэнд, вокал, свинг, фанк, блюз...' ),
		'ka_founded'  => array( 'Год основания', 'text', '' ),
		'ka_city'     => array( 'Город', 'text', 'Москва' ),
		'ka_video_1'  => array( 'Видео 1 (главное)', 'url', 'Ссылка Rutube, VK Видео или YouTube' ),
		'ka_video_2'  => array( 'Видео 2', 'url', '' ),
		'ka_video_3'  => array( 'Видео 3', 'url', '' ),
		'ka_gallery'  => array( 'Фото с концертов (ссылки)', 'textarea', 'По одной ссылке на фото в строке. Лучше из медиатеки сайта.' ),
		'ka_credit'   => array( 'Подпись авторов фото', 'text', 'Например: Фото: Елена Белкина' ),
	);
}

function ka_register_post_type() {
	register_post_type( 'kozlov_artist', array(
		'labels' => array(
			'name'          => 'Артисты',
			'singular_name' => 'Артист',
			'add_new_item'  => 'Добавить артиста',
			'edit_item'     => 'Изменить артиста',
		),
		'public'        => true,
		'has_archive'   => 'artists',
		'rewrite'       => array( 'slug' => 'artists', 'with_front' => false ),
		'menu_icon'     => 'dashicons-microphone',
		'supports'      => array( 'title', 'editor', 'excerpt', 'thumbnail', 'revisions' ),
		'show_in_rest'  => true,
	) );
}
add_action( 'init', 'ka_register_post_type' );

add_action( 'add_meta_boxes', function () {
	add_meta_box( 'ka_fields', 'Данные артиста', 'ka_render_metabox', 'kozlov_artist', 'normal', 'high' );
} );

function ka_render_metabox( $post ) {
	wp_nonce_field( 'ka_save', 'ka_nonce' );
	echo '<p style="color:#666">Текст «Об оркестре» пишется в основном редакторе выше. Короткий лид (под заголовком) в поле «Отрывок». Главное фото: «Изображение записи» справа.</p>';
	foreach ( ka_fields() as $key => $f ) {
		$val = get_post_meta( $post->ID, $key, true );
		echo '<p><label style="display:block;font-weight:600;margin-bottom:4px" for="' . esc_attr( $key ) . '">' . esc_html( $f[0] ) . '</label>';
		if ( 'textarea' === $f[1] ) {
			echo '<textarea style="width:100%" rows="3" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '">' . esc_textarea( $val ) . '</textarea>';
		} else {
			echo '<input style="width:100%" type="' . ( 'url' === $f[1] ? 'url' : 'text' ) . '" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $val ) . '">';
		}
		if ( $f[2] ) { echo '<span style="color:#777;font-size:12px">' . esc_html( $f[2] ) . '</span>'; }
		echo '</p>';
	}
}

add_action( 'save_post_kozlov_artist', function ( $post_id ) {
	if ( ! isset( $_POST['ka_nonce'] ) || ! wp_verify_nonce( $_POST['ka_nonce'], 'ka_save' ) ) { return; }
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
	if ( ! current_user_can( 'edit_post', $post_id ) ) { return; }
	foreach ( ka_fields() as $key => $f ) {
		if ( ! isset( $_POST[ $key ] ) ) { continue; }
		$raw = wp_unslash( $_POST[ $key ] );
		$val = 'textarea' === $f[1] ? sanitize_textarea_field( $raw ) : ( 'url' === $f[1] ? esc_url_raw( $raw ) : sanitize_text_field( $raw ) );
		update_post_meta( $post_id, $key, $val );
	}
	delete_transient( 'ka_events_' . $post_id );
} );

/** Строки из многострочного поля */
function ka_lines( $post_id, $key ) {
	$v = (string) get_post_meta( $post_id, $key, true );
	return array_values( array_filter( array_map( 'trim', preg_split( '/\R/u', $v ) ), 'strlen' ) );
}
