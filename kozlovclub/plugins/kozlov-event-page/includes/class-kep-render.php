<?php
/**
 * Вёрстка новой страницы концерта. Одна разметка на телефон и компьютер.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KEP_Render {

	const MONTHS_GEN = array( 1 => 'января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря' );
	const MONTHS_SHORT = array( 1 => 'янв', 'фев', 'мар', 'апр', 'мая', 'июн', 'июл', 'авг', 'сен', 'окт', 'ноя', 'дек' );
	const WD_SHORT = array( 'понедельник' => 'Пн', 'вторник' => 'Вт', 'среда' => 'Ср', 'четверг' => 'Чт', 'пятница' => 'Пт', 'суббота' => 'Сб', 'воскресенье' => 'Вс' );

	/**
	 * Вся страница.
	 *
	 * @param array $d   Данные из KEP_Parser::parse().
	 * @param array $ctx post_id, hero_id, program, date, code, permalink, more.
	 * @return string
	 */
	public static function page( $d, $ctx ) {
		$s      = KEP_Settings::get();
		$time   = self::show_time( $d['time'] );
		$videos = $d['videos'];

		$h  = '<div class="kep" id="kep" data-date="' . esc_attr( $ctx['date'] ) . '" data-type="' . esc_attr( $d['type'] ) . '">';
		$h .= '<nav class="kep-crumbs" aria-label="Навигация"><a href="' . esc_url( home_url( '/' ) ) . '">' . self::icon( 'back' ) . '<span>Афиша</span></a><span class="kep-crumbs-sep" aria-hidden="true">/</span><span class="kep-crumbs-here" aria-current="page">' . esc_html( $d['title'] ) . '</span></nav>';
		$h .= '<div class="kep-grid">';

		// Фото.
		$h .= '<figure class="kep-hero">' . self::hero( $d, $ctx );
		if ( $videos ) {
			$h .= '<a class="kep-watch" href="#kep-video"><span class="kep-watch-dot">' . self::icon( 'play' ) . '</span>Смотреть видео</a>';
		}
		$h .= '</figure>';

		// Карточка покупки.
		$h .= '<aside class="kep-card" aria-label="Дата и билеты">';
		$h .= '<div class="kep-when"><span class="kep-day">' . esc_html( $d['day'] ) . '</span><span class="kep-when-rows">';
		$h .= '<span class="kep-r1">' . esc_html( self::row1( $d['row1'], $time ) ) . '</span>';
		$h .= '<span class="kep-r2">' . esc_html( $d['weekday'] ) . ' <span class="kep-badge" hidden></span></span>';
		if ( '' !== $d['stage'] ) {
			$h .= '<span class="kep-r3">' . esc_html( $d['stage'] ) . '</span>';
		}
		$h .= '</span></div>';
		$h .= '<h1 class="kep-title">' . esc_html( $d['title'] ) . '</h1>';
		if ( '' !== $ctx['program'] ) {
			$h .= '<p class="kep-program">' . esc_html( self::program( $ctx['program'] ) ) . '</p>';
		}
		if ( $d['genres'] ) {
			$h .= '<ul class="kep-genres" aria-label="Жанры">';
			foreach ( $d['genres'] as $g ) {
				$h .= '<li><a href="' . esc_url( $g['url'] ) . '">' . esc_html( $g['name'] ) . '</a></li>';
			}
			$h .= '</ul>';
		}
		$h .= '<div class="kep-buy" id="kep-buy">' . self::buy( $d, $ctx, $s, false ) . '</div>';
		$h .= '<div class="kep-card-links">';
		if ( '' !== $d['calendar'] && 'past' !== $d['type'] ) {
			$h .= '<a href="' . esc_url( $d['calendar'] ) . '">' . self::icon( 'cal' ) . 'В календарь</a>';
		}
		$h .= '<button type="button" data-kep-share>' . self::icon( 'share' ) . 'Поделиться</button>';
		$h .= '</div>';
		$h .= '</aside>';

		// Основная колонка.
		$h .= '<div class="kep-main">';
		$h .= self::intro( $d );
		$h .= self::video_section( $videos );
		$h .= self::lineup_section( $d );
		$h .= self::about_section( $d );
		$h .= self::where_section( $d, $ctx, $s, $time );
		$h .= '</div>';
		$h .= '</div>';

		$h .= self::more_section( $ctx );
		$h .= '</div>';
		// Нижняя панель и подсказка вне .kep: у .kep обрезка фона, она скрыла бы их.
		$h .= '<div class="kep-fixed">' . self::bar( $d, $ctx, $s, $time ) . '<div class="kep-toast" role="status" aria-live="polite" hidden></div></div>';
		return $h;
	}

	/* ---------- блоки ---------- */

	private static function hero( $d, $ctx ) {
		if ( $ctx['hero_id'] ) {
			$img = wp_get_attachment_image(
				$ctx['hero_id'],
				'full',
				false,
				array(
					'class'         => 'kep-hero-img',
					'alt'           => $d['title'],
					'sizes'         => '(min-width: 1025px) 800px, 100vw',
					'loading'       => 'eager',
					'decoding'      => 'async',
					'fetchpriority' => 'high',
				)
			);
			if ( $img ) {
				return $img;
			}
		}
		if ( $d['banner'] && '' !== $d['banner']['src'] ) {
			return '<img class="kep-hero-img kep-hero-img--banner" src="' . esc_url( $d['banner']['src'] ) . '" alt="' . esc_attr( $d['title'] ) . '" loading="eager" decoding="async">';
		}
		return '<span class="kep-hero-empty" aria-hidden="true"></span>';
	}

	/**
	 * Цена и кнопка по типу события.
	 *
	 * @param array $d       Данные.
	 * @param array $ctx     Контекст.
	 * @param array $s       Настройки.
	 * @param bool  $compact Для нижней панели.
	 * @return string
	 */
	private static function buy( $d, $ctx, $s, $compact ) {
		$price = self::price( $d['price'] );
		$phone = $s['phone'];
		$tel   = 'tel:' . preg_replace( '/[^\d+]/', '', $phone );
		$h     = '';
		switch ( $d['type'] ) {
			case 'ticket':
				$h .= '<div class="kep-price"><span class="kep-price-v">' . esc_html( $price ) . '</span>';
				if ( '' !== $s['price_note'] ) {
					$h .= '<span class="kep-price-n">' . esc_html( $s['price_note'] ) . '</span>';
				}
				$h .= '</div>';
				$h .= self::tc_button( $d['tc'], 'Купить билет', $compact ? '' : 'kep-buy-main' );
				break;
			case 'deposit':
				$h .= '<div class="kep-price"><span class="kep-price-v">' . esc_html( $price ) . '</span></div>';
				$h .= '<a class="kep-cta" href="' . esc_attr( $tel ) . '">' . self::icon( 'phone' ) . 'Забронировать стол</a>';
				$h .= '<div class="kep-buy-row"><span class="kep-phone">' . esc_html( $phone ) . '</span>';
				$tour = KEP_Settings::tour( $ctx['code'] );
				if ( '' !== $tour ) {
					$h .= '<a class="kep-link" href="' . esc_url( $tour ) . '" target="_blank" rel="noopener">' . self::icon( 'cube' ) . 'Зал в 3D</a>';
				}
				$h .= '</div>';
				break;
			case 'free':
				$h .= '<div class="kep-price"><span class="kep-price-v">' . esc_html( '' !== $price ? $price : 'Вход свободный' ) . '</span></div>';
				if ( '' !== $d['calendar'] ) {
					$h .= '<a class="kep-cta kep-cta--ghost" href="' . esc_url( $d['calendar'] ) . '">' . self::icon( 'cal' ) . 'Добавить в календарь</a>';
				}
				break;
			case 'soon':
				$h .= '<div class="kep-price"><span class="kep-price-v kep-price-v--ink">' . esc_html( $price ) . '</span></div>';
				$h .= '<div class="kep-cta kep-cta--off" aria-disabled="true">Продажа скоро откроется</div>';
				$h .= '<p class="kep-buy-note">Вопросы по билетам: <span class="kep-phone">' . esc_html( $phone ) . '</span></p>';
				break;
			case 'past':
				$h .= '<p class="kep-past">Концерт прошёл</p>';
				$h .= '<a class="kep-cta kep-cta--ghost" href="' . esc_url( home_url( '/' ) ) . '">Ближайшие концерты</a>';
				break;
			default:
				if ( '' !== $price ) {
					$h .= '<div class="kep-price"><span class="kep-price-v kep-price-v--ink">' . esc_html( $price ) . '</span></div>';
				}
		}
		return $h;
	}

	/**
	 * Кнопка Ticketscloud: видимая кнопка и прозрачная кнопка виджета поверх неё,
	 * как это сделано в теме.
	 */
	private static function tc_button( $tc, $label, $id_class ) {
		$cls = trim( 'kep-tc ' . $tc['class'] );
		return '<div class="kep-cta-wrap ' . esc_attr( $id_class ) . '"><span class="kep-cta" aria-hidden="true">' . esc_html( $label ) . '</span>'
			. '<button type="button" class="' . esc_attr( $cls ) . '" tc-event="' . esc_attr( $tc['id'] ) . '">' . esc_html( $label ) . '</button></div>';
	}

	private static function intro( $d ) {
		if ( '' === $d['lead'] && ! $d['quote'] ) {
			return '';
		}
		$h = '<section class="kep-box kep-intro">';
		if ( '' !== $d['lead'] ) {
			$h .= '<p class="kep-lead">' . esc_html( $d['lead'] ) . '</p>';
		}
		if ( $d['quote'] ) {
			$h .= '<blockquote class="kep-quote">' . self::icon( 'quote' ) . '<p>' . esc_html( $d['quote']['text'] ) . '</p>';
			if ( '' !== $d['quote']['author'] ) {
				$h .= '<footer>' . esc_html( $d['quote']['author'] ) . '</footer>';
			}
			$h .= '</blockquote>';
		}
		return $h . '</section>';
	}

	private static function video_section( $videos ) {
		if ( ! $videos ) {
			return '';
		}
		$h = '<section class="kep-box kep-videos" id="kep-video" aria-labelledby="kep-video-h"><h2 class="kep-h2" id="kep-video-h">Видео</h2><div class="kep-rail">';
		foreach ( $videos as $i => $v ) {
			$n = $i + 1;
			if ( 'youtube' === $v['type'] ) {
				$h .= '<button type="button" class="kep-video" data-kep-embed="' . esc_attr( $v['src'] . '?autoplay=1&rel=0' ) . '" aria-label="' . esc_attr( 'Смотреть видео ' . $n ) . '">'
					. '<img src="' . esc_url( 'https://i.ytimg.com/vi/' . $v['id'] . '/hqdefault.jpg' ) . '" alt="" loading="lazy" decoding="async">'
					. '<span class="kep-video-play">' . self::icon( 'play' ) . '</span></button>';
			} else {
				$h .= '<div class="kep-video kep-video--frame"><iframe src="' . esc_url( $v['src'] ) . '" title="' . esc_attr( 'Видео ' . $n ) . '" loading="lazy" allow="autoplay; encrypted-media; fullscreen; picture-in-picture" allowfullscreen></iframe></div>';
			}
		}
		return $h . '</div></section>';
	}

	private static function lineup_section( $d ) {
		if ( ! $d['lineup'] ) {
			return '';
		}
		$h = '<section class="kep-box" aria-labelledby="kep-lineup-h"><h2 class="kep-h2" id="kep-lineup-h">На сцене</h2><ul class="kep-lineup">';
		foreach ( $d['lineup'] as $m ) {
			$photo = self::musician_photo( $d['musicians'], $m['name'] );
			$h    .= '<li>';
			if ( '' !== $photo ) {
				$h .= '<img class="kep-ava" src="' . esc_url( $photo ) . '" alt="" loading="lazy" decoding="async">';
			} else {
				$h .= '<span class="kep-ava" aria-hidden="true">' . esc_html( self::initials( $m['name'] ) ) . '</span>';
			}
			$h .= '<span class="kep-lineup-t"><span class="kep-lineup-n">' . esc_html( $m['name'] ) . '</span>';
			if ( '' !== $m['role'] ) {
				$h .= '<span class="kep-lineup-r">' . esc_html( $m['role'] ) . '</span>';
			}
			$h .= '</span></li>';
		}
		return $h . '</ul></section>';
	}

	private static function about_section( $d ) {
		$body = '';
		foreach ( $d['flow'] as $f ) {
			if ( 'p' === $f['t'] ) {
				$p = $d['paras'][ $f['i'] ];
				if ( 'text' !== $p['kind'] && ! ( 'lineup' === $p['kind'] && ! $d['lineup'] ) ) {
					continue;
				}
				$body .= ( 'p' === $p['tag'] ) ? '<p>' . wp_kses_post( $p['html'] ) . '</p>' : wp_kses_post( $p['html'] );
			} elseif ( 'm' === $f['t'] ) {
				$m     = $d['musicians'][ $f['i'] ];
				$body .= '<div class="kep-artist">';
				if ( '' !== $m['img'] ) {
					$body .= '<img class="kep-artist-img" src="' . esc_url( $m['img'] ) . '" alt="' . esc_attr( '' !== $m['name'] ? $m['name'] : $m['alt'] ) . '" loading="lazy" decoding="async">';
				}
				$body .= '<div class="kep-artist-t">' . wp_kses_post( $m['html'] ) . '</div></div>';
			} elseif ( 'x' === $f['t'] ) {
				$body .= '<div class="kep-extra">' . wp_kses_post( $d['extra'][ $f['i'] ] ) . '</div>';
			}
		}
		if ( '' === trim( wp_strip_all_tags( $body ) ) && false === strpos( $body, '<img' ) ) {
			return '';
		}
		return '<section class="kep-box kep-about" aria-labelledby="kep-about-h"><h2 class="kep-h2" id="kep-about-h">О концерте</h2>'
			. '<div class="kep-clamp" id="kep-about-body">' . $body . '</div>'
			. '<button type="button" class="kep-more-btn" aria-expanded="false" aria-controls="kep-about-body" hidden>Читать полностью</button></section>';
	}

	private static function where_section( $d, $ctx, $s, $time ) {
		$place = KEP_Settings::place( $ctx['code'] );
		$h     = '<section class="kep-box kep-where" aria-labelledby="kep-where-h"><h2 class="kep-h2" id="kep-where-h">Когда и где</h2><div class="kep-where-rows">';
		$h    .= '<div class="kep-where-row">' . self::icon( 'cal' ) . '<div><strong>' . esc_html( self::when_text( $d, $ctx, $time ) ) . '</strong></div></div>';
		$stage = $d['stage'];
		$line  = trim( $stage . ( '' !== $stage && '' !== $place['addr'] ? ', ' : '' ) . $place['addr'] );
		$h    .= '<div class="kep-where-row">' . self::icon( 'pin' ) . '<div><strong>' . esc_html( $line ) . '</strong>';
		if ( '' !== $place['metro'] ) {
			$h .= '<span>' . esc_html( $place['metro'] ) . '</span>';
		}
		$h .= '</div></div></div><div class="kep-where-btns">';
		if ( '' !== $place['addr'] ) {
			$h .= '<a class="kep-btn" href="' . esc_url( 'https://yandex.ru/maps/?text=' . rawurlencode( 'Москва, ' . $place['addr'] ) ) . '" target="_blank" rel="noopener">' . self::icon( 'route' ) . 'Маршрут</a>';
		}
		$tour = KEP_Settings::tour( $ctx['code'] );
		if ( '' !== $tour ) {
			$h .= '<a class="kep-btn" href="' . esc_url( $tour ) . '" target="_blank" rel="noopener">' . self::icon( 'cube' ) . 'Зал в 3D</a>';
		}
		if ( '' !== $d['calendar'] && 'past' !== $d['type'] ) {
			$h .= '<a class="kep-btn kep-only-mobile" href="' . esc_url( $d['calendar'] ) . '">' . self::icon( 'cal' ) . 'В календарь</a>';
		}
		$h .= '<button type="button" class="kep-btn kep-only-mobile" data-kep-share>' . self::icon( 'share' ) . 'Поделиться</button>';
		$h .= '</div>';
		$notes = array();
		foreach ( $d['paras'] as $p ) {
			if ( 'note' === $p['kind'] ) {
				$notes[] = $p['text'];
			}
		}
		if ( $notes ) {
			$h .= '<p class="kep-note">' . esc_html( implode( ' ', $notes ) ) . '</p>';
		}
		return $h . '</section>';
	}

	private static function more_section( $ctx ) {
		if ( empty( $ctx['more'] ) ) {
			return '';
		}
		$stages = KEP_Settings::stages();
		$h      = '<section class="kep-more" aria-labelledby="kep-more-h"><div class="kep-more-head"><h2 class="kep-h2" id="kep-more-h">Ещё в этот вечер</h2><a href="' . esc_url( home_url( '/' ) ) . '">Вся афиша</a></div><div class="kep-more-rail">';
		foreach ( $ctx['more'] as $e ) {
			$img   = KEP_Data::img_url( $e['img'] );
			$stage = isset( $stages[ $e['stage'] ] ) ? $stages[ $e['stage'] ] : '';
			$h    .= '<a class="kep-more-card" href="' . esc_url( KEP_Data::event_url( $e['slug'] ) ) . '">';
			$h    .= '' !== $img ? '<img src="' . esc_url( $img ) . '" alt="" loading="lazy" decoding="async">' : '<span class="kep-more-noimg"></span>';
			$h    .= '<span class="kep-more-when">' . esc_html( trim( self::show_time( $e['time'] ) . ( '' !== $stage ? ' · ' . $stage : '' ) ) ) . '</span>';
			$h    .= '<span class="kep-more-title">' . esc_html( $e['title'] ) . '</span>';
			$h    .= '<span class="kep-more-price">' . esc_html( self::price( $e['price'] ) ) . '</span></a>';
		}
		return $h . '</div></section>';
	}

	private static function bar( $d, $ctx, $s, $time ) {
		$price = self::price( $d['price'] );
		$sub   = self::short_date( $d, $ctx, $time );
		$left  = '<span class="kep-bar-t"><span class="kep-bar-p">' . esc_html( $price ) . '</span><span class="kep-bar-d">' . esc_html( $sub ) . '</span></span>';
		switch ( $d['type'] ) {
			case 'ticket':
				$right = self::tc_button( $d['tc'], 'Купить билет', 'kep-bar-cta' );
				break;
			case 'deposit':
				$right = '<a class="kep-cta kep-bar-cta" href="' . esc_attr( 'tel:' . preg_replace( '/[^\d+]/', '', $s['phone'] ) ) . '">Забронировать</a>';
				break;
			case 'free':
				if ( '' === $d['calendar'] ) {
					return '';
				}
				$left  = '<span class="kep-bar-t"><span class="kep-bar-p">' . esc_html( '' !== $price ? $price : 'Вход свободный' ) . '</span><span class="kep-bar-d">' . esc_html( $sub ) . '</span></span>';
				$right = '<a class="kep-cta kep-cta--ghost kep-bar-cta" href="' . esc_url( $d['calendar'] ) . '">В календарь</a>';
				break;
			default:
				return '';
		}
		return '<div class="kep-bar" aria-hidden="true">' . $left . $right . '</div>';
	}

	/* ---------- текст ---------- */

	public static function price( $raw ) {
		$p = KEP_Parser::clean( $raw );
		if ( '' === $p ) {
			return '';
		}
		$p = mb_strtolower( $p, 'UTF-8' );
		$p = preg_replace_callback(
			'/\d[\d\s]{2,}\d/u',
			function ( $m ) {
				$n = (int) preg_replace( '/\D/', '', $m[0] );
				return $n >= 1000 ? number_format( $n, 0, '', "\xC2\xA0" ) : $m[0];
			},
			$p
		);
		return mb_strtoupper( mb_substr( $p, 0, 1, 'UTF-8' ), 'UTF-8' ) . mb_substr( $p, 1, null, 'UTF-8' );
	}

	public static function program( $p ) {
		$p = trim( $p );
		if ( preg_match( '/^[«"“]/u', $p ) ) {
			return $p;
		}
		return '«' . $p . '»';
	}

	public static function initials( $name ) {
		$words = preg_split( '/\s+/u', trim( preg_replace( '/[«»"“”().,]/u', '', $name ) ) );
		$out   = '';
		foreach ( array_slice( $words, 0, 2 ) as $w ) {
			if ( '' !== $w ) {
				$out .= mb_strtoupper( mb_substr( $w, 0, 1, 'UTF-8' ), 'UTF-8' );
			}
		}
		return $out;
	}

	private static function musician_photo( $musicians, $name ) {
		$n = mb_strtolower( trim( $name ), 'UTF-8' );
		if ( '' === $n ) {
			return '';
		}
		foreach ( $musicians as $m ) {
			$mn = mb_strtolower( trim( $m['name'] ), 'UTF-8' );
			if ( '' !== $mn && '' !== $m['img'] && ( 0 === mb_strpos( $n, $mn, 0, 'UTF-8' ) || 0 === mb_strpos( $mn, $n, 0, 'UTF-8' ) ) ) {
				return $m['img'];
			}
		}
		return '';
	}

	public static function show_time( $t ) {
		return '23:59' === $t ? '00:00' : $t;
	}

	private static function row1( $row1, $time ) {
		if ( '' === $time ) {
			return $row1;
		}
		return preg_replace( '/\d{1,2}:\d{2}/', $time, $row1, 1 );
	}

	private static function when_text( $d, $ctx, $time ) {
		$ts = '' !== $ctx['date'] ? strtotime( $ctx['date'] . ' 12:00:00 UTC' ) : 0;
		if ( ! $ts ) {
			return trim( $d['weekday'] . ', ' . $d['day'] . ' ' . $d['row1'] );
		}
		$day   = (int) gmdate( 'j', $ts );
		$month = self::MONTHS_GEN[ (int) gmdate( 'n', $ts ) ];
		if ( '23:59' === $d['time'] ) {
			$next = $ts + DAY_IN_SECONDS;
			return sprintf( 'Ночь с %d на %d %s, 00:00', $day, (int) gmdate( 'j', $next ), self::MONTHS_GEN[ (int) gmdate( 'n', $next ) ] );
		}
		$wd = '' !== $d['weekday'] ? $d['weekday'] . ', ' : '';
		return $wd . $day . ' ' . $month . ( '' !== $time ? ', ' . $time : '' );
	}

	private static function short_date( $d, $ctx, $time ) {
		$wd = mb_strtolower( $d['weekday'], 'UTF-8' );
		$wd = isset( self::WD_SHORT[ $wd ] ) ? self::WD_SHORT[ $wd ] . ', ' : '';
		$ts = '' !== $ctx['date'] ? strtotime( $ctx['date'] . ' 12:00:00 UTC' ) : 0;
		$dm = $ts ? (int) gmdate( 'j', $ts ) . ' ' . self::MONTHS_SHORT[ (int) gmdate( 'n', $ts ) ] : $d['day'];
		return $wd . $dm . ( '' !== $time ? ' · ' . $time : '' );
	}

	/**
	 * Иконки: тонкие линии, цвет от текста.
	 */
	public static function icon( $name ) {
		$p = array(
			'back'  => '<path d="M15 18l-6-6 6-6"/>',
			'play'  => '<path d="M8 5l12 7-12 7z" fill="currentColor" stroke="none"/>',
			'cal'   => '<rect x="3.5" y="5" width="17" height="15" rx="1.5"/><path d="M3.5 10h17M8 3v4M16 3v4"/>',
			'share' => '<path d="M12 3v12M7 8l5-5 5 5M5 14v6h14v-6"/>',
			'phone' => '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/>',
			'cube'  => '<path d="M12 3l8 4.5v9L12 21l-8-4.5v-9z"/><path d="M4 7.5l8 4.5 8-4.5M12 12v9"/>',
			'pin'   => '<path d="M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/>',
			'route' => '<path d="M3 11l18-8-8 18-2-8z"/>',
			'quote' => '<path d="M4 18v-6c0-4 2-6.5 6-7l.6 2c-2.2.7-3.1 2-3.2 4H11v7zm9 0v-6c0-4 2-6.5 6-7l.6 2c-2.2.7-3.1 2-3.2 4H20v7z" fill="currentColor" stroke="none"/>',
		);
		if ( ! isset( $p[ $name ] ) ) {
			return '';
		}
		return '<svg class="kep-i kep-i--' . $name . '" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $p[ $name ] . '</svg>';
	}
}
