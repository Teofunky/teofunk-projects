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
