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
	const WD_BY_N = array( 1 => 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс' );

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

		$badge_date = 'past' === $d['type'] ? '' : $ctx['date'];
		$h          = '<div class="kep" id="kep" data-date="' . esc_attr( $badge_date ) . '" data-time="' . esc_attr( $d['time'] ) . '" data-type="' . esc_attr( $d['type'] ) . '">';
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
		$h .= '<span class="kep-r1">' . esc_html( self::row1( $d, $ctx, $time ) ) . '</span>';
		$h .= '<span class="kep-r2">' . esc_html( self::weekday_text( $d, $ctx ) ) . ' <span class="kep-badge" hidden></span></span>';
		if ( '' !== $d['stage'] ) {
			$map = KEP_Settings::map_url( $ctx['code'] );
			if ( '' !== $map && '' !== $ctx['code'] ) {
				$h .= '<a class="kep-r3" href="' . esc_url( $map ) . '" target="_blank" rel="noopener" title="Открыть на карте">' . esc_html( $d['stage'] ) . '</a>';
			} else {
				$h .= '<span class="kep-r3">' . esc_html( $d['stage'] ) . '</span>';
			}
		}
		$h .= '</span></div>';
		$h .= '<h1 class="kep-title">' . esc_html( $d['title'] ) . '</h1>';
		if ( '' !== $ctx['program'] ) {
			$h .= '<p class="kep-program">' . esc_html( self::program( $ctx['program'] ) ) . '</p>';
		}
		if ( $d['genres'] ) {
			$h .= '<ul class="kep-genres" aria-label="Жанры">';
			foreach ( $d['genres'] as $g ) {
				$h .= '<li><a href="' . esc_url( self::genre_url( $g['url'] ) ) . '">' . esc_html( $g['name'] ) . '</a></li>';
			}
			$h .= '</ul>';
		}
		$h .= '<div class="kep-buy" id="kep-buy">' . self::buy( $d, $ctx, $s, false ) . '</div>';
		$h .= self::notices( $d );
		$h .= '<div class="kep-card-links">';
		if ( '' !== $d['calendar'] && 'past' !== $d['type'] ) {
			$h .= '<a href="' . esc_url( $d['calendar'] ) . '">' . self::icon( 'cal' ) . 'В календарь</a>';
		}
		$h .= '<button type="button" data-kep-share>' . self::icon( 'share' ) . 'Поделиться</button>';
		$h .= '</div>';
		$h .= self::other_dates( $d, $ctx );
		$h .= '</aside>';

		// Основная колонка.
		$h .= '<div class="kep-main">';
		$h .= self::intro( $d );
		$h .= self::video_section( $videos );
		$h .= self::lineup_section( $d );
		$h .= self::artists_section( $d );
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
				$reg = self::is_register( $d );
				if ( $reg ) {
					$price = self::register_price( $price );
				}
				$h .= '<div class="kep-price"><span class="kep-price-v">' . esc_html( $price ) . '</span>';
				if ( '' !== $s['price_note'] && ! $reg ) { // «без сервисного сбора» у бесплатной регистрации ни к чему
					$h .= '<span class="kep-price-n">' . esc_html( $s['price_note'] ) . '</span>';
				}
				$h .= '</div>';
				$h .= self::tc_button( $d['tc'], self::tc_label( $d ), $compact ? '' : 'kep-buy-main' );
				break;
			case 'deposit':
			case 'free':
				$label = ( 'free' === $d['type'] && '' === $price ) ? 'Вход свободный' : $price;
				$h    .= '<div class="kep-price"><span class="kep-price-v">' . esc_html( $label ) . '</span></div>';
				$h    .= '<a class="kep-cta" href="' . esc_attr( $tel ) . '">' . self::icon( 'phone' ) . 'Забронировать стол</a>';
				$h    .= '<div class="kep-buy-row"><span class="kep-phone">' . esc_html( $phone ) . '</span>';
				$tour  = KEP_Settings::tour( $ctx['code'] );
				if ( '' !== $tour ) {
					$h .= '<a class="kep-link" href="' . esc_url( $tour ) . '" target="_blank" rel="noopener">' . self::icon( 'cube' ) . 'Зал в 3D</a>';
				}
				$h .= '</div>';
				if ( 'free' === $d['type'] && '' !== $d['calendar'] ) {
					$h .= '<a class="kep-link kep-link--cal" href="' . esc_url( $d['calendar'] ) . '">' . self::icon( 'cal' ) . 'Добавить в календарь</a>';
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
	private static function is_register( $d ) {
		return ! empty( $d['tc']['register'] );
	}

	private static function tc_label( $d ) {
		return self::is_register( $d ) ? 'Регистрация' : 'Купить билет';
	}

	/**
	 * Цена у события с регистрацией: пусто или одни нули («0», «0 ₽») показываются как «Вход свободный»,
	 * любой другой текст редактора остаётся как есть.
	 */
	private static function register_price( $price ) {
		return preg_match( '/[1-9]/', $price ) || preg_match( '/\p{L}{3,}/u', preg_replace( '/руб\w*|₽/iu', '', $price ) ) ? $price : 'Вход свободный';
	}

	private static function tc_button( $tc, $label, $id_class ) {
		$cls = trim( 'kep-tc ' . $tc['class'] );
		return '<div class="kep-cta-wrap ' . esc_attr( $id_class ) . '"><span class="kep-cta" aria-hidden="true">' . esc_html( $label ) . '</span>'
			. '<button type="button" class="' . esc_attr( $cls ) . '" tc-event="' . esc_attr( $tc['id'] ) . '">' . esc_html( $label ) . '</button></div>';
	}

	private static function intro( $d ) {
		if ( '' === $d['lead'] && ! $d['quotes'] ) {
			return '';
		}
		$h = '<section class="kep-box kep-intro">';
		if ( ! empty( $d['lead_rich'] ) ) {
			$h .= '<div class="kep-lead">' . wp_kses_post( $d['lead_html'] ) . '</div>'; // из поля «Коротко о концерте»
		} elseif ( '' !== $d['lead'] ) {
			$h .= '<p class="kep-lead">' . wp_kses_post( '' !== $d['lead_html'] ? $d['lead_html'] : $d['lead'] ) . '</p>';
		}
		foreach ( $d['quotes'] as $q ) {
			$rich = $q['html'] !== wp_strip_all_tags( $q['html'] );
			$h   .= '<blockquote class="kep-quote">' . self::icon( 'quote' ) . '<p>' . ( $rich ? wp_kses_post( $q['html'] ) : esc_html( $q['text'] ) ) . '</p>';
			if ( '' !== $q['author'] ) {
				$arich = $q['author_html'] !== wp_strip_all_tags( $q['author_html'] );
				$h    .= '<footer>' . ( $arich ? wp_kses_post( $q['author_html'] ) : esc_html( $q['author'] ) ) . '</footer>';
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

	/**
	 * «Важно знать»: адрес и вход, депозит, бронь. Сразу под кнопкой покупки, где решают, идти ли.
	 * Текст сцены из настроек (другой адрес) идёт первым: заголовок жирным, текст, ссылка на карту.
	 */
	private static function notices( $d ) {
		$place = isset( $d['place_note'] ) ? $d['place_note'] : null;
		if ( empty( $d['notices'] ) && ! $place ) {
			return '';
		}
		$h = '<div class="kep-notices' . ( $place ? ' kep-notices--place' : '' ) . '" role="note" aria-label="Важно знать"><span class="kep-notices-l">' . self::icon( 'info' ) . 'Важно знать</span>';
		if ( $place ) {
			$h .= '<div class="kep-place">' . self::icon( 'pin' ) . '<div class="kep-place-t">';
			if ( '' !== $place['title'] ) {
				$h .= '<strong class="kep-place-h">' . esc_html( $place['title'] ) . '</strong>';
			}
			if ( '' !== $place['text'] ) {
				$h .= '<span class="kep-place-x">' . wp_kses( $place['text'], KEP_Data::INLINE_TAGS ) . '</span>';
			}
			if ( '' !== $place['map'] ) {
				$h .= '<a class="kep-place-map" href="' . esc_url( $place['map'] ) . '" target="_blank" rel="noopener"><span class="kep-place-u">Открыть на карте</span><span class="kep-place-go" aria-hidden="true">→</span></a>';
			}
			$h .= '</div></div>';
		}
		if ( ! empty( $d['notices'] ) ) {
			$h .= '<ul class="kep-notices-ul">';
			foreach ( $d['notices'] as $n ) {
				$h .= '<li>' . wp_kses( $n, KEP_Data::INLINE_TAGS ) . '</li>';
			}
			$h .= '</ul>';
		}
		return $h . '</div>';
	}

	/**
	 * Даты этого концерта как маленький календарь: день недели, число, месяц.
	 * Текущая дата в ряду выделена, остальные ведут на свои страницы. Прошедшие не показываются.
	 */
	private static function other_dates( $d, $ctx ) {
		if ( empty( $d['other_dates'] ) ) {
			return '';
		}
		$today = strtotime( gmdate( 'Y-m-d', current_time( 'timestamp' ) ) . ' 12:00:00 UTC' );
		$items = array();
		foreach ( $d['other_dates'] as $i => $o ) {
			$slug = preg_match( '#/event/([^/?\#]+)#', $o['url'], $m ) ? rawurldecode( $m[1] ) : '';
			$e    = '' !== $slug ? KEP_Data::by_slug( $slug ) : null;
			$ts   = ( $e && '' !== $e['date'] ) ? strtotime( $e['date'] . ' 12:00:00 UTC' ) : self::label_ts( $o['label'] );
			if ( $ts && $ts < $today ) {
				continue;
			}
			$items[] = array(
				'url'  => $o['url'],
				'ts'   => $ts,
				'time' => ( $e && '' !== $e['time'] && $e['time'] !== $d['time'] ) ? self::show_time( $e['time'] ) : '',
				'text' => $o['label'],
				'cur'  => false,
				'sort' => $ts ? $ts : PHP_INT_MAX - 1000 + $i,
			);
		}
		if ( ! $items ) {
			return '';
		}
		$cur_ts = '' !== (string) $ctx['date'] ? strtotime( $ctx['date'] . ' 12:00:00 UTC' ) : 0;
		if ( $cur_ts ) {
			$items[] = array( 'url' => '', 'ts' => $cur_ts, 'time' => '', 'text' => '', 'cur' => true, 'sort' => $cur_ts );
		}
		usort(
			$items,
			function ( $a, $b ) {
				return $a['sort'] <=> $b['sort'];
			}
		);
		$h = '<nav class="kep-dates" aria-label="Даты этого концерта"><span class="kep-dates-l">' . ( $cur_ts ? 'Даты концерта' : 'Другие даты' ) . '</span><ul class="kep-dates-list">';
		foreach ( $items as $it ) {
			if ( $it['ts'] ) {
				$inner = '<span class="kep-date-w">' . esc_html( self::WD_BY_N[ (int) gmdate( 'N', $it['ts'] ) ] ) . '</span>'
					. '<span class="kep-date-d">' . (int) gmdate( 'j', $it['ts'] ) . '</span>'
					. '<span class="kep-date-m">' . esc_html( self::MONTHS_SHORT[ (int) gmdate( 'n', $it['ts'] ) ] . ( '' !== $it['time'] ? ' · ' . $it['time'] : '' ) ) . '</span>';
				$label = (int) gmdate( 'j', $it['ts'] ) . ' ' . self::MONTHS_GEN[ (int) gmdate( 'n', $it['ts'] ) ];
			} else {
				$inner = '<span class="kep-date-d kep-date-d--text">' . esc_html( $it['text'] ) . '</span>';
				$label = $it['text'];
			}
			if ( $it['cur'] ) {
				$h .= '<li><span class="kep-date is-cur" aria-current="date" title="' . esc_attr( 'Вы смотрите ' . $label ) . '">' . $inner . '</span></li>';
			} else {
				$h .= '<li><a class="kep-date" href="' . esc_url( $it['url'] ) . '" aria-label="' . esc_attr( $label . ( '' !== $it['time'] ? ', ' . $it['time'] : '' ) ) . '">' . $inner . '</a></li>';
			}
		}
		return $h . '</ul></nav>';
	}

	/**
	 * Дата из подписи темы «23 окт 2026».
	 */
	private static function label_ts( $label ) {
		if ( ! preg_match( '/(\d{1,2})\s+([а-яё]{3})[а-яё]*\.?\s+(\d{4})/ui', (string) $label, $m ) ) {
			return 0;
		}
		$mon = mb_strtolower( $m[2], 'UTF-8' );
		$mon = 'май' === $mon ? 'мая' : $mon;
		$n   = array_search( $mon, self::MONTHS_SHORT, true );
		return $n ? (int) strtotime( sprintf( '%04d-%02d-%02d 12:00:00 UTC', (int) $m[3], (int) $n, (int) $m[1] ) ) : 0;
	}

	/**
	 * Артисты со своими страницами (Kozlov Artists). Один: карточка. Несколько: список, первые три сразу.
	 */
	private static function artists_section( $d ) {
		$list = isset( $d['artists'] ) ? $d['artists'] : array();
		if ( ! $list ) {
			return '';
		}
		$one = 1 === count( $list );
		$h   = '<section class="kep-box kep-artists" aria-labelledby="kep-artists-h"><h2 class="kep-h2" id="kep-artists-h">' . ( $one ? 'Об артисте' : 'Артисты вечера' ) . '</h2>';
		if ( $one ) {
			$a  = $list[0];
			$h .= '<a class="kep-ac" href="' . esc_url( $a['url'] ) . '">' . self::artist_photo( $a, 'kep-ac-p' ) . '<span class="kep-ac-t"><span class="kep-ac-n">' . esc_html( $a['name'] ) . '</span>';
			if ( '' !== $a['text'] ) {
				$h .= '<span class="kep-ac-d">' . esc_html( $a['text'] ) . '</span>';
			}
			$h .= '<span class="kep-ac-more">' . esc_html( '' !== $a['more'] ? $a['more'] : 'Страница артиста' ) . ' <span aria-hidden="true">→</span></span></span></a>';
			return $h . '</section>';
		}
		$first = array_slice( $list, 0, 3 );
		$rest  = array_slice( $list, 3 );
		$h    .= self::artist_rows( $first );
		if ( $rest ) {
			$n  = count( $rest );
			$h .= '<details class="kep-ar-more"><summary>' . esc_html( 'Ещё ' . $n . ' ' . self::plural( $n, 'артист', 'артиста', 'артистов' ) ) . '</summary>' . self::artist_rows( $rest ) . '</details>';
		}
		return $h . '</section>';
	}

	private static function artist_rows( $list ) {
		$h = '<ul class="kep-ar">';
		foreach ( $list as $a ) {
			$h .= '<li><a class="kep-ar-a" href="' . esc_url( $a['url'] ) . '">' . self::artist_photo( $a, 'kep-ar-p' ) . '<span class="kep-ar-t"><span class="kep-ar-n">' . esc_html( $a['name'] ) . '</span>';
			if ( '' !== $a['text'] ) {
				$h .= '<span class="kep-ar-d">' . esc_html( $a['text'] ) . '</span>';
			}
			$h .= '</span><svg class="kep-i kep-ar-go" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M9 6l6 6-6 6"/></svg></a></li>';
		}
		return $h . '</ul>';
	}

	private static function artist_photo( $a, $cls ) {
		if ( '' !== $a['photo'] ) {
			return '<img class="' . esc_attr( $cls ) . '" src="' . esc_url( $a['photo'] ) . '" alt="" loading="lazy" decoding="async">';
		}
		return '<span class="' . esc_attr( $cls ) . ' kep-ava" aria-hidden="true">' . esc_html( self::initials( $a['name'] ) ) . '</span>';
	}

	public static function plural( $n, $one, $few, $many ) {
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

	private static function about_section( $d ) {
		$body = '';
		foreach ( $d['flow'] as $f ) {
			if ( 'p' === $f['t'] ) {
				$p = $d['paras'][ $f['i'] ];
				if ( 'text' !== $p['kind'] ) {
					continue; // первый абзац, состав списком и служебные строки выводятся в своих местах
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
		$addr  = self::same_street( $stage, $place['addr'] ) ? '' : $place['addr'];
		$line  = trim( $stage . ( '' !== $stage && '' !== $addr ? ', ' : '' ) . $addr );
		if ( '' !== $line ) { // сцены нет и адреса нет: строку с меткой не выводим
			$h .= '<div class="kep-where-row">' . self::icon( 'pin' ) . '<div><strong>' . esc_html( $line ) . '</strong>';
			if ( '' !== $place['metro'] ) {
				$h .= '<span>' . esc_html( $place['metro'] ) . '</span>';
			}
			$h .= '</div></div>';
		}
		$h .= '</div><div class="kep-where-btns">';
		$map = KEP_Settings::map_url( $ctx['code'] );
		if ( '' !== $map ) {
			$h .= '<a class="kep-btn" href="' . esc_url( $map ) . '" target="_blank" rel="noopener">' . self::icon( 'route' ) . 'Маршрут</a>';
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
				$notes[] = wp_kses_post( $p['html'] );
			}
		}
		if ( $notes ) {
			$h .= '<p class="kep-note">' . implode( ' ', $notes ) . '</p>';
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
			$h    .= '<span class="kep-more-when">' . esc_html( trim( self::show_time( $e['time'] ) . self::night_short( $e['time'], $e['date'] ) . ( '' !== $stage ? ' · ' . $stage : '' ) ) ) . '</span>';
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
				if ( self::is_register( $d ) ) {
					$left = '<span class="kep-bar-t"><span class="kep-bar-p">' . esc_html( self::register_price( $price ) ) . '</span><span class="kep-bar-d">' . esc_html( $sub ) . '</span></span>';
				}
				$right = self::tc_button( $d['tc'], self::tc_label( $d ), 'kep-bar-cta' );
				break;
			case 'deposit':
			case 'free':
				if ( 'free' === $d['type'] && '' === $price ) {
					$left = '<span class="kep-bar-t"><span class="kep-bar-p">Вход свободный</span><span class="kep-bar-d">' . esc_html( $sub ) . '</span></span>';
				}
				$right = '<a class="kep-cta kep-bar-cta" href="' . esc_attr( 'tel:' . preg_replace( '/[^\d+]/', '', $s['phone'] ) ) . '">Забронировать</a>';
				break;
			default:
				return '';
		}
		return '<div class="kep-bar" aria-hidden="true">' . $left . $right . '</div>';
	}

	/* ---------- текст ---------- */

	/**
	 * Адрес страницы жанра от WordPress. Тема пишет ?genre=jazz; если у таксономии
	 * есть красивые адреса (/genre/jazz/), берём их. Иначе оставляем как было.
	 */
	public static function genre_url( $url ) {
		static $tax = null;
		$q = array();
		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
		if ( 1 !== count( $q ) ) {
			return $url;
		}
		$var  = key( $q );
		$slug = sanitize_title( (string) current( $q ) );
		if ( null === $tax ) {
			$tax = array();
			foreach ( get_taxonomies( array(), 'objects' ) as $t ) {
				if ( ! empty( $t->query_var ) ) {
					$tax[ $t->query_var ] = $t->name;
				}
			}
		}
		if ( '' === $slug || ! isset( $tax[ $var ] ) ) {
			return $url;
		}
		$term = get_term_by( 'slug', $slug, $tax[ $var ] );
		if ( ! $term ) {
			return $url;
		}
		$link = get_term_link( $term );
		return is_wp_error( $link ) ? $url : $link;
	}

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

	private static function row1( $d, $ctx, $time ) {
		if ( '' === $time ) {
			return $d['row1'];
		}
		return preg_replace( '/\d{1,2}:\d{2}/', $time, $d['row1'], 1 );
	}

	const WD_ACC       = array( 1 => 'понедельник', 'вторник', 'среду', 'четверг', 'пятницу', 'субботу', 'воскресенье' );
	const WD_ACC_SHORT = array( 1 => 'пн', 'вт', 'ср', 'чт', 'пт', 'сб', 'вс' );

	/**
	 * «Пятница, в ночь на субботу» для ночного концерта (23:59 в афише), иначе день недели как есть.
	 */
	private static function weekday_text( $d, $ctx ) {
		$wd = trim( $d['weekday'] );
		if ( '23:59' !== $d['time'] ) {
			return $wd;
		}
		$next = self::next_day( $ctx['date'] );
		if ( ! $next ) {
			return $wd;
		}
		return ( '' !== $wd ? $wd . ', ' : '' ) . 'в ночь на ' . self::WD_ACC[ (int) gmdate( 'N', $next ) ];
	}

	/**
	 * «, ночь на сб» для ночного концерта, иначе пусто.
	 */
	private static function night_short( $time, $date ) {
		if ( '23:59' !== $time ) {
			return '';
		}
		$next = self::next_day( $date );
		return $next ? ', ночь на ' . self::WD_ACC_SHORT[ (int) gmdate( 'N', $next ) ] : '';
	}

	private static function next_day( $date ) {
		$ts = '' !== (string) $date ? strtotime( $date . ' 12:00:00 UTC' ) : 0;
		return $ts ? $ts + DAY_IN_SECONDS : 0;
	}

	/**
	 * Есть ли улица из адреса уже в названии сцены («Сцена на Мясницкой, 15»).
	 */
	public static function same_street( $stage, $addr ) {
		if ( '' === (string) $stage || '' === (string) $addr ) {
			return false;
		}
		return KEP_Parser::mentions_street( $stage, $addr );
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
			$wdn  = '' !== $d['weekday'] ? $d['weekday'] . ', ' : '';
			return sprintf( '%s%d %s, 00:00 (в ночь на %s %d %s)', $wdn, $day, $month, self::WD_ACC[ (int) gmdate( 'N', $next ) ], (int) gmdate( 'j', $next ), self::MONTHS_GEN[ (int) gmdate( 'n', $next ) ] );
		}
		$wd = '' !== $d['weekday'] ? $d['weekday'] . ', ' : '';
		return $wd . $day . ' ' . $month . ( '' !== $time ? ', ' . $time : '' );
	}

	private static function short_date( $d, $ctx, $time ) {
		$wd = mb_strtolower( $d['weekday'], 'UTF-8' );
		$wd = isset( self::WD_SHORT[ $wd ] ) ? self::WD_SHORT[ $wd ] . ', ' : '';
		$ts = '' !== $ctx['date'] ? strtotime( $ctx['date'] . ' 12:00:00 UTC' ) : 0;
		$dm = $ts ? (int) gmdate( 'j', $ts ) . ' ' . self::MONTHS_SHORT[ (int) gmdate( 'n', $ts ) ] : $d['day'];
		return $wd . $dm . ( '' !== $time ? ' · ' . $time : '' ) . self::night_short( $d['time'], $ctx['date'] );
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
			'info'  => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5.5M12 7.6v.1"/>',
			'quote' => '<path d="M4 18v-6c0-4 2-6.5 6-7l.6 2c-2.2.7-3.1 2-3.2 4H11v7zm9 0v-6c0-4 2-6.5 6-7l.6 2c-2.2.7-3.1 2-3.2 4H20v7z" fill="currentColor" stroke="none"/>',
		);
		if ( ! isset( $p[ $name ] ) ) {
			return '';
		}
		return '<svg class="kep-i kep-i--' . $name . '" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $p[ $name ] . '</svg>';
	}
}
