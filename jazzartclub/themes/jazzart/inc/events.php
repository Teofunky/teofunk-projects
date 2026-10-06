<?php
/**
 * Афиша: карточки концертов из обычного текста страницы «Анонсы».
 *
 * Редактор пишет программу как привык: дата полужирным с новой строки
 * («7 октября, начало 19.30»), ниже фото, название и описание. Сайт сам делит
 * текст на концерты по этим датам. Если дат в тексте нет, страница выводится как есть.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function jac_months() {
	return array(
		'января' => 1, 'февраля' => 2, 'марта' => 3, 'апреля' => 4, 'мая' => 5, 'июня' => 6,
		'июля' => 7, 'августа' => 8, 'сентября' => 9, 'октября' => 10, 'ноября' => 11, 'декабря' => 12,
	);
}

/** Заголовок, набранный одними прописными, приводим к обычному виду: «ПРОГРАММА НА ОКТЯБРЬ» → «Программа на октябрь». */
function jac_soften_caps( $text, $title_case = false ) {
	$text = trim( preg_replace( '~\s+~u', ' ', wp_strip_all_tags( $text ) ) );
	if ( '' === $text || ! preg_match( '~\p{Lu}~u', $text ) || $text !== mb_strtoupper( $text ) ) {
		return $text;
	}
	if ( $title_case ) {
		return mb_convert_case( $text, MB_CASE_TITLE );
	}
	$lower = mb_strtolower( $text );
	return mb_strtoupper( mb_substr( $lower, 0, 1 ) ) . mb_substr( $lower, 1 );
}

/**
 * Разбирает страницу афиши.
 *
 * @return array{season:string,events:array<int,array<string,mixed>>}
 */
function jac_events( $page = null ) {
	static $cache = array();

	$page = $page ? get_post( $page ) : jac_home_page();
	if ( ! $page ) {
		return array( 'season' => '', 'events' => array() );
	}
	if ( isset( $cache[ $page->ID ] ) ) {
		return $cache[ $page->ID ];
	}

	$html   = $page->post_content;
	$months = jac_months();
	$re     = '~<(strong|b)\b[^>]*>\s*(\d{1,2})\s+(' . implode( '|', array_keys( $months ) ) . ')\b([^<]{0,60})</\1>~iu';
	$result = array( 'season' => '', 'events' => array() );

	if ( ! preg_match_all( $re, $html, $marks, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) || count( $marks ) < 2 ) {
		return $cache[ $page->ID ] = $result;
	}

	$heading_re = '~<h[1-6]\b[^>]*>(.*?)</h[1-6]>~isu';
	$weekdays   = array( 'воскресенье', 'понедельник', 'вторник', 'среда', 'четверг', 'пятница', 'суббота' );
	$short      = array( 1 => 'янв', 'фев', 'мар', 'апр', 'мая', 'июн', 'июл', 'авг', 'сен', 'окт', 'ноя', 'дек' );
	$tz         = wp_timezone();
	$ref        = time(); // Год в тексте не пишут: берём тот, при котором дата ближе всего к сегодняшнему дню.

	// Вступление до первой даты: заголовок сезона и заголовок первого раздела.
	$section = '';
	$intro   = substr( $html, 0, $marks[0][0][1] );
	if ( preg_match_all( $heading_re, $intro, $heads ) ) {
		$titles = array_values( array_filter( array_map( 'jac_soften_caps', $heads[1] ) ) );
		if ( count( $titles ) > 1 ) {
			$result['season'] = $titles[0];
		}
		$section = $titles ? end( $titles ) : '';
	}

	$used = array();
	foreach ( $marks as $i => $mark ) {
		$start = $mark[0][1] + strlen( $mark[0][0] );
		$end   = isset( $marks[ $i + 1 ] ) ? $marks[ $i + 1 ][0][1] : strlen( $html );
		$body  = substr( $html, $start, $end - $start );

		// Заголовок внутри блока («Программа на ноябрь») относится уже к следующим концертам.
		$next_section = '';
		if ( preg_match_all( $heading_re, $body, $heads ) ) {
			$titles       = array_values( array_filter( array_map( 'jac_soften_caps', $heads[1] ) ) );
			$next_section = $titles ? end( $titles ) : '';
			$body         = preg_replace( $heading_re, '', $body );
		}

		// Фото: первая картинка блока, показывается отдельно от текста.
		$image = '';
		$full  = '';
		if ( preg_match( '~(?:<a\b[^>]*\bhref=["\']([^"\']+)["\'][^>]*>\s*)?<img\b[^>]*\bsrc=["\']([^"\']+)["\'][^>]*>(?:\s*</a>)?~iu', $body, $im ) ) {
			$image = $im[2];
			$full  = ( ! empty( $im[1] ) && preg_match( '~\.(jpe?g|png|gif|webp)$~i', $im[1] ) ) ? $im[1] : $im[2];
			$body  = str_replace( $im[0], '', $body );
		}

		// Обрывки абзацев на стыках блоков.
		$body = preg_replace( '~^(?:\s|&nbsp;|\.|</p>|<br\s*/?>)+~iu', '', $body );
		$body = preg_replace( '~(?:\s|&nbsp;|<p\b[^>]*>|<br\s*/?>)+$~iu', '', $body );

		// Название: полужирное начало блока, иначе первая фраза.
		$title = '';
		if ( preg_match( '~^<(strong|b)\b[^>]*>(.*?)</\1>~isu', $body, $tm ) ) {
			$title = wp_strip_all_tags( $tm[2] );
			$body  = preg_replace( '~^(?:\s|\x{00A0}|&nbsp;)+~u', '', substr( $body, strlen( $tm[0] ) ) );
		} else {
			$plain = trim( preg_replace( '~\s+~u', ' ', wp_strip_all_tags( $body ) ) );
			$title = preg_split( '~(?<=[.!?])\s~u', $plain, 2 )[0];
			if ( mb_strlen( $title ) > 110 ) {
				$title = rtrim( mb_substr( $title, 0, mb_strrpos( mb_substr( $title, 0, 110 ), ' ' ) ), ' ,;:' ) . '…';
			}
		}
		$title = jac_soften_caps( rtrim( trim( $title ), '.' ), true );
		$title = html_entity_decode( wptexturize( $title ), ENT_QUOTES, 'UTF-8' ); // Тире и кавычки как в остальном тексте.

		// «АЛЕКСЕЙ ДАВЛЕТШИН — композитор…»: возвращаем имя в начало описания, чтобы фраза читалась.
		if ( preg_match( '~^[-–—]~u', $body ) ) {
			$body = '<strong>' . esc_html( $title ) . '</strong> ' . $body;
		}
		$body  = force_balance_tags( $body );
		$plain = trim( preg_replace( '~\s+~u', ' ', wp_strip_all_tags( $body ) ) );

		// Дата и время начала.
		$day   = (int) $mark[2][0];
		$month = $months[ mb_strtolower( $mark[3][0] ) ];
		$time  = '';
		$hour  = 12;
		$min   = 0;
		if ( preg_match( '~(\d{1,2})[.:](\d{2})~', $mark[4][0], $tm ) ) {
			$hour = (int) $tm[1];
			$min  = (int) $tm[2];
			$time = sprintf( '%d:%02d', $hour, $min );
		}
		$year = (int) wp_date( 'Y', $ref );
		$date = new DateTimeImmutable( sprintf( '%04d-%02d-%02d %02d:%02d:00', $year, $month, $day, $hour, $min ), $tz );
		if ( $date->getTimestamp() < $ref - 150 * DAY_IN_SECONDS ) {
			$date = $date->modify( '+1 year' );
		} elseif ( $date->getTimestamp() > $ref + 300 * DAY_IN_SECONDS ) {
			$date = $date->modify( '-1 year' );
		}

		$id = 'koncert-' . $day . '-' . $month;
		if ( isset( $used[ $id ] ) ) {
			$id .= '-' . ( ++$used[ $id ] );
		} else {
			$used[ $id ] = 1;
		}

		$result['events'][] = array(
			'id'          => $id,
			'day'         => $day,
			'month'       => mb_strtolower( $mark[3][0] ),
			'month_short' => $short[ $month ],
			'weekday'     => $weekdays[ (int) $date->format( 'w' ) ],
			'time'        => $time,
			'ts'          => $date->getTimestamp(),
			'title'       => $title,
			'text'        => $body,
			'excerpt'     => $plain === $title ? '' : wp_trim_words( $plain, 24, '…' ),
			'image'       => $image,
			'image_full'  => $full,
			'section'     => $section,
		);
		if ( $next_section ) {
			$section = $next_section;
		}
	}

	return $cache[ $page->ID ] = $result;
}

/** Ближайшие концерты: сегодняшние и будущие, по порядку. */
function jac_upcoming( $limit = 4 ) {
	$data  = jac_events();
	$today = ( new DateTimeImmutable( 'today', wp_timezone() ) )->getTimestamp();
	$list  = array_values( array_filter( $data['events'], function ( $e ) use ( $today ) {
		return $e['ts'] >= $today;
	} ) );
	usort( $list, function ( $a, $b ) {
		return $a['ts'] <=> $b['ts'];
	} );
	return array_slice( $list, 0, $limit );
}

/** Адрес страницы афиши и ссылка на конкретный концерт на ней. */
function jac_afisha_url( $event = null ) {
	$page = jac_home_page();
	$url  = $page ? get_permalink( $page ) : home_url( '/' );
	return $event ? $url . '#' . $event['id'] : $url;
}

/** Плашка с датой: число крупно, месяц и день недели. */
function jac_date_badge( $event ) {
	return '<span class="date-badge"><span class="date-day">' . (int) $event['day'] . '</span><span class="date-month">' . esc_html( $event['month'] ) . '</span><span class="date-weekday">' . esc_html( $event['weekday'] ) . '</span></span>';
}

/** Подсказка редактору на странице афиши. */
add_action( 'edit_form_after_title', function ( $post ) {
	$page = jac_home_page();
	if ( ! $page || $post->ID !== $page->ID ) {
		return;
	}
	echo '<div class="notice notice-info inline" style="margin:12px 0"><p style="font-size:14px"><strong>Как сайт делает карточки концертов.</strong> Каждый концерт начинайте с новой строки с даты <strong>полужирным</strong>, например: <strong>7 октября, начало 19.30</strong>. Ниже фото, название полужирным и описание. Заголовки «Программа на октябрь» оставляйте как сейчас. Год писать не нужно. Прошедшие концерты с главной уходят сами.</p></div>';
} );
