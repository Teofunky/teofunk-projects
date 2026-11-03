<?php
/**
 * Публичные функции Kozlov Core для темы и плагинов.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Сцены по умолчанию: slug => [название, цвет точки, старое поле Magic Fields]. */
function kc_default_stages() {
	return array(
		'main-scene'  => array( 'Главная сцена', '#f24841', 'location_main_scene' ),
		'mansarda'    => array( 'Мансарда', '#f24841', 'location_mansarda' ),
		'the-beat'    => array( 'The Beat', '#f2c230', 'location_the_beat' ),
		'myasnitskaya' => array( 'Сцена на Мясницкой, 15', '#f24841', 'location_myasnitskaya' ),
	);
}

/** Жанры по умолчанию: slug (как в старом ?genre=) => название. */
function kc_default_genres() {
	return array(
		'jazz' => 'Джаз', 'fusion' => 'Фьюжн', 'soul' => 'Соул', 'jazz-rock' => 'Джаз-рок', 'gospel' => 'Госпел',
		'blues' => 'Блюз', 'manush' => 'Мануш', 'jam' => 'Джем-сэшн', 'for-kids' => 'Детям', 'avangard' => 'Авангард',
		'classics' => 'Классика', 'ethnics' => 'Этника', 'rock' => 'Рок', 'funk' => 'Фанк', 'folk' => 'Фолк', 'pop' => 'Поп',
		'dance' => 'Танцы', 'latin' => 'Латино', 'hiphop' => 'Хип-хоп', 'indi' => 'Инди', 'flamenko' => 'Фламенко',
		'festival' => 'Фестиваль', 'master' => 'Мастер-класс', 'lecture' => 'Лекция', 'arsenal' => 'Арсенал', 'free' => 'Вход свободный',
	);
}

/** Типы цены. */
function kc_price_types() {
	return array(
		'ticket'  => 'Билеты (Ticketscloud)',
		'deposit' => 'Депозит / бронь стола',
		'free'    => 'Вход свободный',
		'info'    => 'Без продажи (только информация)',
	);
}

/** Текст цены для карточки: из поля kc_price_text, иначе собирается из типа и суммы. */
function kc_price_text( $post_id ) {
	$text = trim( (string) get_post_meta( $post_id, 'kc_price_text', true ) );
	if ( '' !== $text ) {
		return $text;
	}
	$type  = get_post_meta( $post_id, 'kc_price_type', true );
	$price = (int) get_post_meta( $post_id, 'kc_price', true );
	switch ( $type ) {
		case 'free':
			return 'Вход свободный';
		case 'deposit':
			return $price ? 'Депозит ' . number_format( $price, 0, '', ' ' ) . ' руб.' : 'Депозит';
		case 'ticket':
			return $price ? 'от ' . number_format( $price, 0, '', ' ' ) . ' руб.' : '';
		default:
			return '';
	}
}

/** Дата и время начала как DateTimeImmutable в поясе сайта, либо null. */
function kc_start( $post_id ) {
	$raw = get_post_meta( $post_id, 'kc_start', true );
	if ( ! $raw ) {
		return null;
	}
	try {
		return new DateTimeImmutable( $raw, wp_timezone() );
	} catch ( Exception $e ) {
		return null;
	}
}

/** Сцена концерта: массив term или null. */
function kc_stage( $post_id ) {
	$terms = get_the_terms( $post_id, KC_TAX_STAGE );
	if ( ! $terms || is_wp_error( $terms ) ) {
		return null;
	}
	$t = $terms[0];
	return array(
		'id'    => $t->term_id,
		'slug'  => $t->slug,
		'name'  => $t->name,
		'color' => get_term_meta( $t->term_id, 'kc_color', true ) ?: '#f24841',
		'url'   => get_term_link( $t ),
	);
}

/**
 * Нормализованный концерт для темы и плагинов.
 * Возвращает null, если это не концерт.
 */
function kozlov_concert( $post = null ) {
	$post = get_post( $post );
	if ( ! $post || KC_POST_TYPE !== $post->post_type ) {
		return null;
	}
	$id    = $post->ID;
	$start = kc_start( $id );
	$end   = get_post_meta( $id, 'kc_end', true );
	$genres = array();
	$gt = get_the_terms( $id, KC_TAX_GENRE );
	if ( $gt && ! is_wp_error( $gt ) ) {
		foreach ( $gt as $t ) {
			$genres[] = array( 'slug' => $t->slug, 'name' => $t->name );
		}
	}
	$thumb = get_post_thumbnail_id( $id );
	$hero  = (int) get_post_meta( $id, 'kc_hero', true );
	$type  = get_post_meta( $id, 'kc_price_type', true ) ?: 'info';
	$tc    = trim( (string) get_post_meta( $id, 'kc_tc_event', true ) );
	$wd    = array( 'Воскресенье', 'Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота' );
	$mon   = array( 'Янв', 'Фев', 'Мар', 'Апр', 'Май', 'Июн', 'Июл', 'Авг', 'Сен', 'Окт', 'Ноя', 'Дек' );
	return array(
		'id'        => $id,
		'title'     => get_the_title( $id ),
		'program'   => (string) get_post_meta( $id, 'kc_program', true ),
		'url'       => get_permalink( $id ),
		'start'     => $start ? $start->format( 'Y-m-d H:i:s' ) : '',
		'date'      => $start ? $start->format( 'Y-m-d' ) : '',
		'time'      => $start ? $start->format( 'H:i' ) : '',
		'day'       => $start ? $start->format( 'd' ) : '',
		'month_short' => $start ? $mon[ (int) $start->format( 'n' ) - 1 ] : '',
		'weekday'   => $start ? $wd[ (int) $start->format( 'w' ) ] : '',
		'end'       => $end ?: '',
		'timestamp' => $start ? $start->getTimestamp() : 0,
		'is_today'  => $start ? $start->format( 'Y-m-d' ) === wp_date( 'Y-m-d' ) : false,
		'is_past'   => $start ? $start->getTimestamp() < current_datetime()->getTimestamp() : false,
		'stage'     => kc_stage( $id ),
		'genres'    => $genres,
		'price'     => array(
			'type'   => $type,
			'amount' => (int) get_post_meta( $id, 'kc_price', true ),
			'text'   => kc_price_text( $id ),
		),
		'tc_event'  => $tc,
		'can_buy'   => 'ticket' === $type && '' !== $tc && ! get_post_meta( $id, 'kc_sold_out', true ) && ! get_post_meta( $id, 'kc_cancelled', true ),
		'booking'   => (bool) get_post_meta( $id, 'kc_booking', true ),
		'sold_out'  => (bool) get_post_meta( $id, 'kc_sold_out', true ),
		'cancelled' => (bool) get_post_meta( $id, 'kc_cancelled', true ),
		'image'     => array(
			'id'    => $thumb,
			'thumb' => $thumb ? wp_get_attachment_image_url( $thumb, 'kc-card' ) : '',
			'large' => $thumb ? wp_get_attachment_image_url( $thumb, 'large' ) : '',
			'hero'  => $hero ? wp_get_attachment_image_url( $hero, 'full' ) : '',
			'hero_id' => $hero,
		),
		'slider'    => array(
			'show'  => (bool) get_post_meta( $id, 'kc_slider_show', true ),
			'title' => (string) get_post_meta( $id, 'kc_slider_title', true ),
			'image' => ( $sid = (int) get_post_meta( $id, 'kc_slider_img', true ) ) ? wp_get_attachment_image_url( $sid, 'kc-slider' ) : '',
		),
		'videos'    => (array) get_post_meta( $id, 'kc_videos', true ),
		'artists'   => (array) get_post_meta( $id, 'kc_artists', true ),
		'legacy_id' => (int) get_post_meta( $id, 'kc_legacy_id', true ),
	);
}

/**
 * Выборка концертов. Аргументы: from (Y-m-d), to (Y-m-d), stage (slug), genre (slug), q (строка),
 * limit (int, по умолчанию 500), past (bool, включать прошедшие), order (asc|desc), ids_only (bool).
 */
function kozlov_concerts( $args = array() ) {
	return KC_Query::concerts( $args );
}


/**
 * Адрес видео для встраивания. Принимает ссылку (YouTube, RuTube, VK, VK Видео) или код для вставки
 * (<iframe src="...">). Возвращает адрес для iframe или пустую строку.
 */
function kozlov_video_src( $in ) {
	$in = trim( (string) $in );
	if ( '' === $in ) {
		return '';
	}
	if ( preg_match( '/<iframe[^>]+src\s*=\s*["\']([^"\']+)["\']/i', $in, $m ) ) {
		$in = html_entity_decode( trim( $m[1] ), ENT_QUOTES );
	}
	if ( 0 === strpos( $in, '//' ) ) {
		$in = 'https:' . $in;
	}
	if ( ! preg_match( '#^https?://#i', $in ) ) {
		return '';
	}
	if ( preg_match( '#youtu\.be/([\w-]{11})|youtube(?:-nocookie)?\.com/(?:watch\?(?:[^\#]*&)?v=|embed/|shorts/|live/)([\w-]{11})#', $in, $m ) ) {
		return 'https://www.youtube.com/embed/' . ( $m[1] ?: $m[2] );
	}
	if ( preg_match( '#rutube\.ru/(?:video|play/embed|embed)/(?:private/)?([A-Za-z0-9]+)#', $in, $m ) ) {
		return 'https://rutube.ru/play/embed/' . $m[1];
	}
	// VK: код для вставки video_ext.php оставляем как есть (со всеми параметрами), только без autoplay
	if ( preg_match( '#^https?://(?:www\.)?(vk\.com|vk\.ru|vkvideo\.ru)/video_ext\.php\?(.+)$#i', $in, $m ) ) {
		parse_str( html_entity_decode( $m[2] ), $q );
		$keep = array();
		foreach ( array( 'oid', 'id', 'hash' ) as $k ) {
			if ( isset( $q[ $k ] ) && '' !== $q[ $k ] ) {
				$keep[ $k ] = $q[ $k ];
			}
		}
		if ( isset( $keep['oid'], $keep['id'] ) ) {
			return 'https://' . strtolower( $m[1] ) . '/video_ext.php?' . http_build_query( $keep );
		}
	}
	// VK: ссылка вида vk.com/video-123_456, vkvideo.ru/video806799120_456239493, vk.com/video?z=video-1_2
	if ( preg_match( '#(?:vk\.com|vk\.ru|vkvideo\.ru)/[^\s]*?video(-?\d+)_(\d+)(?:_([0-9a-f]{8,}))?#i', $in, $m ) ) {
		$u = 'https://vk.ru/video_ext.php?oid=' . $m[1] . '&id=' . $m[2];
		if ( ! empty( $m[3] ) ) {
			$u .= '&hash=' . $m[3];
		}
		return $u;
	}
	return esc_url_raw( $in );
}

/** Список видео из текста поля: ссылки по одной в строке и/или коды <iframe>, в любом сочетании. */
function kozlov_parse_videos( $text ) {
	$text = (string) $text;
	$out  = array();
	if ( preg_match_all( '/<iframe\b.*?(?:<\/iframe>|\/>)/is', $text, $m ) ) {
		foreach ( $m[0] as $code ) {
			$u = kozlov_video_src( $code );
			if ( $u ) {
				$out[] = $u;
			}
		}
		$text = preg_replace( '/<iframe\b.*?(?:<\/iframe>|\/>)/is', "\n", $text );
	}
	foreach ( preg_split( '/[\r\n]+/', $text ) as $line ) {
		$u = kozlov_video_src( $line );
		if ( $u ) {
			$out[] = $u;
		}
	}
	return array_values( array_unique( $out ) );
}
