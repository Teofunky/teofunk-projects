<?php
/**
 * Вывод страницы на сайте.
 *
 * Страница выводится собственным шаблоном без get_header()/get_footer() темы,
 * поэтому стили темы и других плагинов на неё не влияют, а её стили не попадают на сайт.
 * Любая ошибка при выводе возвращает обычный шаблон темы.
 *
 * @package Kozlov_New_Year
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'template_include', 'kny_template_include', 99999 );

/**
 * Подменяет шаблон только для одной выбранной страницы.
 *
 * @param string $template Шаблон темы.
 * @return string
 */
function kny_template_include( $template ) {
	if ( is_admin() || is_feed() ) {
		return $template;
	}
	$s = kny_get_settings();
	if ( empty( $s['enabled'] ) || empty( $s['page_id'] ) || ! is_page( (int) $s['page_id'] ) ) {
		return $template;
	}
	$GLOBALS['kny_fallback_template'] = $template;
	return KNY_DIR . 'templates/page.php';
}

/**
 * Выводит страницу с защитой: при ошибке ничего не выводит и возвращает false.
 *
 * @param array $data Данные.
 * @param array $ctx  Контекст (url, noindex, preview).
 * @return bool
 */
function kny_safe_render( $data, $ctx ) {
	$level = ob_get_level();
	ob_start();
	try {
		kny_render_landing( $data, $ctx );
	} catch ( Throwable $e ) {
		while ( ob_get_level() > $level ) {
			ob_end_clean();
		}
		error_log( '[Kozlov New Year] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		return false;
	}
	ob_end_flush();
	return true;
}

/**
 * Подключает шаблон страницы.
 *
 * @param array $d   Данные.
 * @param array $ctx Контекст.
 */
function kny_render_landing( $d, $ctx ) {
	$ctx = array_merge(
		array(
			'url'     => '',
			'noindex' => false,
			'preview' => false,
			'page_id' => 0,
		),
		$ctx
	);
	include KNY_DIR . 'templates/landing.php';
}

/* ---------- Помощники вывода ---------- */

/**
 * URL картинки: plugin:имя → файл плагина, иначе как есть.
 *
 * @param string $v Значение.
 * @return string
 */
function kny_img( $v ) {
	$v = (string) $v;
	if ( 0 === strpos( $v, 'plugin:' ) ) {
		return KNY_URL . 'assets/img/' . rawurlencode( basename( substr( $v, 7 ) ) ) . '?ver=' . KNY_VERSION;
	}
	return $v;
}

/**
 * Ссылка tel: из телефона в любом виде.
 *
 * @param string $phone Телефон.
 * @return string
 */
function kny_tel( $phone ) {
	$digits = preg_replace( '/\D/', '', (string) $phone );
	if ( 11 === strlen( $digits ) && '8' === $digits[0] ) {
		$digits = '7' . substr( $digits, 1 );
	}
	return 'tel:+' . $digits;
}

/**
 * Многострочный простой текст: строки через <br>.
 *
 * @param string $s Текст.
 * @return string
 */
function kny_lines( $s ) {
	$lines = preg_split( '/\n/', trim( (string) $s ) );
	return implode( '<br>', array_map( 'esc_html', $lines ) );
}

/**
 * Подставляет {phone} и чистит разрешённые теги.
 *
 * @param string $s Текст.
 * @return string
 */
function kny_rich_prepare( $s ) {
	$s = wp_kses( str_replace( "\r\n", "\n", (string) $s ), kny_allowed_html() );
	if ( false !== strpos( $s, '{phone}' ) ) {
		$phone = isset( $GLOBALS['kny_phone'] ) ? $GLOBALS['kny_phone'] : '';
		$link  = '<a href="' . esc_attr( kny_tel( $phone ) ) . '">' . esc_html( $phone ) . '</a>';
		$s     = str_replace( '{phone}', $link, $s );
	}
	return trim( $s );
}

/**
 * Текст абзацами: пустая строка = новый <p>, перенос = <br>.
 *
 * @param string $s     Текст.
 * @param string $class Класс абзацев.
 * @return string
 */
function kny_rich( $s, $class = '' ) {
	$s = kny_rich_prepare( $s );
	if ( '' === $s ) {
		return '';
	}
	$attr = $class ? ' class="' . esc_attr( $class ) . '"' : '';
	$out  = '';
	foreach ( preg_split( '/\n\s*\n/', $s ) as $para ) {
		$para = trim( $para );
		if ( '' !== $para ) {
			$out .= '<p' . $attr . '>' . nl2br( $para, false ) . '</p>';
		}
	}
	return $out;
}

/**
 * Текст без обёртки <p> (для вставки внутрь абзаца).
 *
 * @param string $s Текст.
 * @return string
 */
function kny_rich_inline( $s ) {
	return nl2br( kny_rich_prepare( $s ), false );
}

/**
 * Склонение: 1 событие, 2 события, 5 событий.
 *
 * @param int    $n    Число.
 * @param string $one  1.
 * @param string $few  2-4.
 * @param string $many 5+.
 * @return string
 */
function kny_plural( $n, $one, $few, $many ) {
	$n10  = $n % 10;
	$n100 = $n % 100;
	if ( 1 === $n10 && 11 !== $n100 ) {
		return $one;
	}
	if ( $n10 >= 2 && $n10 <= 4 && ( $n100 < 12 || $n100 > 14 ) ) {
		return $few;
	}
	return $many;
}

/**
 * Видимые концерты, отсортированные по времени (до 06:00 считается ночью после полуночи).
 *
 * @param array $events Концерты.
 * @return array
 */
function kny_visible_events( $events ) {
	$list = array();
	foreach ( (array) $events as $i => $e ) {
		if ( empty( $e['hidden'] ) && '' !== trim( (string) $e['title'] ) ) {
			$e['_order'] = $i;
			$list[]      = $e;
		}
	}
	usort(
		$list,
		function ( $a, $b ) {
			$ta = kny_time_minutes( $a['time'] );
			$tb = kny_time_minutes( $b['time'] );
			return $ta === $tb ? $a['_order'] - $b['_order'] : $ta - $tb;
		}
	);
	return $list;
}

/**
 * Минуты от начала дня для сортировки.
 *
 * @param string $t Время ЧЧ:ММ.
 * @return int
 */
function kny_time_minutes( $t ) {
	if ( ! preg_match( '/(\d{1,2})[:.](\d{2})/', (string) $t, $m ) ) {
		return 9999;
	}
	$min = (int) $m[1] * 60 + (int) $m[2];
	return $min < 360 ? $min + 1440 : $min;
}

/**
 * Список сцен для фильтра: заданные + встреченные в карточках.
 *
 * @param string $lines  Сцены по строкам.
 * @param array  $events Концерты.
 * @return array
 */
function kny_stage_list( $lines, $events ) {
	$stages = array();
	foreach ( preg_split( '/\n/', (string) $lines ) as $st ) {
		$st = trim( $st );
		if ( '' !== $st && ! in_array( $st, $stages, true ) ) {
			$stages[] = $st;
		}
	}
	foreach ( $events as $e ) {
		$st = trim( (string) $e['stage'] );
		if ( '' !== $st && ! in_array( $st, $stages, true ) ) {
			$stages[] = $st;
		}
	}
	return $stages;
}

/**
 * Код счётчиков для <head>.
 *
 * @param array $s Настройки.
 * @return string
 */
function kny_analytics_head( $s ) {
	$out = '';
	$id  = preg_replace( '/\D/', '', (string) $s['metrika_id'] );
	if ( $id && false === stripos( (string) $s['head_code'], 'mc.yandex.ru' ) ) {
		$opts = '{clickmap:true, trackLinks:true, accurateTrackBounce:true' . ( ! empty( $s['webvisor'] ) ? ', webvisor:true' : '' ) . '}';
		$out .= '<script>(function(m,e,t,r,i,k,a){m[i]=m[i]||function(){(m[i].a=m[i].a||[]).push(arguments)};m[i].l=1*new Date();for(var j=0;j<document.scripts.length;j++){if(document.scripts[j].src===r){return;}}k=e.createElement(t),a=e.getElementsByTagName(t)[0],k.async=1,k.src=r,a.parentNode.insertBefore(k,a)})(window,document,"script","https://mc.yandex.ru/metrika/tag.js","ym");ym(' . $id . ',"init",' . $opts . ');</script>' . "\n";
	}
	if ( '' !== trim( (string) $s['head_code'] ) ) {
		$out .= $s['head_code'] . "\n";
	}
	return $out;
}

/**
 * Код счётчиков в начале <body>.
 *
 * @param array $s Настройки.
 * @return string
 */
function kny_analytics_body( $s ) {
	$out = '';
	$id  = preg_replace( '/\D/', '', (string) $s['metrika_id'] );
	if ( $id && false === stripos( (string) $s['head_code'], 'mc.yandex.ru' ) ) {
		$out .= '<noscript><div><img src="https://mc.yandex.ru/watch/' . $id . '" style="position:absolute;left:-9999px" alt=""></div></noscript>' . "\n";
	}
	if ( '' !== trim( (string) $s['body_code'] ) ) {
		$out .= $s['body_code'] . "\n";
	}
	return $out;
}
