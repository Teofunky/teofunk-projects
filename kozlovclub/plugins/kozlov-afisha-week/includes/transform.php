<?php
/**
 * Kozlov Afisha Week: разметка главной.
 *
 * Чистая функция без WordPress: получает готовый HTML главной и дату «сегодня» (по Москве), возвращает HTML,
 * в котором карточки афиши дальше первых N дней лежат в <template class="kaw-later" data-kaw-d="ГГГГ-ММ-ДД">.
 * Браузер такие карточки не раскладывает и не грузит их картинки, но они остаются на странице: скрипт плагина
 * раскрывает их по кнопке «Показать концерты …», а панель фильтров (Kozlov Afisha Filters 1.0.8+) раскрывает все
 * перед фильтром или поиском.
 *
 * Правила безопасности:
 *  - карточка, дату которой не удалось прочитать, остаётся видимой;
 *  - если не найдено ни одной карточки или ни одна не отложена, HTML возвращается без изменений;
 *  - любая ошибка регулярного выражения возвращает исходный HTML.
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'KAW_CLI' ) ) {
	exit;
}

if ( ! defined( 'DAY_IN_SECONDS_KAW' ) ) {
	define( 'DAY_IN_SECONDS_KAW', 86400 );
}

/** Короткие названия месяцев темы (функция _month в functions.php темы). */
function kaw_month_map() {
	return array(
		'янв' => 1, 'фев' => 2, 'мар' => 3, 'апр' => 4, 'май' => 5, 'июн' => 6,
		'июл' => 7, 'авг' => 8, 'сен' => 9, 'окт' => 10, 'ноя' => 11, 'дек' => 12,
		'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
		'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
	);
}

/**
 * Дата карточки ГГГГ-ММ-ДД или '' если прочитать не удалось.
 * В карточке темы есть только день и месяц: год берётся тот, при котором дата ближе всего к сегодняшней
 * и не раньше чем за 2 дня до неё (афиша показывает только будущие концерты).
 */
function kaw_card_date( $card, $today_ts ) {
	if ( ! preg_match( '#<div class="cell cell_1">\s*(\d{1,2})\s*</div>.*?<div class="row row_1"[^>]*>\s*([^\s|<]+)#su', $card, $m ) ) {
		return '';
	}
	$day = (int) $m[1];
	$key = function_exists( 'mb_strtolower' ) ? mb_strtolower( $m[2], 'UTF-8' ) : strtolower( $m[2] );
	$key = function_exists( 'mb_substr' ) ? mb_substr( $key, 0, 3, 'UTF-8' ) : substr( $key, 0, 3 );
	$map = kaw_month_map();
	if ( $day < 1 || $day > 31 || ! isset( $map[ $key ] ) ) {
		return '';
	}
	$month = $map[ $key ];
	$year  = (int) gmdate( 'Y', $today_ts );
	foreach ( array( $year, $year + 1 ) as $y ) {
		if ( ! checkdate( $month, $day, $y ) ) {
			continue;
		}
		$ts = gmmktime( 0, 0, 0, $month, $day, $y );
		if ( $ts >= $today_ts - 2 * DAY_IN_SECONDS_KAW && $ts <= $today_ts + 400 * DAY_IN_SECONDS_KAW ) {
			return gmdate( 'Y-m-d', $ts );
		}
	}
	return '';
}

/** Отложенная карточка: картинки ленивые, без srcset (иначе на retina берётся оригинал вместо превью). */
function kaw_prepare_card( $card ) {
	$out = preg_replace_callback(
		'#<img\b[^>]*>#i',
		function ( $m ) {
			$tag = preg_replace( '#\s(?:srcset|sizes)="[^"]*"#i', '', $m[0] );
			if ( ! preg_match( '#\sloading=#i', $tag ) ) {
				$tag = preg_replace( '#^<img\b#i', '<img loading="lazy"', $tag );
			}
			if ( ! preg_match( '#\sdecoding=#i', $tag ) ) {
				$tag = preg_replace( '#^<img\b#i', '<img decoding="async"', $tag );
			}
			return $tag;
		},
		$card
	);
	return is_string( $out ) ? $out : $card;
}

/**
 * @param string $html      готовый HTML страницы
 * @param string $today     сегодняшняя дата по Москве, ГГГГ-ММ-ДД
 * @param int    $first     сколько дней видно сразу (сегодня и ещё first-1)
 * @param int    $step      сколько дней открывает кнопка
 * @return array { html: string, total: int, kept: int, later: int, reason: string }
 */
function kaw_transform( $html, $today, $first = 7, $step = 7 ) {
	$res = array( 'html' => $html, 'total' => 0, 'kept' => 0, 'later' => 0, 'reason' => '' );
	if ( ! is_string( $html ) || false === strpos( $html, '<div class="event today_' ) || ! preg_match( '#^\d{4}-\d\d-\d\d$#', $today ) ) {
		$res['reason'] = 'no afisha';
		return $res;
	}
	$first    = max( 1, min( 62, (int) $first ) );
	$step     = max( 1, min( 62, (int) $step ) );
	$today_ts = gmmktime( 0, 0, 0, (int) substr( $today, 5, 2 ), (int) substr( $today, 8, 2 ), (int) substr( $today, 0, 4 ) );
	$limit    = gmdate( 'Y-m-d', $today_ts + ( $first - 1 ) * DAY_IN_SECONDS_KAW );

	$total = 0;
	$later = 0;
	$out   = preg_replace_callback(
		'#<div class="event today_[^"]*">.*?</div> <!-- event -->#su',
		function ( $m ) use ( $today_ts, $limit, &$total, &$later ) {
			$total++;
			$date = kaw_card_date( $m[0], $today_ts );
			if ( '' === $date || $date <= $limit ) {
				return $m[0];
			}
			$later++;
			return '<template class="kaw-later" data-kaw-d="' . $date . '">' . kaw_prepare_card( $m[0] ) . '</template>';
		},
		$html
	);
	if ( ! is_string( $out ) ) {
		$res['reason'] = 'regex error ' . preg_last_error();
		return $res;
	}
	$res['total'] = $total;
	$res['later'] = $later;
	$res['kept']  = $total - $later;
	if ( ! $later ) {
		$res['reason'] = $total ? 'nothing to defer' : 'no cards';
		return $res;
	}

	// Заголовок месяца и его блок, где все карточки отложены, скрыты до раскрытия (класс kaw-hide).
	$parts = preg_split( '#(?=<div class="event_month">)#u', $out );
	if ( is_array( $parts ) ) {
		foreach ( $parts as $i => $part ) {
			if ( 0 !== strpos( $part, '<div class="event_month">' ) ) {
				continue;
			}
			$cut = strpos( $part, '</div> <!-- events -->' );
			$seg = false === $cut ? $part : substr( $part, 0, $cut );
			$all = substr_count( $seg, '<div class="event today_' );
			$def = substr_count( $seg, '<template class="kaw-later"' );
			if ( $def > 0 && $all === $def ) {
				$part = preg_replace( '#^<div class="event_month">#', '<div class="event_month kaw-hide">', $part, 1 );
				$part = preg_replace( '#<div class="events (view_[a-z]+)">#', '<div class="events $1 kaw-hide">', $part, 1 );
				$parts[ $i ] = $part;
			}
		}
		$out = implode( '', $parts );
	}

	// Метка на блоке афиши: по ней включаются стили и скрипт плагина.
	$attrs = ' data-kaw-first="' . $first . '" data-kaw-step="' . $step . '" data-kaw-today="' . $today . '"';
	$tagged = preg_replace( '#(<div class="[^"]*\bevents_wrap\b[^"]*)"#u', '$1 kaw-on"' . $attrs, $out, 1, $n );
	if ( ! is_string( $tagged ) || 1 !== $n ) {
		$res['reason'] = 'no events_wrap';
		return $res; // без метки скрипт не раскроет карточки: отдаём страницу как была
	}

	$res['html'] = $tagged;
	return $res;
}
