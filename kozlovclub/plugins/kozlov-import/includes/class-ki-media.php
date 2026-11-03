<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Фото: сначала регистрируются как вложения без файла (kc_import_status = pending),
 * потом шаг «Фото» скачивает их со старого сайта или из Wayback и создаёт миниатюры.
 */
class KI_Media {

	/** Зарегистрировать файл по старому относительному пути (2026/09/x.png или files_mf/x.jpg). Возвращает ID вложения. */
	public static function register( $rel, $parent = 0, $alt = '' ) {
		$rel = ltrim( str_replace( '\\', '/', $rel ), '/' );
		if ( '' === $rel ) {
			return 0;
		}
		$map = get_option( 'ki_map_attachment', array() );
		if ( isset( $map[ $rel ] ) ) {
			return (int) $map[ $rel ];
		}
		$is_mf = 0 === strpos( $rel, 'files_mf/' );
		$src   = $is_mf ? KI_Importer::OLD_CONTENT . $rel : KI_Importer::OLD_UPLOADS . $rel;
		$local = $is_mf ? 'legacy/' . substr( $rel, 9 ) : $rel; // files_mf кладём в uploads/legacy/
		$ext   = strtolower( pathinfo( $rel, PATHINFO_EXTENSION ) );
		$mime  = array( 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp' )[ $ext ] ?? 'image/jpeg';
		$id = wp_insert_post( array(
			'post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => $mime, 'post_parent' => (int) $parent,
			'post_title' => sanitize_text_field( pathinfo( $rel, PATHINFO_FILENAME ) ), 'guid' => wp_upload_dir()['baseurl'] . '/' . $local,
		) );
		if ( ! $id || is_wp_error( $id ) ) {
			return 0;
		}
		update_post_meta( $id, '_wp_attached_file', $local );
		update_post_meta( $id, 'kc_import_src', $src );
		update_post_meta( $id, 'kc_import_status', 'pending' );
		if ( $alt ) {
			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
		}
		$map[ $rel ] = $id;
		update_option( 'ki_map_attachment', $map, false );
		return $id;
	}

	public static function count( $status ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key='kc_import_status' AND meta_value=%s", $status ) );
	}

	/** Скачать порцию. Будущие концерты первыми: сортировка по дате родителя. */
	public static function run( $limit, $retry ) {
		global $wpdb;
		$status = $retry ? 'failed' : 'pending';
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT pm.post_id FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} a ON a.ID = pm.post_id LEFT JOIN {$wpdb->postmeta} s ON s.post_id = a.post_parent AND s.meta_key='kc_start'
			 WHERE pm.meta_key='kc_import_status' AND pm.meta_value=%s ORDER BY (s.meta_value IS NULL), s.meta_value DESC LIMIT %d", $status, $limit ) );
		if ( ! $ids ) {
			return array( 'done' => true, 'status' => 'все фото обработаны', 'log' => 'Фото: очередь пуста.' );
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$ok = $fail = 0; $log = array();
		foreach ( $ids as $aid ) {
			$res = self::fetch( (int) $aid );
			if ( $res['ok'] ) {
				$ok++;
			} else {
				$fail++; $log[] = basename( get_post_meta( $aid, 'kc_import_src', true ) ) . ': ' . $res['error'];
			}
		}
		$left = self::count( 'pending' );
		return array( 'done' => 0 === $left, 'status' => "скачано +$ok, не найдено +$fail, в очереди $left", 'log' => "Фото: +$ok скачано, +$fail не найдено, осталось $left" . ( $log ? "\n  " . implode( "\n  ", array_slice( $log, 0, 6 ) ) : '' ), 'pending' => $left );
	}

	private static function fetch( $aid ) {
		$src   = get_post_meta( $aid, 'kc_import_src', true );
		$rel   = get_post_meta( $aid, '_wp_attached_file', true );
		$up    = wp_upload_dir();
		$path  = $up['basedir'] . '/' . $rel;
		if ( file_exists( $path ) && filesize( $path ) > 0 ) {
			return self::finish( $aid, $path );
		}
		wp_mkdir_p( dirname( $path ) );
		$candidates = array(
			$src,
			'https://web.archive.org/web/2026id_/' . $src,
			'https://web.archive.org/web/2025id_/' . $src,
		);
		$last = '';
		foreach ( $candidates as $i => $url ) {
			$r = wp_remote_get( $url, array( 'timeout' => $i ? 40 : 8, 'redirection' => 5, 'user-agent' => 'Mozilla/5.0 KozlovImport', 'headers' => array( 'Cookie' => 'beget=begetok' ) ) );
			if ( is_wp_error( $r ) ) {
				$last = $r->get_error_message(); continue;
			}
			$code = wp_remote_retrieve_response_code( $r );
			$body = wp_remote_retrieve_body( $r );
			$type = wp_remote_retrieve_header( $r, 'content-type' );
			if ( 200 !== $code || strlen( $body ) < 200 || false === strpos( (string) $type, 'image' ) ) {
				$last = "HTTP $code";
				if ( 429 === $code ) { sleep( 10 ); }
				continue;
			}
			file_put_contents( $path, $body );
			return self::finish( $aid, $path );
		}
		update_post_meta( $aid, 'kc_import_status', 'failed' );
		update_post_meta( $aid, 'kc_import_error', $last );
		return array( 'ok' => false, 'error' => $last ?: 'нет источника' );
	}

	private static function finish( $aid, $path ) {
		$meta = wp_generate_attachment_metadata( $aid, $path );
		if ( is_array( $meta ) && $meta ) {
			wp_update_attachment_metadata( $aid, $meta );
		}
		update_post_meta( $aid, 'kc_import_status', 'done' );
		delete_post_meta( $aid, 'kc_import_error' );
		return array( 'ok' => true );
	}
}
