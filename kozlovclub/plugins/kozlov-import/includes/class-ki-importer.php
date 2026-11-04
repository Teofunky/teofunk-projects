<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KI_Importer {

	const OLD_UPLOADS = 'https://kozlovclub.ru/wp-content/uploads/';
	const OLD_CONTENT = 'https://kozlovclub.ru/wp-content/';

	public static function load( $name ) {
		$file = KI_DIR . 'data/' . $name . '.json.gz';
		if ( ! file_exists( $file ) ) {
			throw new RuntimeException( 'Нет файла данных ' . $name );
		}
		$json = gzdecode( file_get_contents( $file ) );
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			throw new RuntimeException( 'Повреждён файл данных ' . $name );
		}
		return $data;
	}

	/** Карта старый ID => новый ID для типа (user|page|concert|attachment). */
	public static function map( $type ) {
		return get_option( 'ki_map_' . $type, array() );
	}
	public static function map_set( $type, $old, $new ) {
		$m = self::map( $type );
		$m[ (string) $old ] = (int) $new;
		update_option( 'ki_map_' . $type, $m, false );
	}
	public static function map_get( $type, $old ) {
		$m = self::map( $type );
		return isset( $m[ (string) $old ] ) ? (int) $m[ (string) $old ] : 0;
	}

	/* ---------- 1. пользователи ---------- */
	public static function users() {
		global $wpdb;
		$m = ki_meta();
		$created = $updated = 0;
		foreach ( $m['users'] as $u ) {
			if ( 'rest_manager' === $u['role'] || 'subscriber' === $u['role'] ) {
				$u['role'] = 'subscriber';
			}
			$existing = get_user_by( 'login', $u['login'] );
			if ( ! $existing && $u['email'] ) {
				$existing = get_user_by( 'email', $u['email'] );
			}
			if ( $existing ) {
				$uid = $existing->ID;
				$updated++;
			} else {
				$uid = wp_insert_user( array(
					'user_login' => $u['login'], 'user_pass' => wp_generate_password( 24 ), 'user_email' => $u['email'] ?: '', 'user_nicename' => $u['nicename'],
					'display_name' => $u['display_name'], 'user_url' => $u['url'], 'user_registered' => $u['registered'], 'role' => $u['role'],
				) );
				if ( is_wp_error( $uid ) ) {
					continue;
				}
				// старый хэш пароля: редакторы входят со своими паролями
				if ( $u['pass'] ) {
					$wpdb->update( $wpdb->users, array( 'user_pass' => $u['pass'] ), array( 'ID' => $uid ) );
					clean_user_cache( $uid );
				}
				$created++;
			}
			update_user_meta( $uid, 'kc_legacy_user_id', (int) $u['id'] );
			self::map_set( 'user', $u['id'], $uid );
		}
		return array( 'done' => true, 'status' => "создано $created, уже были $updated", 'log' => "Пользователи: создано $created, уже были $updated." );
	}

	private static function author( $old_id ) {
		return self::map_get( 'user', $old_id ) ?: get_current_user_id();
	}

	/* ---------- 2. страницы ---------- */
	public static function pages() {
		$m = ki_meta();
		$n = 0;
		foreach ( $m['pages'] as $p ) {
			if ( ! $p['slug'] ) {
				continue;
			}
			$id = self::map_get( 'page', $p['id'] );
			$args = array(
				'ID' => $id ?: 0, 'post_type' => 'page', 'post_title' => $p['title'], 'post_name' => $p['slug'], 'post_content' => $p['content'], 'post_excerpt' => $p['excerpt'],
				'post_status' => $p['status'], 'post_date' => $p['date'], 'post_modified' => $p['modified'], 'menu_order' => (int) $p['order'], 'post_author' => self::author( $p['author'] ),
			);
			$id = $id ? wp_update_post( $args, true ) : wp_insert_post( $args, true );
			if ( is_wp_error( $id ) ) {
				continue;
			}
			update_post_meta( $id, 'kc_legacy_id', (int) $p['id'] );
			if ( $p['template'] ) {
				update_post_meta( $id, '_wp_page_template', $p['template'] );
			}
			self::yoast( $id, $p );
			if ( $p['thumb'] ) {
				set_post_thumbnail( $id, KI_Media::register( $p['thumb'], $id ) );
			}
			self::map_set( 'page', $p['id'], $id );
			$n++;
		}
		// родители вторым проходом
		foreach ( $m['pages'] as $p ) {
			if ( $p['parent'] && ( $id = self::map_get( 'page', $p['id'] ) ) && ( $pid = self::map_get( 'page', $p['parent'] ) ) ) {
				wp_update_post( array( 'ID' => $id, 'post_parent' => $pid ) );
			}
		}
		return array( 'done' => true, 'status' => "перенесено $n", 'log' => "Страницы: $n." );
	}

	private static function yoast( $id, $p ) {
		if ( ! empty( $p['yoast_title'] ) ) {
			update_post_meta( $id, '_yoast_wpseo_title', $p['yoast_title'] );
		}
		if ( ! empty( $p['yoast_desc'] ) ) {
			update_post_meta( $id, '_yoast_wpseo_metadesc', $p['yoast_desc'] );
		}
	}

	/* ---------- 3. меню ---------- */
	public static function menu() {
		$m = ki_meta();
		$menu = wp_get_nav_menu_object( 'main-menu' );
		$menu_id = $menu ? $menu->term_id : wp_create_nav_menu( 'main-menu' );
		if ( is_wp_error( $menu_id ) ) {
			throw new RuntimeException( 'Не удалось создать меню' );
		}
		foreach ( wp_get_nav_menu_items( $menu_id ) ?: array() as $old ) {
			wp_delete_post( $old->ID, true );
		}
		$ids = array(); $n = 0;
		$items = $m['menu'];
		usort( $items, fn( $a, $b ) => $a['order'] <=> $b['order'] );
		foreach ( $items as $it ) {
			$args = array( 'menu-item-title' => wp_strip_all_tags( $it['title'] ), 'menu-item-status' => 'publish', 'menu-item-position' => (int) $it['order'], 'menu-item-parent-id' => $ids[ $it['parent'] ] ?? 0 );
			$type = $it['type'];
			if ( 'post_type' === $type && 'page' === $it['object'] && ( $pid = self::map_get( 'page', $it['object_id'] ) ) ) {
				$args += array( 'menu-item-type' => 'post_type', 'menu-item-object' => 'page', 'menu-item-object-id' => $pid );
			} else {
				$url = $it['url'] ?: '#';
				// старые фильтры главной ведут на новые адреса
				if ( preg_match( '#[?&]sort=(main_scene|mansard|the_beat|myasnitskaya)#', $url, $mm ) ) {
					$map = array( 'main_scene' => 'main-scene', 'mansard' => 'mansarda', 'the_beat' => 'the-beat', 'myasnitskaya' => 'myasnitskaya' );
					$url = home_url( '/stage/' . $map[ $mm[1] ] . '/' );
				} elseif ( preg_match( '#[?&]genre=([^&]+)#', $url, $mm ) ) {
					$url = home_url( '/genre/' . sanitize_title( urldecode( $mm[1] ) ) . '/' );
				} elseif ( 0 === strpos( $url, 'https://kozlovclub.ru' ) ) {
					$url = home_url( substr( $url, strlen( 'https://kozlovclub.ru' ) ) );
				}
				$args += array( 'menu-item-type' => 'custom', 'menu-item-url' => $url );
			}
			$new = wp_update_nav_menu_item( $menu_id, 0, $args );
			if ( ! is_wp_error( $new ) ) {
				$ids[ $it['id'] ] = $new; $n++;
			}
		}
		$locations = get_theme_mod( 'nav_menu_locations', array() );
		$locations['main-menu'] = $menu_id;
		set_theme_mod( 'nav_menu_locations', $locations );
		return array( 'done' => true, 'status' => "пунктов $n", 'log' => "Меню main-menu: $n пунктов, назначено в область main-menu." );
	}

	/* ---------- 4. концерты, порциями ---------- */
	public static function concerts( $offset ) {
		$m = ki_meta();
		$chunks = (int) $m['concert_chunks'];
		$total  = (int) $m['concert_total'];
		$chunk_i = intdiv( $offset, 150 );
		if ( $chunk_i >= $chunks ) {
			return array( 'done' => true, 'offset' => $offset, 'total' => $total, 'status' => "перенесено $total", 'log' => 'Концерты: готово.' );
		}
		$list  = self::load( sprintf( 'concerts-%03d', $chunk_i ) );
		$start = $offset - $chunk_i * 150;
		$batch = array_slice( $list, $start, 30 );
		$t0 = microtime( true ); $n = 0;
		foreach ( $batch as $c ) {
			self::concert( $c ); $n++;
			if ( microtime( true ) - $t0 > 20 ) {
				break;
			}
		}
		$offset += $n;
		$done = $offset >= $total;
		return array( 'done' => $done, 'offset' => $offset, 'total' => $total, 'status' => "перенесено $offset из $total", 'log' => "Концерты: $offset из $total" . ( $done ? ', готово.' : '' ) );
	}

	private static function concert( $c ) {
		$id = self::map_get( 'concert', $c['id'] );
		$args = array(
			'ID' => $id ?: 0, 'post_type' => KC_POST_TYPE, 'post_title' => $c['title'], 'post_name' => $c['slug'], 'post_content' => $c['content'], 'post_excerpt' => $c['excerpt'],
			'post_status' => 'draft' === $c['status'] ? 'draft' : 'publish', 'post_date' => $c['date'], 'post_modified' => $c['modified'], 'post_author' => self::author( $c['author'] ),
		);
		$id = $id ? wp_update_post( $args, true ) : wp_insert_post( $args, true );
		if ( is_wp_error( $id ) ) {
			return;
		}
		$meta = array(
			'kc_start' => $c['start'], 'kc_end' => $c['end'], 'kc_price_type' => $c['price_type'], 'kc_price' => (int) $c['price'],
			'kc_price_text' => self::custom_price_text( $c ), 'kc_tc_event' => $c['tc_event'], 'kc_program' => $c['program'],
			'kc_booking' => $c['booking'] ? 1 : 0, 'kc_register_btn' => $c['register'] ? 1 : 0, 'kc_sold_out' => 0, 'kc_cancelled' => 0,
			'kc_slider_show' => ! empty( $c['slider']['show'] ) ? 1 : 0, 'kc_slider_title' => $c['slider']['title'] ?? '',
			'kc_videos' => array_values( array_unique( $c['videos'] ) ), 'kc_text_1' => $c['text1'], 'kc_text_2' => $c['text2'], 'kc_quote' => $c['quote'], 'kc_quote_author' => $c['quote_author'],
			'kc_additional' => $c['additional'], 'kc_partners_title' => $c['partners_title'], 'kc_ext_links' => $c['ext_links'], 'kc_legacy_id' => (int) $c['id'],
		);
		if ( $c['lead'] ) { $meta['kep_lead'] = $c['lead']; }
		if ( $c['notes'] ) { $meta['kep_notes'] = $c['notes']; }
		// фото: анонс (миниатюра), большое, слайдер, артисты, партнёры
		if ( $c['thumb'] ) {
			set_post_thumbnail( $id, KI_Media::register( $c['thumb'], $id, $c['thumb_alt'] ) );
		} elseif ( $c['anons_mf'] ) {
			set_post_thumbnail( $id, KI_Media::register( $c['anons_mf'], $id, $c['thumb_alt'] ) );
		}
		$meta['kc_hero'] = $c['hero'] ? KI_Media::register( $c['hero'], $id ) : 0;
		$meta['kc_slider_img'] = ! empty( $c['slider']['image'] ) ? KI_Media::register( $c['slider']['image'], $id ) : 0;
		$artists = array();
		foreach ( $c['artists'] as $a ) {
			$artists[] = array( 'image' => $a['image'] ? KI_Media::register( $a['image'], $id, $a['alt'] ) : 0, 'name' => '', 'text' => $a['text'] );
		}
		$meta['kc_artists'] = $artists;
		$partners = array();
		foreach ( $c['partners'] as $p ) {
			$partners[] = array( 'image' => $p['image'] ? KI_Media::register( $p['image'], $id ) : 0, 'link' => $p['link'], 'alt' => $p['alt'] );
		}
		$meta['kc_partners'] = $partners;
		foreach ( $meta as $k => $v ) {
			update_post_meta( $id, $k, $v );
		}
		self::yoast( $id, $c );
		// сцена, жанры, теги
		$stage_term = $c['stage'] ? get_term_by( 'slug', $c['stage'], KC_TAX_STAGE ) : null;
		wp_set_post_terms( $id, $stage_term ? array( (int) $stage_term->term_id ) : array(), KC_TAX_STAGE, false );
		$genre_ids = array();
		foreach ( $c['genres'] as $g ) {
			$t = term_exists( $g['slug'], KC_TAX_GENRE );
			if ( ! $t ) {
				$t = wp_insert_term( $g['name'], KC_TAX_GENRE, array( 'slug' => $g['slug'] ) );
			}
			if ( ! is_wp_error( $t ) ) {
				$genre_ids[] = (int) ( is_array( $t ) ? $t['term_id'] : $t );
			}
		}
		if ( 'free' === $c['price_type'] && ( $t = term_exists( 'free', KC_TAX_GENRE ) ) ) {
			$genre_ids[] = (int) $t['term_id'];
		}
		wp_set_post_terms( $id, array_unique( $genre_ids ), KC_TAX_GENRE, false );
		if ( $c['tags'] ) {
			wp_set_post_terms( $id, array_map( 'wp_strip_all_tags', $c['tags'] ), KC_TAX_TAG, false );
		}
		self::map_set( 'concert', $c['id'], $id );
	}

	/* ---------- 4б. концерты с боевого сайта (исходные поля из админки kozlovclub.ru, 04.11.2026) ---------- */

	/**
	 * Новые концерты создаются целиком. У существующих меняется поле, только если у нас его никто не правил
	 * (сейчас равно значению из дампа или из прошлого запуска этого шага). Если поле уже новое, оно пропускается.
	 */
	public static function live_sync( $offset ) {
		$ops   = self::load( 'live-2026-11-04' );
		$total = count( $ops );
		$batch = array_slice( $ops, (int) $offset, 15 );
		$st    = get_option( 'ki_live_stats', array() );
		if ( 0 === (int) $offset || ! $st ) {
			$st = array( 'new' => 0, 'upd' => 0, 'set' => 0, 'kept' => 0, 'same' => 0, 'missing' => 0 );
		}
		foreach ( $batch as $op ) {
			if ( 'new' === $op['op'] ) {
				$pid = self::map_get( 'concert', $op['c']['id'] );
				if ( ! $pid || ! get_post( $pid ) ) { self::concert( $op['c'] ); $st['new']++; continue; }
				if ( empty( $op['was'] ) ) { continue; }
				$set = array();
				foreach ( $op['was'] as $f => $v ) { $set[ $f ] = $op['c'][ $f ]; }
				$r = self::patch( $pid, $set, $op['was'] );
				$st['upd']++;
			} else {
				$pid = self::map_get( 'concert', $op['id'] );
				if ( ! $pid || ! get_post( $pid ) ) { $st['missing']++; continue; }
				$r = self::patch( $pid, $op['set'], $op['was'], $op['was2'] ?? array() );
			}
			$st['set'] += $r[0]; $st['kept'] += $r[1]; $st['same'] += $r[2];
		}
		update_option( 'ki_live_stats', $st, false );
		$offset += count( $batch );
		$done = $offset >= $total;
		$msg = "новых {$st['new']}, обновлено полей {$st['set']}, уже совпадало {$st['same']}, оставлено наших правок {$st['kept']}";
		return array( 'done' => $done, 'offset' => $offset, 'total' => $total, 'status' => ( $done ? 'готово: ' : "$offset из $total: " ) . $msg,
			'log' => "Концерты с сайта: $offset из $total, $msg" . ( $st['missing'] ? ", не найдено {$st['missing']}" : '' ) . ( $done ? '. Дальше шаг 5 «Фото».' : '' ) );
	}

	private static function norm( $v ) {
		if ( is_bool( $v ) ) { return $v ? '1' : '0'; }
		if ( is_array( $v ) ) { return wp_json_encode( array_map( array( __CLASS__, 'norm' ), $v ) ); }
		$v = (string) $v;
		if ( false !== strpos( $v, '<' ) ) {
			$v = wp_kses_post( $v ); // так значение сохраняет Kozlov Core
		}
		return trim( preg_replace( '/\s+/u', ' ', str_replace( array( "\r", "\xc2\xa0" ), array( '', ' ' ), html_entity_decode( $v, ENT_QUOTES, 'UTF-8' ) ) ) );
	}

	/** Сравнимое значение поля: что лежит у нас сейчас и во что превращается значение из пакета. */
	private static function cur( $pid, $f ) {
		$meta = array( 'program' => 'kc_program', 'start' => 'kc_start', 'end' => 'kc_end', 'tc_event' => 'kc_tc_event', 'text1' => 'kc_text_1', 'text2' => 'kc_text_2',
			'quote' => 'kc_quote', 'quote_author' => 'kc_quote_author', 'additional' => 'kc_additional', 'lead' => 'kep_lead', 'notes' => 'kep_notes', 'yoast_desc' => '_yoast_wpseo_metadesc',
			'booking' => 'kc_booking', 'register' => 'kc_register_btn' );
		if ( 'title' === $f ) { return self::norm( get_post_field( 'post_title', $pid ) ); }
		if ( 'booking' === $f || 'register' === $f ) { return get_post_meta( $pid, $meta[ $f ], true ) ? '1' : '0'; }
		if ( isset( $meta[ $f ] ) ) { return self::norm( get_post_meta( $pid, $meta[ $f ], true ) ); }
		if ( 'price' === $f ) { return (string) (int) get_post_meta( $pid, 'kc_price', true ); }
		if ( 'price_type' === $f ) { return self::norm( get_post_meta( $pid, 'kc_price_type', true ) ); }
		if ( 'price_text' === $f ) { return self::norm( get_post_meta( $pid, 'kc_price_text', true ) ); }
		if ( 'videos' === $f ) { return self::norm( array_values( (array) get_post_meta( $pid, 'kc_videos', true ) ) ); }
		if ( 'hero' === $f ) { return (string) (int) get_post_meta( $pid, 'kc_hero', true ); }
		if ( 'thumb' === $f ) { return (string) (int) get_post_thumbnail_id( $pid ); }
		if ( 'stage' === $f ) { $t = wp_get_post_terms( $pid, KC_TAX_STAGE, array( 'fields' => 'slugs' ) ); return is_array( $t ) && $t ? $t[0] : ''; }
		if ( 'artists' === $f ) {
			$out = array();
			foreach ( (array) get_post_meta( $pid, 'kc_artists', true ) as $a ) { $out[] = array( (int) ( $a['image'] ?? 0 ), self::norm( $a['text'] ?? '' ) ); }
			return wp_json_encode( $out );
		}
		return null;
	}

	private static function pack_val( $f, $v ) {
		$map = get_option( 'ki_map_attachment', array() );
		if ( in_array( $f, array( 'hero', 'thumb' ), true ) ) { $v = ltrim( (string) $v, '/' ); return (string) ( $v && isset( $map[ $v ] ) ? (int) $map[ $v ] : 0 ); }
		if ( 'price' === $f ) { return (string) (int) $v; }
		if ( 'price_text' === $f ) { return null; }
		if ( 'videos' === $f ) { return self::norm( array_values( array_unique( (array) $v ) ) ); }
		if ( 'artists' === $f ) {
			$out = array();
			foreach ( (array) $v as $a ) { $img = ltrim( (string) ( $a['image'] ?? '' ), '/' ); $out[] = array( $img && isset( $map[ $img ] ) ? (int) $map[ $img ] : 0, self::norm( $a['text'] ?? '' ) ); }
			return wp_json_encode( $out );
		}
		if ( 'booking' === $f || 'register' === $f ) { return $v ? '1' : '0'; }
		return self::norm( $v );
	}

	private static function put( $pid, $f, $v, $set ) {
		$meta = array( 'program' => 'kc_program', 'start' => 'kc_start', 'end' => 'kc_end', 'tc_event' => 'kc_tc_event', 'text1' => 'kc_text_1', 'text2' => 'kc_text_2',
			'quote' => 'kc_quote', 'quote_author' => 'kc_quote_author', 'additional' => 'kc_additional', 'lead' => 'kep_lead', 'notes' => 'kep_notes', 'yoast_desc' => '_yoast_wpseo_metadesc' );
		if ( 'title' === $f ) { wp_update_post( array( 'ID' => $pid, 'post_title' => $v ) ); return; }
		if ( isset( $meta[ $f ] ) ) { update_post_meta( $pid, $meta[ $f ], $v ); return; }
		if ( 'booking' === $f ) { update_post_meta( $pid, 'kc_booking', $v ? 1 : 0 ); return; }
		if ( 'register' === $f ) { update_post_meta( $pid, 'kc_register_btn', $v ? 1 : 0 ); return; }
		if ( 'price_type' === $f ) {
			update_post_meta( $pid, 'kc_price_type', $set['price_type'] );
			update_post_meta( $pid, 'kc_price', (int) $set['price'] );
			update_post_meta( $pid, 'kc_price_text', self::custom_price_text( $set ) );
			return;
		}
		if ( 'videos' === $f ) { update_post_meta( $pid, 'kc_videos', array_values( array_unique( (array) $v ) ) ); return; }
		if ( 'hero' === $f ) { update_post_meta( $pid, 'kc_hero', $v ? KI_Media::register( $v, $pid ) : 0 ); return; }
		if ( 'thumb' === $f ) { if ( $v ) { set_post_thumbnail( $pid, KI_Media::register( $v, $pid, get_the_title( $pid ) ) ); delete_post_meta( $pid, 'kc_placeholder' ); } return; }
		if ( 'stage' === $f ) { $t = $v ? get_term_by( 'slug', $v, KC_TAX_STAGE ) : null; wp_set_post_terms( $pid, $t ? array( (int) $t->term_id ) : array(), KC_TAX_STAGE, false ); return; }
		if ( 'artists' === $f ) {
			$out = array();
			foreach ( (array) $v as $a ) { $out[] = array( 'image' => $a['image'] ? KI_Media::register( $a['image'], $pid, $a['alt'] ?? '' ) : 0, 'name' => '', 'text' => $a['text'] ); }
			update_post_meta( $pid, 'kc_artists', $out );
		}
	}

	/** @return array{0:int,1:int,2:int} обновлено, оставлено наших правок, уже совпадало */
	private static function patch( $pid, $set, $was, $was2 = array() ) {
		$ok = 0; $kept = 0; $same = 0;
		$ph = (string) (int) get_option( 'ki_placeholder', 0 );
		foreach ( $set as $f => $v ) {
			if ( in_array( $f, array( 'price', 'price_text' ), true ) ) { continue; } // меняются вместе с price_type
			if ( 'price_type' === $f ) {
				$now  = self::cur( $pid, 'price_type' ) . '|' . self::cur( $pid, 'price' );
				$new  = self::pack_val( 'price_type', $set['price_type'] ) . '|' . self::pack_val( 'price', $set['price'] ?? 0 );
				$old  = self::pack_val( 'price_type', $was['price_type'] ?? '' ) . '|' . self::pack_val( 'price', $was['price'] ?? 0 );
				$text = self::cur( $pid, 'price_text' ) === self::norm( self::custom_price_text( $set ) );
				if ( $now === $new && $text ) { $same++; continue; }
				if ( $now === $old || $now === $new ) { self::put( $pid, 'price_type', null, $set + array( 'price' => 0, 'price_text' => '' ) ); $ok++; } else { $kept++; }
				continue;
			}
			$now = self::cur( $pid, $f );
			if ( null === $now ) { continue; }
			$new = self::pack_val( $f, $v );
			$old = self::pack_val( $f, $was[ $f ] ?? '' );
			if ( $now === $new && ( '0' !== $new || ! in_array( $f, array( 'hero', 'thumb' ), true ) ) ) { $same++; continue; }
			$old2 = array_key_exists( $f, $was2 ) ? self::pack_val( $f, $was2[ $f ] ) : null;
			$free = ( $now === $old ) || ( null !== $old2 && $now === $old2 ) || ( in_array( $f, array( 'hero', 'thumb' ), true ) && ( '0' === $now || $now === $ph ) ) || ( '' === $now && '' !== $new );
			if ( $free ) { self::put( $pid, $f, $v, $set ); $ok++; } else { $kept++; }
		}
		return array( $ok, $kept, $same );
	}

	/** Текст цены сохраняем, только если он нестандартный; стандартный Core соберёт сам. */
	private static function custom_price_text( $c ) {
		$t = trim( $c['price_text'] );
		if ( '' === $t ) {
			return '';
		}
		$std = array( 'ticket' => $c['price'] ? 'от ' . $c['price'] . ' руб.' : '', 'deposit' => 'депозит ' . number_format( (int) $c['price'], 0, '', ' ' ) . ' руб.', 'free' => 'вход свободный' );
		$norm = mb_strtolower( preg_replace( '/\s+/', ' ', $t ) );
		$cmp  = mb_strtolower( $std[ $c['price_type'] ] ?? '' );
		return ( $cmp && $norm === $cmp ) ? '' : $t;
	}
}
