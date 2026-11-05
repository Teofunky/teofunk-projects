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

/**
 * Месяц по слову из карточки. Тема пишет короткие названия (функция _month: «Янв» … «Дек», «Jan» … «Dec»);
 * на всякий случай понимаются и родительный падеж («мая», «ноября»), и английские полные названия.
 * Сравнение регулярными выражениями в режиме UTF-8 без учёта регистра: расширение mbstring не нужно.
 */
function kaw_month_of( $word ) {
	static $pats = array(
		1  => 'янв|jan', 2 => 'фев|feb', 3 => 'мар|mar', 4 => 'апр|apr', 5 => 'май|мая|may', 6 => 'июн|jun',
		7  => 'июл|jul', 8 => 'авг|aug', 9 => 'сен|sep', 10 => 'окт|oct', 11 => 'ноя|nov', 12 => 'дек|dec',
	);
	foreach ( $pats as $n => $p ) {
		if ( preg_match( '#^(?:' . $p . ')#iu', $word ) ) {
			return $n;
		}
	}
	return 0;
}

/** Родительный падеж для подписи «до 31 декабря». */
function kaw_month_gen( $n ) {
	$g = array( 1 => 'января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря' );
	return isset( $g[ $n ] ) ? $g[ $n ] : '';
}

function kaw_plural( $n, $one, $few, $many ) {
	$a = $n % 10;
	$b = $n % 100;
	return ( 1 === $a && 11 !== $b ) ? $one : ( ( $a >= 2 && $a <= 4 && ( $b < 12 || $b > 14 ) ) ? $few : $many );
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
	$day   = (int) $m[1];
	$month = kaw_month_of( $m[2] );
	if ( $day < 1 || $day > 31 || ! $month ) {
		return '';
	}
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
 * @param string $all_url   адрес всей афиши без плагина (ссылка для посетителей без JavaScript)
 * @return array { html: string, total: int, kept: int, later: int, reason: string }
 */
function kaw_transform( $html, $today, $first = 7, $step = 7, $all_url = '/?kaw=off' ) {
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
	$last  = '';
	$out   = preg_replace_callback(
		'#<div class="event today_[^"]*">.*?</div> <!-- event -->#su',
		function ( $m ) use ( $today_ts, $limit, &$total, &$later, &$last ) {
			$total++;
			$date = kaw_card_date( $m[0], $today_ts );
			if ( '' === $date || $date <= $limit ) {
				return $m[0];
			}
			$later++;
			if ( $date > $last ) {
				$last = $date;
			}
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

	// Ссылка на всю афишу сразу под афишей. Она в самом HTML: если скрипт не загрузился или JavaScript выключен,
	// посетитель всё равно откроет все концерты (адрес с ?kaw=off плагин не трогает). Скрипт заменяет её кнопкой.
	// Тема закрывает блоки месяцев простым </div>, а метку </div> <!-- events --> ставит один раз после афиши.
	// Если меток окажется несколько, ссылка встаёт после последней, то есть после всей афиши.
	$end = '</div> <!-- events -->';
	$pos = strrpos( $out, $end );
	if ( false === $pos ) {
		$res['reason'] = 'no events end';
		return $res; // без места для ссылки не прячем ничего
	}
	$sub  = 'Ещё ' . $later . ' ' . kaw_plural( $later, 'концерт', 'концерта', 'концертов' );
	$sub .= $last ? ' до ' . (int) substr( $last, 8, 2 ) . ' ' . kaw_month_gen( (int) substr( $last, 5, 2 ) ) : '';
	$link = '<div class="kaw-more kaw-nojs"><a class="kaw-btn" rel="nofollow" href="' . htmlspecialchars( $all_url, ENT_QUOTES, 'UTF-8' ) . '">Показать всю афишу</a><p class="kaw-sub">' . $sub . '</p></div>';
	$out  = substr( $out, 0, $pos + strlen( $end ) ) . $link . substr( $out, $pos + strlen( $end ) );

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
