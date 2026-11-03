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
		$now = wp_date( 'Y-m-d 00:00:00' );
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT pm.post_id FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} a ON a.ID = pm.post_id LEFT JOIN {$wpdb->postmeta} s ON s.post_id = a.post_parent AND s.meta_key='kc_start'
			 WHERE pm.meta_key='kc_import_status' AND pm.meta_value=%s
			 ORDER BY (s.meta_value IS NULL), (s.meta_value < %s), CASE WHEN s.meta_value >= %s THEN s.meta_value END ASC, s.meta_value DESC LIMIT %d", $status, $now, $now, $limit ) );
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

	/** Ключ файла в data/cache: путь 2026/09/x.png -> 2026__09__x (без размера и расширения), в NFC. */
	private static function cache_key( $rel ) {
		$k = str_replace( '/', '__', $rel );
		$k = preg_replace( '/\.(jpe?g|png|webp|gif)$/i', '', $k );
		$k = preg_replace( '/-\d+x\d+$/', '', $k );
		return class_exists( 'Normalizer' ) ? Normalizer::normalize( $k, Normalizer::FORM_C ) : $k;
	}

	/** Фото из кэша Chrome (превью со старого сайта) для вложений, которые не нашлись. */
	public static function from_cache( $limit = 20 ) {
		global $wpdb;
		$dirs = array_merge( (array) glob( WP_PLUGIN_DIR . '/kozlov-import-cache-*/files', GLOB_ONLYDIR ), is_dir( KI_DIR . 'data/cache' ) ? array( KI_DIR . 'data/cache' ) : array() );
		if ( ! $dirs ) {
			return array( 'done' => true, 'status' => 'нет файлов кэша', 'log' => 'Кэш: установите плагины Kozlov Import Cache 1 и 2.' );
		}
		$index = get_transient( 'ki_cache_index' );
		if ( ! is_array( $index ) ) {
			$index = array();
			foreach ( $dirs as $dir ) {
				foreach ( (array) glob( $dir . '/*' ) as $f ) {
					$k = preg_replace( '/\.(jpe?g|png|webp)$/i', '', basename( $f ) );
					$index[ class_exists( 'Normalizer' ) ? Normalizer::normalize( $k, Normalizer::FORM_C ) : $k ] = $f;
				}
			}
			set_transient( 'ki_cache_index', $index, HOUR_IN_SECONDS );
		}
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT pm.post_id FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->postmeta} t ON t.post_id = pm.post_id AND t.meta_key = 'kc_cache_tried'
			 WHERE pm.meta_key = 'kc_import_status' AND pm.meta_value IN ('failed','pending') AND t.meta_id IS NULL LIMIT %d", $limit ) );
		if ( ! $ids ) {
			return array( 'done' => true, 'status' => 'кэш обработан', 'log' => 'Кэш: очередь пуста.' );
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$up = wp_upload_dir(); $ok = 0; $miss = 0;
		foreach ( $ids as $aid ) {
			update_post_meta( $aid, 'kc_cache_tried', 1 );
			$rel = (string) get_post_meta( $aid, '_wp_attached_file', true );
			$key = self::cache_key( $rel );
			if ( empty( $index[ $key ] ) ) { $miss++; continue; }
			$ext = strtolower( pathinfo( $index[ $key ], PATHINFO_EXTENSION ) );
			$new = preg_replace( '/\.\w+$/', '.' . $ext, $rel );
			$path = $up['basedir'] . '/' . $new;
			wp_mkdir_p( dirname( $path ) );
			if ( ! copy( $index[ $key ], $path ) ) { $miss++; continue; }
			if ( $new !== $rel ) {
				update_post_meta( $aid, '_wp_attached_file', $new );
				wp_update_post( array( 'ID' => $aid, 'post_mime_type' => array( 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' )[ $ext ] ?? 'image/jpeg', 'guid' => $up['baseurl'] . '/' . $new ) );
			}
			update_post_meta( $aid, 'kc_lowres', 1 );
			self::finish( $aid, $path );
			$ok++;
		}
		$left = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->postmeta} t ON t.post_id = pm.post_id AND t.meta_key = 'kc_cache_tried' WHERE pm.meta_key = 'kc_import_status' AND pm.meta_value IN ('failed','pending') AND t.meta_id IS NULL" );
		return array( 'done' => 0 === $left, 'status' => "из кэша +$ok, нет в кэше +$miss, в очереди $left", 'log' => "Кэш Chrome: +$ok восстановлено, +$miss без совпадения, осталось проверить $left", 'pending' => self::count( 'pending' ) );
	}

	private static function thumb_ok( $aid ) {
		if ( ! $aid ) { return false; }
		$f = get_attached_file( $aid );
		return $f && file_exists( $f ) && filesize( $f ) > 0;
	}

	/** Обложки Ticketscloud для концертов без картинки: ссылки собраны через браузер (data/tc_covers.json). */
	public static function from_tickets( $limit = 4 ) {
		$map = json_decode( (string) file_get_contents( KI_DIR . 'data/tc_covers.json' ), true );
		if ( ! is_array( $map ) ) {
			return array( 'done' => true, 'status' => 'нет списка обложек', 'log' => 'Ticketscloud: data/tc_covers.json не найден.' );
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		$q = get_posts( array( 'post_type' => 'concert', 'post_status' => array( 'publish', 'future', 'draft' ), 'numberposts' => -1, 'fields' => 'ids', 'meta_query' => array( array( 'key' => 'kc_tc_event', 'value' => array_keys( $map ), 'compare' => 'IN' ), array( 'key' => 'kc_tc_cover', 'compare' => 'NOT EXISTS' ) ) ) );
		$ok = $fail = $skip = 0; $log = array(); $cache = get_option( 'ki_tc_att', array() ); $n = 0;
		foreach ( $q as $cid ) {
			if ( $n >= $limit ) { break; }
			$thumb = (int) get_post_thumbnail_id( $cid );
			if ( self::thumb_ok( $thumb ) && ! get_post_meta( $thumb, 'kc_lowres', true ) ) {
				update_post_meta( $cid, 'kc_tc_cover', 'skip' ); $skip++; continue;
			}
			$path = $map[ get_post_meta( $cid, 'kc_tc_event', true ) ] ?? '';
			if ( ! $path ) { update_post_meta( $cid, 'kc_tc_cover', 'none' ); continue; }
			$n++;
			if ( empty( $cache[ $path ] ) || ! self::thumb_ok( $cache[ $path ] ) ) {
				$tmp = download_url( 'https://ticketscloud.com/s3/production/' . $path, 30 );
				if ( is_wp_error( $tmp ) ) {
					$fail++; $log[] = $cid . ': ' . $tmp->get_error_message(); update_post_meta( $cid, 'kc_tc_cover', 'fail' ); continue;
				}
				$aid = media_handle_sideload( array( 'name' => 'tc-' . basename( $path ), 'tmp_name' => $tmp ), 0, get_the_title( $cid ) );
				if ( is_wp_error( $aid ) ) {
					@unlink( $tmp ); $fail++; $log[] = $cid . ': ' . $aid->get_error_message(); update_post_meta( $cid, 'kc_tc_cover', 'fail' ); continue;
				}
				$cache[ $path ] = (int) $aid;
				update_option( 'ki_tc_att', $cache, false );
			}
			set_post_thumbnail( $cid, $cache[ $path ] );
			if ( ! self::thumb_ok( (int) get_post_meta( $cid, 'kc_hero', true ) ) ) {
				update_post_meta( $cid, 'kc_hero', $cache[ $path ] );
			}
			update_post_meta( $cid, 'kc_tc_cover', $path );
			$ok++;
		}
		$left = count( $q ) - $ok - $fail - $skip;
		return array( 'done' => $n < $limit, 'status' => "обложек +$ok, ошибок +$fail", 'log' => "Ticketscloud: +$ok обложек, +$fail ошибок, пропущено $skip (картинка уже есть)" . ( $log ? "\n  " . implode( "\n  ", array_slice( $log, 0, 5 ) ) : '' ) );
	}

	/** Заглушка для концертов, у которых так и нет рабочей картинки. */
	public static function placeholder() {
		$aid = (int) get_option( 'ki_placeholder', 0 );
		if ( ! self::thumb_ok( $aid ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$up = wp_upload_dir(); wp_mkdir_p( $up['basedir'] . '/kozlov' );
			$path = $up['basedir'] . '/kozlov/placeholder.jpg';
			$im = imagecreatetruecolor( 800, 600 );
			for ( $y = 0; $y < 600; $y++ ) { $c = imagecolorallocate( $im, 26 + (int) ( $y / 40 ), 24 + (int) ( $y / 40 ), 28 + (int) ( $y / 40 ) ); imageline( $im, 0, $y, 800, $y, $c ); }
			$logo = get_template_directory() . '/assets/images/logo-kozlovclub.png';
			if ( file_exists( $logo ) && ( $lg = @imagecreatefrompng( $logo ) ) ) {
				$w = imagesx( $lg ); $h = imagesy( $lg ); $tw = 420; $th = (int) ( $h * $tw / $w );
				imagealphablending( $im, true );
				imagecopyresampled( $im, $lg, (int) ( ( 800 - $tw ) / 2 ), (int) ( ( 600 - $th ) / 2 ), 0, 0, $tw, $th, $w, $h );
			}
			imagejpeg( $im, $path, 88 );
			$aid = wp_insert_attachment( array( 'post_mime_type' => 'image/jpeg', 'post_title' => 'Клуб Алексея Козлова', 'post_status' => 'inherit', 'guid' => $up['baseurl'] . '/kozlov/placeholder.jpg' ), $path );
			update_post_meta( $aid, '_wp_attached_file', 'kozlov/placeholder.jpg' );
			wp_update_attachment_metadata( $aid, wp_generate_attachment_metadata( $aid, $path ) );
			update_option( 'ki_placeholder', (int) $aid, false );
		}
		$ids = get_posts( array( 'post_type' => 'concert', 'post_status' => array( 'publish', 'future' ), 'numberposts' => -1, 'fields' => 'ids' ) );
		$n = 0;
		foreach ( $ids as $cid ) {
			$t = (int) get_post_thumbnail_id( $cid );
			if ( ! self::thumb_ok( $t ) ) { set_post_thumbnail( $cid, $aid ); update_post_meta( $cid, 'kc_placeholder', 1 ); $n++; }
		}
		return array( 'done' => true, 'status' => "заглушка у $n концертов", 'log' => "Заглушка: поставлена $n концертам без картинки (метка kc_placeholder)." );
	}
}
