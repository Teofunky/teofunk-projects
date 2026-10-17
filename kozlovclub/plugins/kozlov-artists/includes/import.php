<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Импорт фото из публичной папки Яндекс Диска в медиатеку (скачивает сервер сайта) */

add_action( 'add_meta_boxes', function () {
	add_meta_box( 'ka_import', 'Импорт фото с Яндекс Диска', function ( $post ) {
		wp_nonce_field( 'ka_import', 'ka_import_nonce' );
		echo '<p>Вставьте ссылку на публичную папку Яндекс Диска и нажмите «Обновить» или «Опубликовать». Все фото из папки (и вложенных папок) попадут в медиатеку и в поле «Фото с концертов». Главным фото станет первый вертикальный кадр, если главного ещё нет.</p>';
		echo '<input style="width:100%" type="url" name="ka_import_url" placeholder="https://disk.yandex.ru/d/...">';
		$note = get_transient( 'ka_import_note_' . $post->ID );
		if ( $note ) { echo '<p style="color:#0a6">' . esc_html( $note ) . '</p>'; delete_transient( 'ka_import_note_' . $post->ID ); }
	}, 'kozlov_artist', 'side' );
} );

add_action( 'save_post_kozlov_artist', function ( $post_id ) {
	if ( empty( $_POST['ka_import_url'] ) || ! isset( $_POST['ka_import_nonce'] ) || ! wp_verify_nonce( $_POST['ka_import_nonce'], 'ka_import' ) ) { return; }
	if ( ! current_user_can( 'upload_files' ) || ! current_user_can( 'edit_post', $post_id ) ) { return; }
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
	$url = esc_url_raw( wp_unslash( $_POST['ka_import_url'] ) );
	if ( ! preg_match( '~^https://(disk\.yandex\.(ru|com)|yadi\.sk)/~', $url ) ) { return; }
	set_transient( 'ka_import_note_' . $post_id, ka_import_yandex( $post_id, $url ), 300 );
}, 20 );

function ka_import_yandex( $post_id, $public_key ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	@set_time_limit( 300 ); // phpcs:ignore
	ignore_user_abort( true );

	// Ссылка вида https://disk.yandex.ru/d/KEY/Папка/Подпапка: ключ отдельно, путь отдельно
	$start_path = '';
	if ( preg_match( '~^(https://(?:disk\.yandex\.(?:ru|com)|yadi\.sk)/d/[^/?#]+)(/[^?#]+)?~', $public_key, $m ) ) {
		$public_key = $m[1];
		$start_path = isset( $m[2] ) ? rawurldecode( $m[2] ) : '';
	}
	$files = array();
	ka_yd_collect( $public_key, $start_path, $files, 0 );
	$done  = (array) get_post_meta( $post_id, 'ka_imported', true );
	$files = array_values( array_filter( $files, function ( $f ) use ( $done ) { return ! in_array( $f['path'], $done, true ); } ) );
	$total = count( $files );
	$files = array_slice( $files, 0, 2 ); // порция: сервер ограничивает время запроса
	$urls  = ka_lines( $post_id, 'ka_gallery' );
	$ok    = 0;
	$thumb = null;
	foreach ( $files as $f ) {
		$dl = ka_yd_json( 'https://cloud-api.yandex.net/v1/disk/public/resources/download?public_key=' . rawurlencode( $public_key ) . '&path=' . rawurlencode( $f['path'] ) );
		if ( empty( $dl['href'] ) ) { continue; }
		$tmp = download_url( $dl['href'], 120 );
		if ( is_wp_error( $tmp ) ) { continue; }
		$name = preg_replace( '~\.(jfif|jpeg)$~i', '.jpg', $f['name'] );
		$name = 'bdo-' . sanitize_file_name( remove_accents( $name ) );
		$id   = media_handle_sideload( array( 'name' => $name, 'tmp_name' => $tmp ), $post_id, get_the_title( $post_id ) );
		if ( is_wp_error( $id ) ) { @unlink( $tmp ); continue; } // phpcs:ignore
		update_post_meta( $id, '_wp_attachment_image_alt', get_the_title( $post_id ) );
		$urls[] = wp_get_attachment_url( $id );
		$done[] = $f['path'];
		// прогресс сохраняем после КАЖДОГО файла: запрос может оборваться по таймауту сервера
		update_post_meta( $post_id, 'ka_imported', array_values( array_filter( $done ) ) );
		update_post_meta( $post_id, 'ka_gallery', implode( "\n", array_unique( $urls ) ) );
		if ( ! has_post_thumbnail( $post_id ) ) {
			$mt = wp_get_attachment_metadata( $id );
			if ( ! empty( $mt['width'] ) && $mt['height'] > $mt['width'] ) { set_post_thumbnail( $post_id, $id ); }
		}
		$meta   = wp_get_attachment_metadata( $id );
		if ( ! $thumb && ! empty( $meta['width'] ) && $meta['height'] > $meta['width'] ) { $thumb = $id; }
		$ok++;
	}
	update_post_meta( $post_id, 'ka_imported', array_values( array_filter( $done ) ) );
	if ( $urls ) { update_post_meta( $post_id, 'ka_gallery', implode( "\n", array_unique( $urls ) ) ); }
	if ( $thumb && ! has_post_thumbnail( $post_id ) ) { set_post_thumbnail( $post_id, $thumb ); }
	return sprintf( 'Импорт: загружено %d. Осталось файлов: %d. Если осталось больше 0, нажмите «Обновить» ещё раз с той же ссылкой.', $ok, max( 0, $total - $ok ) );
}

function ka_yd_json( $url ) {
	$r = wp_remote_get( $url, array( 'timeout' => 20 ) );
	if ( is_wp_error( $r ) ) { return array(); }
	$j = json_decode( wp_remote_retrieve_body( $r ), true );
	return is_array( $j ) ? $j : array();
}

function ka_yd_collect( $public_key, $path, array &$files, $depth ) {
	$u = 'https://cloud-api.yandex.net/v1/disk/public/resources?limit=100&public_key=' . rawurlencode( $public_key ) . ( $path ? '&path=' . rawurlencode( $path ) : '' );
	$j = ka_yd_json( $u );
	foreach ( (array) ( $j['_embedded']['items'] ?? array() ) as $it ) {
		if ( 'dir' === $it['type'] && $depth < 2 ) {
			ka_yd_collect( $public_key, $it['path'], $files, $depth + 1 );
		} elseif ( 'file' === $it['type'] && 0 === strpos( (string) ( $it['mime_type'] ?? '' ), 'image/' ) ) {
			$files[] = array( 'path' => $it['path'], 'name' => $it['name'] );
		}
	}
}
