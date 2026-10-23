<?php
/**
 * Разбор готовой страницы концерта, которую выводит тема.
 * Плагин ничего не читает из базы анонса напрямую: берёт блоки из HTML темы
 * (цитата, текст, «о музыканте», видео, цена, кнопка Ticketscloud).
 * Если разметка не узнана, возвращает null, и остаётся старая страница.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KEP_Parser {

	const MONTHS = array(
		'янв' => 1, 'фев' => 2, 'мар' => 3, 'апр' => 4, 'мая' => 5, 'май' => 5, 'июн' => 6,
		'июл' => 7, 'авг' => 8, 'сен' => 9, 'окт' => 10, 'ноя' => 11, 'дек' => 12,
	);

	/**
	 * Найти в странице блок концерта (div.event_page_post_content) целиком.
	 *
	 * @param string $html Вся страница.
	 * @return array|null start, length, html
	 */
	public static function find_fragment( $html ) {
		if ( ! is_string( $html ) || '' === $html ) {
			return null;
		}
		if ( ! preg_match( '/<div\b[^>]*\bclass\s*=\s*["\'][^"\']*\bevent_page_post_content\b[^"\']*["\'][^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}
		return self::div_at( $html, $m[0][1], strlen( $m[0][0] ) );
	}

	/**
	 * Блок div целиком по месту его открывающего тега.
	 *
	 * @param string $html     Страница.
	 * @param int    $start    Где начинается <div ...>.
	 * @param int    $open_len Длина открывающего тега.
	 * @return array|null start, length, html
	 */
	public static function div_at( $html, $start, $open_len ) {
		$pos   = $start + $open_len;
		$depth = 1;
		while ( $depth > 0 && preg_match( '/<(\/?)div\b[^>]*>/i', $html, $t, PREG_OFFSET_CAPTURE, $pos ) ) {
			$depth += ( '/' === $t[1][0] ) ? -1 : 1;
			$pos    = $t[0][1] + strlen( $t[0][0] );
		}
		if ( 0 !== $depth ) {
			return null;
		}
		return array(
			'start'  => $start,
			'length' => $pos - $start,
			'html'   => substr( $html, $start, $pos - $start ),
		);
	}

	/**
	 * Разобрать блок концерта.
	 *
	 * @param string $fragment HTML блока.
	 * @return array|null
	 */
	public static function parse( $fragment ) {
		$prev = libxml_use_internal_errors( true );
		$doc  = new DOMDocument();
		$ok   = $doc->loadHTML( '<?xml encoding="utf-8" ?><html><body>' . $fragment . '</body></html>', LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		if ( ! $ok ) {
			return null;
		}
		$x    = new DOMXPath( $doc );
		$root = self::q1( $x, '//div[' . self::c( 'event_page_post_content' ) . ']' );
		if ( ! $root ) {
			return null;
		}
		$info = self::q1( $x, './/div[' . self::c( 'info' ) . ']', $root );
		$desc = self::q1( $x, './/div[' . self::c( 'description' ) . ']', $root );
		if ( ! $info || ! $desc ) {
			return null;
		}

		$d             = array();
		$d['upcoming'] = (bool) preg_match( '/(^|\s)upcoming(\s|$)/', $info->getAttribute( 'class' ) );
		$d['day']      = self::text( self::q1( $x, './/*[' . self::c( 'cell_1' ) . ']', $info ) );
		$d['row1']     = self::text( self::q1( $x, './/*[' . self::c( 'row_1' ) . ']', $info ) );
		$d['weekday']  = self::text( self::q1( $x, './/*[' . self::c( 'row_2' ) . ']', $info ) );
		$d['stage']    = self::text( self::q1( $x, './/*[' . self::c( 'row_3' ) . ']', $info ) );
		$d['title']    = self::text( self::q1( $x, './/*[' . self::c( 'title' ) . ']//h1', $info ) );
		$d['price']    = self::text( self::q1( $x, './/*[' . self::c( 'price' ) . ']', $info ) );
		$d['time']     = '';
		$d['month']    = 0;
		if ( preg_match( '/^\s*([^\s|]+)\s*\|\s*(\d{1,2}:\d{2})/u', $d['row1'], $m ) ) {
			$d['time']  = $m[2];
			$key        = self::lower( mb_substr( $m[1], 0, 3, 'UTF-8' ) );
			$d['month'] = isset( self::MONTHS[ $key ] ) ? self::MONTHS[ $key ] : 0;
		}
		if ( '' === $d['title'] || '' === $d['day'] ) {
			return null;
		}

		$btn = self::q1( $x, './/*[@tc-event]', $info );
		if ( ! $btn ) {
			$btn = self::q1( $x, './/*[@tc-event]', $root );
		}
		$d['tc'] = ( $btn && '' !== trim( $btn->getAttribute( 'tc-event' ) ) ) ? array(
			'id'    => trim( $btn->getAttribute( 'tc-event' ) ),
			'class' => trim( $btn->getAttribute( 'class' ) ),
		) : null;

		$cal           = self::q1( $x, './/*[' . self::c( 'add_to_calendar' ) . ']//a[@href]', $root );
		$d['calendar'] = $cal ? $cal->getAttribute( 'href' ) : '';

		$d['genres'] = array();
		foreach ( $x->query( './/*[' . self::c( 'genres-categories' ) . ']//a[@href]', $root ) as $a ) {
			$name = self::text( $a );
			if ( '' !== $name ) {
				$d['genres'][] = array( 'name' => $name, 'url' => $a->getAttribute( 'href' ) );
			}
		}

		// Другие даты этого же концерта (блок под шапкой анонса).
		$d['other_dates'] = array();
		foreach ( $x->query( './/*[' . self::c( 'event-other-dates-list' ) . ']//a[@href]', $root ) as $a ) {
			$label = self::text( $a );
			if ( '' !== $label ) {
				$d['other_dates'][] = array( 'url' => $a->getAttribute( 'href' ), 'label' => $label );
			}
		}

		// Незнакомые блоки в шапке анонса: показать их новая страница не умеет, значит, остаётся старая.
		$d['unknown'] = array();
		if ( $x->query( './/*[' . self::c( 'event-other-dates-list' ) . ']//a[@href]', $root )->length !== count( $d['other_dates'] ) ) {
			$d['unknown'][] = 'ссылка без текста в «Других датах»';
		}
		foreach ( $root->childNodes as $el ) {
			if ( $el instanceof DOMElement && ! self::has_class( $el, array( 'concert_nav', 'intro', 'description' ) ) && self::has_content( $x, $el ) ) {
				$d['unknown'][] = self::label( $el );
			}
		}
		$intro = self::q1( $x, './/div[' . self::c( 'intro' ) . ']', $root );
		if ( $intro ) {
			foreach ( $intro->childNodes as $el ) {
				if ( $el instanceof DOMElement && ! self::has_class( $el, array( 'info', 'row-block', 'event-other-dates-list', 'anons_thumb' ) ) && self::has_content( $x, $el ) ) {
					$d['unknown'][] = self::label( $el );
				}
			}
		}
		foreach ( $x->query( './/div[' . self::c( 'row-block' ) . ']', $root ) as $row ) {
			$known = $x->query( './/*[' . self::c( 'add_to_calendar' ) . ']//a[@href] | .//*[' . self::c( 'genres-categories' ) . ']//a[@href]', $row )->length;
			if ( $x->query( './/a[@href]', $row )->length > $known ) {
				$d['unknown'][] = 'ссылка в строке «Добавить в календарь / Жанры»';
			}
		}

		$banner      = self::q1( $x, './/*[' . self::c( 'anons_thumb' ) . ']//img', $root );
		$d['banner'] = $banner ? array(
			'src' => self::img_src( $banner ),
			'alt' => $banner->getAttribute( 'alt' ),
		) : null;

		$d['quote']     = null;
		$d['quotes']    = array();
		$d['paras']     = array();
		$d['musicians'] = array();
		$d['videos']    = array();
		$d['extra']     = array();
		$d['artists']   = array();
		$d['flow']      = array();
		$d['bad']       = array();
		$d['orig_len']  = 0;
		$d['kept_len']  = 0;
		$d['orig_img']  = 0;
		$d['kept_img']  = 0;
		$d['orig_link'] = 0;
		$d['kept_link'] = 0;
		$seen_artist    = false;

		foreach ( $desc->childNodes as $block ) {
			if ( $block instanceof DOMText ) {
				$d['orig_len'] += self::len( $block->textContent );
				continue;
			}
			if ( ! ( $block instanceof DOMElement ) ) {
				continue;
			}
			if ( in_array( strtolower( $block->tagName ), array( 'style', 'script', 'template', 'noscript' ), true ) ) {
				continue; // стили и скрипты других плагинов: не содержимое анонса
			}
			$cls = ' ' . preg_replace( '/\s+/', ' ', $block->getAttribute( 'class' ) ) . ' ';
			if ( preg_match( '/ (bottom_block|concert_nav|buy_button|share_panel) /', $cls ) ) {
				continue;
			}
			// Всё остальное считается содержимым анонса, которое нельзя потерять:
			// текст, картинки и ссылки считаются до разбора и после.
			$d['orig_len']  += self::len( $block->textContent );
			$d['orig_img']  += $x->query( './/img', $block )->length;
			$d['orig_link'] += $x->query( './/a[@href]', $block )->length;
			// Блок «Об артисте» из плагина Kozlov Artists: один или несколько артистов со ссылками на их страницы.
			if ( false !== strpos( $cls, ' ka-eb ' ) ) {
				$found = self::ka_artists( $x, $block );
				if ( $found ) {
					foreach ( $found as $a ) {
						$d['artists'][ $a['url'] ] = $a;
					}
					$d['kept_len']  += self::len( $block->textContent );
					$d['kept_img']  += $x->query( './/img', $block )->length;
					$d['kept_link'] += $x->query( './/a[@href]', $block )->length;
				}
				continue;
			}
			if ( false === strpos( $cls, ' meta_block ' ) ) {
				continue;
			}
			// Видео из любого блока. Встраивание, которое не видео (карта, виджет), не переносим.
			foreach ( $x->query( './/iframe', $block ) as $f ) {
				$src = $f->getAttribute( 'src' ) ? $f->getAttribute( 'src' ) : $f->getAttribute( 'data-src' );
				$v   = self::video( $src );
				if ( $v ) {
					$d['videos'][] = $v;
				} else {
					$host       = wp_parse_url( (string) $src, PHP_URL_HOST );
					$d['bad'][] = $host ? $host : 'встроенный блок';
				}
			}
			$iframes = array();
			foreach ( $x->query( './/iframe', $block ) as $f ) {
				$iframes[] = $f;
			}
			foreach ( $iframes as $f ) {
				$f->parentNode->removeChild( $f );
			}

			if ( false !== strpos( $cls, ' quote ' ) ) {
				$desc_el   = self::q1( $x, './/*[' . self::c( 'desc' ) . ']', $block );
				$author_el = self::q1( $x, './/*[' . self::c( 'author' ) . ']', $block );
				$text      = self::text( $desc_el );
				if ( mb_strlen( $text, 'UTF-8' ) > 3 ) {
					$q = array(
						'text'        => self::utrim( $text, '\s«»"“”„' ),
						'html'        => $desc_el ? self::inner( $desc_el ) : '',
						'author'      => self::text( $author_el ),
						'author_html' => $author_el ? self::inner( $author_el ) : '',
					);
					$d['quotes'][] = $q;
					if ( ! $d['quote'] ) {
						$d['quote'] = $q;
					}
					$d['kept_len']  += self::len( $block->textContent );
					$d['kept_img']  += self::count_img( $q['html'] . $q['author_html'] );
					$d['kept_link'] += self::count_link( $q['html'] . $q['author_html'] );
				}
				continue;
			}
			if ( false !== strpos( $cls, ' artist_desc ' ) ) {
				$seen_artist = true;
				$img         = self::q1( $x, './/*[' . self::c( 'img' ) . ']//img', $block );
				$text_el     = self::q1( $x, './/*[' . self::c( 'text' ) . ']', $block );
				$html        = $text_el ? self::inner( $text_el ) : '';
				$plain       = $text_el ? self::text( $text_el ) : '';
				if ( '' === $plain && ! $img ) {
					continue;
				}
				$d['kept_len']   += self::len( $plain );
				$d['kept_img']   += ( $img ? 1 : 0 ) + self::count_img( $html );
				$d['kept_link']  += self::count_link( $html );
				$d['flow'][]      = array( 't' => 'm', 'i' => count( $d['musicians'] ) );
				$d['musicians'][] = array(
					'img'  => $img ? self::img_src( $img ) : '',
					'alt'  => $img ? $img->getAttribute( 'alt' ) : '',
					'name' => self::artist_name( $x, $text_el, $plain ),
					'html' => $html,
					'text' => $plain,
				);
				continue;
			}
			if ( false !== strpos( $cls, ' textblock ' ) ) {
				foreach ( self::paragraphs( $block ) as $p ) {
					$p['before_artist'] = ! $seen_artist;
					$p['kind']          = self::kind( $p['text'] );
					if ( 'lineup' === $p['kind'] && ( self::count_link( $p['html'] ) || self::count_img( $p['html'] ) ) ) {
						$p['kind'] = 'text'; // со ссылками и картинками выводим как есть, чтобы ничего не потерять
					}
					$d['kept_len']     += self::len( $p['text'] );
					$d['kept_img']     += self::count_img( $p['html'] );
					$d['kept_link']    += self::count_link( $p['html'] );
					$d['flow'][]        = array( 't' => 'p', 'i' => count( $d['paras'] ) );
					$d['paras'][]       = $p;
				}
				continue;
			}
			if ( false !== strpos( $cls, ' youtube ' ) ) {
				continue;
			}
			$plain = self::text( $block );
			$inner = self::inner( $block );
			if ( '' !== $plain || self::count_img( $inner ) ) {
				$d['kept_len']  += self::len( $plain );
				$d['kept_img']  += self::count_img( $inner );
				$d['kept_link'] += self::count_link( $inner );
				$d['flow'][]     = array( 't' => 'x', 'i' => count( $d['extra'] ) );
				$d['extra'][]    = $inner;
			}
		}

		// Короткое описание: первый обычный абзац до блоков о музыкантах.
		// Абзац с двоеточием в конце не годится: без следующего за ним текста он обрывается.
		$d['lead']      = '';
		$d['lead_html'] = '';
		foreach ( $d['paras'] as $i => $p ) {
			$len = mb_strlen( $p['text'], 'UTF-8' );
			if ( 'text' === $p['kind'] && 'p' === $p['tag'] && $p['before_artist'] && $len >= 30 && $len <= 420 && ! self::ends_colon( $p['text'] ) ) {
				$d['lead']                  = $p['text'];
				$d['lead_html']             = $p['html'];
				$d['paras'][ $i ]['kind'] = 'lead';
				break;
			}
		}

		// Состав из строки «На сцену выйдут: …».
		$d['lineup'] = array();
		foreach ( $d['paras'] as $i => $p ) {
			if ( 'lineup' !== $p['kind'] ) {
				continue;
			}
			$list = $d['lineup'] ? array() : self::lineup( $p['text'] );
			if ( $list ) {
				$d['lineup']              = $list;
				$d['paras'][ $i ]['kind'] = 'lineup_used';
			} else {
				$d['paras'][ $i ]['kind'] = 'text';
			}
		}

		$d['artists'] = array_values( $d['artists'] );
		$d['videos']  = self::unique_videos( $d['videos'] );
		$d['type']    = self::cta_type( $d );
		return $d;
	}

	/**
	 * Артисты из блока Kozlov Artists (aside.ka-eb): ссылка, имя, фото, короткое описание.
	 *
	 * @param DOMXPath   $x     XPath.
	 * @param DOMElement $block Блок.
	 * @return array
	 */
	private static function ka_artists( $x, $block ) {
		$out   = array();
		$items = $x->query( './/*[' . self::c( 'ka-eb__i' ) . ']', $block );
		if ( ! $items->length ) {
			$items = array( $block );
		}
		foreach ( $items as $it ) {
			$name_a = self::q1( $x, './/a[' . self::c( 'ka-eb__n' ) . '][@href]', $it );
			if ( ! $name_a ) {
				continue;
			}
			$url  = $name_a->getAttribute( 'href' );
			$name = self::text( $name_a );
			if ( '' === $url || '' === $name ) {
				continue;
			}
			$photo = '';
			$p     = self::q1( $x, './/*[' . self::c( 'ka-eb__p' ) . ']', $it );
			if ( $p && preg_match( '/url\(\s*([\'"]?)(.+?)\1\s*\)/', $p->getAttribute( 'style' ), $m ) ) {
				$photo = html_entity_decode( $m[2], ENT_QUOTES, 'UTF-8' );
			}
			if ( '' === $photo ) {
				$img   = self::q1( $x, './/img', $it );
				$photo = $img ? self::img_src( $img ) : '';
			}
			$more  = self::q1( $x, './/a[' . self::c( 'ka-eb__a' ) . ']', $it );
			$out[] = array(
				'url'   => $url,
				'name'  => $name,
				'photo' => $photo,
				'text'  => self::text( self::q1( $x, './/*[' . self::c( 'ka-eb__t' ) . ']', $it ) ),
				'more'  => $more ? self::text( $more ) : '',
			);
		}
		return $out;
	}

	private static function has_class( $el, $names ) {
		$cls = ' ' . preg_replace( '/\s+/', ' ', $el->getAttribute( 'class' ) ) . ' ';
		foreach ( $names as $n ) {
			if ( false !== strpos( $cls, ' ' . $n . ' ' ) ) {
				return true;
			}
		}
		return false;
	}

	private static function has_content( $x, $el ) {
		return '' !== self::text( $el ) || $x->query( './/img | .//a[@href] | .//iframe', $el )->length > 0;
	}

	private static function label( $el ) {
		$cls = trim( $el->getAttribute( 'class' ) );
		return 'блок ' . ( '' !== $cls ? '«' . $cls . '»' : $el->tagName ) . ' в шапке анонса';
	}

	/**
	 * Тип кнопки: ticket, deposit, free, soon, past, none.
	 *
	 * @param array $d Данные.
	 * @return string
	 */
	public static function cta_type( $d ) {
		if ( empty( $d['upcoming'] ) ) {
			return 'past';
		}
		if ( ! empty( $d['tc'] ) ) {
			return 'ticket';
		}
		$p = self::lower( $d['price'] );
		if ( false !== mb_strpos( $p, 'депозит', 0, 'UTF-8' ) ) {
			return 'deposit';
		}
		if ( false !== mb_strpos( $p, 'свободн', 0, 'UTF-8' ) || false !== mb_strpos( $p, 'бесплат', 0, 'UTF-8' ) ) {
			return 'free';
		}
		if ( preg_match( '/\d/', $p ) ) {
			return 'soon';
		}
		return 'none';
	}

	/**
	 * Вид абзаца: lineup, note (служебная строка), text.
	 *
	 * @param string $text Текст.
	 * @return string
	 */
	public static function kind( $text ) {
		if ( preg_match( '/(В анонсе использован|^\s*Фото( для анонса)?\s*:|оставляет за собой право|Внимание!\s*Состав|Состав коллектива может быть изм|Состав (может|могут) быть изм)/iu', $text ) ) {
			return 'note';
		}
		// Практические сноски (адрес, вход, депозит, бронь): на новой странице они у кнопки покупки, а не первым абзацем.
		// Абзац с двоеточием в конце вводит то, что идёт следом («…один из двух сетов:»), поэтому остаётся в тексте.
		if ( mb_strlen( $text, 'UTF-8' ) <= 320 && ! self::ends_colon( $text ) && (
			preg_match( '/^\s*(Внимание|Обратите внимание|Важно)\s*[!:.]/iu', $text )
			|| preg_match( '/(депозит|бронирован|забронир|вход через|находится по адресу|дресс-код)/iu', $text )
		) ) {
			return 'notice';
		}
		if ( preg_match( '/(выйд[уеё]т|выступ[ая]т)[^:.]{0,40}:/iu', $text ) || preg_match( '/^\s*(В составе|Состав)\s*:/iu', $text ) ) {
			return 'lineup';
		}
		return 'text';
	}

	/**
	 * Абзац заканчивается двоеточием: он подводит к следующему тексту (списку, сетам, составу).
	 *
	 * @param string $text Текст.
	 * @return bool
	 */
	public static function ends_colon( $text ) {
		return (bool) preg_match( '/:[\s\x{00A0}]*$/u', (string) $text );
	}

	/**
	 * Состав: «Имя (инструменты)» через запятую или «и».
	 *
	 * @param string $text Строка состава.
	 * @return array Список name, role.
	 */
	public static function lineup( $text ) {
		$p = mb_strpos( $text, ':', 0, 'UTF-8' );
		if ( false === $p ) {
			return array();
		}
		$s     = rtrim( trim( mb_substr( $text, $p + 1, null, 'UTF-8' ) ), ". \t" );
		$chars = preg_split( '//u', $s, -1, PREG_SPLIT_NO_EMPTY );
		$items = array();
		$buf   = '';
		$depth = 0;
		$n     = count( $chars );
		for ( $i = 0; $i < $n; $i++ ) {
			$ch = $chars[ $i ];
			if ( '(' === $ch ) {
				$depth++;
			} elseif ( ')' === $ch ) {
				$depth = max( 0, $depth - 1 );
			}
			if ( 0 === $depth && ( ',' === $ch || ';' === $ch ) ) {
				$items[] = $buf;
				$buf     = '';
				continue;
			}
			if ( 0 === $depth && ' ' === $ch && $i + 2 < $n && 'и' === $chars[ $i + 1 ] && ' ' === $chars[ $i + 2 ] ) {
				$items[] = $buf;
				$buf     = '';
				$i      += 2;
				continue;
			}
			$buf .= $ch;
		}
		$items[] = $buf;
		$out     = array();
		$roles   = 0;
		foreach ( $items as $it ) {
			$it = trim( $it );
			if ( '' === $it ) {
				continue;
			}
			if ( preg_match( '/^(.+?)\s*\(([^)]*)\)\s*$/u', $it, $m ) ) {
				$out[] = array( 'name' => trim( $m[1] ), 'role' => trim( $m[2] ) );
				$roles++;
			} else {
				$out[] = array( 'name' => $it, 'role' => '' );
			}
		}
		if ( 0 === $roles || count( $out ) > 20 ) {
			return array();
		}
		return $out;
	}

	/**
	 * Видео по адресу iframe: YouTube, RuTube, VK.
	 *
	 * @param string $src Адрес.
	 * @return array|null type, id, src
	 */
	public static function video( $src ) {
		$src = trim( (string) $src );
		if ( '' === $src ) {
			return null;
		}
		if ( 0 === strpos( $src, '//' ) ) {
			$src = 'https:' . $src;
		}
		if ( preg_match( '#(?:youtube(?:-nocookie)?\.com/(?:embed|shorts)/|youtu\.be/)([A-Za-z0-9_-]{11})#', $src, $m ) ) {
			return array( 'type' => 'youtube', 'id' => $m[1], 'src' => 'https://www.youtube.com/embed/' . $m[1] );
		}
		if ( preg_match( '#rutube\.ru/(?:play/embed|video|embed)/([A-Za-z0-9]+)#', $src, $m ) ) {
			return array( 'type' => 'rutube', 'id' => $m[1], 'src' => 'https://rutube.ru/play/embed/' . $m[1] );
		}
		if ( preg_match( '#^https://(?:vk\.com|vk\.ru|vkvideo\.ru)/video_ext\.php\?#', $src ) ) {
			return array( 'type' => 'vk', 'id' => md5( $src ), 'src' => $src );
		}
		return null;
	}

	/**
	 * Без повторов.
	 *
	 * @param array $videos Видео.
	 * @return array
	 */
	private static function unique_videos( $videos ) {
		$out  = array();
		$seen = array();
		foreach ( $videos as $v ) {
			$k = $v['type'] . ':' . $v['id'];
			if ( isset( $seen[ $k ] ) ) {
				continue;
			}
			$seen[ $k ] = true;
			$out[]      = $v;
		}
		return $out;
	}

	/**
	 * Абзацы текстового блока.
	 *
	 * @param DOMElement $block Блок.
	 * @return array html, text, tag
	 */
	private static function paragraphs( $block ) {
		$out    = array();
		$buffer = '';
		$flush  = function () use ( &$buffer, &$out ) {
			$text = self::clean( wp_strip_all_tags( $buffer ) );
			if ( '' !== $text || false !== stripos( $buffer, '<img' ) ) {
				$out[] = array( 'html' => trim( $buffer ), 'text' => $text, 'tag' => 'p' );
			}
			$buffer = '';
		};
		foreach ( $block->childNodes as $n ) {
			if ( $n instanceof DOMElement && in_array( strtolower( $n->nodeName ), array( 'p', 'div', 'ul', 'ol', 'h2', 'h3', 'h4', 'blockquote' ), true ) ) {
				$flush();
				$tag  = strtolower( $n->nodeName );
				$text = self::text( $n );
				if ( '' === $text && ! $n->getElementsByTagName( 'img' )->length ) {
					continue;
				}
				if ( 'div' === $tag || 'p' === $tag || 'blockquote' === $tag ) {
					$out[] = array( 'html' => self::inner( $n ), 'text' => $text, 'tag' => 'p' );
				} else {
					$out[] = array( 'html' => $n->ownerDocument->saveHTML( $n ), 'text' => $text, 'tag' => $tag );
				}
				continue;
			}
			if ( $n instanceof DOMElement && 'br' === strtolower( $n->nodeName ) ) {
				$buffer .= '<br>';
				continue;
			}
			$buffer .= $n->ownerDocument->saveHTML( $n );
		}
		$flush();
		return $out;
	}

	/**
	 * Имя музыканта: жирное начало текста или «Имя Фамилия – …».
	 *
	 * @param DOMXPath        $x       XPath.
	 * @param DOMElement|null $text_el Текст.
	 * @param string          $plain   Текст без тегов.
	 * @return string
	 */
	private static function artist_name( $x, $text_el, $plain ) {
		if ( $text_el ) {
			$strong = self::q1( $x, './/strong|.//b', $text_el );
			if ( $strong ) {
				$name = self::text( $strong );
				if ( '' !== $name && 0 === mb_strpos( $plain, $name, 0, 'UTF-8' ) && mb_strlen( $name, 'UTF-8' ) <= 70 ) {
					return self::utrim( $name, '\s,:–—\-' );
				}
			}
		}
		if ( preg_match( '/^([^–—\-.,:]{3,70}?)\s[–—-]\s/u', $plain, $m ) ) {
			return trim( $m[1] );
		}
		return '';
	}

	/**
	 * Адрес картинки (учитывает ленивую загрузку).
	 *
	 * @param DOMElement $img Картинка.
	 * @return string
	 */
	private static function img_src( $img ) {
		foreach ( array( 'data-src', 'data-lazy-src', 'src' ) as $attr ) {
			$v = trim( $img->getAttribute( $attr ) );
			if ( '' !== $v && false === strpos( $v, 'lazy_placeholder' ) && 0 !== strpos( $v, 'data:' ) ) {
				return $v;
			}
		}
		return '';
	}

	/**
	 * Дата концерта Y-m-d по дню и месяцу (год выбирается ближайший).
	 *
	 * @param int  $day      День.
	 * @param int  $month    Месяц.
	 * @param bool $upcoming Концерт впереди.
	 * @param int  $now      Сейчас (timestamp).
	 * @return string
	 */
	public static function infer_date( $day, $month, $upcoming, $now ) {
		$day   = (int) $day;
		$month = (int) $month;
		if ( $day < 1 || $month < 1 ) {
			return '';
		}
		$year = (int) gmdate( 'Y', $now );
		$ts   = gmmktime( 12, 0, 0, $month, $day, $year );
		if ( $upcoming && $ts < $now - 2 * DAY_IN_SECONDS ) {
			$year++;
		} elseif ( ! $upcoming && $ts > $now + DAY_IN_SECONDS ) {
			$year--;
		}
		return sprintf( '%04d-%02d-%02d', $year, $month, $day );
	}

	/* ---------- помощники ---------- */

	private static function c( $class ) {
		return 'contains(concat(" ", normalize-space(@class), " "), " ' . $class . ' ")';
	}

	private static function q1( $x, $query, $context = null ) {
		$r = $context ? $x->query( $query, $context ) : $x->query( $query );
		return ( $r && $r->length ) ? $r->item( 0 ) : null;
	}

	private static function text( $node ) {
		return $node ? self::clean( $node->textContent ) : '';
	}

	public static function clean( $s ) {
		$s = html_entity_decode( (string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$s = preg_replace( '/[\x{00AD}\x{200B}-\x{200D}\x{FEFF}]/u', '', $s );
		return trim( preg_replace( '/\s+/u', ' ', $s ) );
	}

	private static function inner( $node ) {
		$html = '';
		foreach ( $node->childNodes as $child ) {
			$html .= $node->ownerDocument->saveHTML( $child );
		}
		return trim( $html );
	}

	/**
	 * Обрезка по краям с поддержкой UTF-8 (обычный trim портит кириллицу).
	 *
	 * @param string $s     Строка.
	 * @param string $chars Класс символов для регулярного выражения.
	 * @return string
	 */
	public static function utrim( $s, $chars ) {
		return (string) preg_replace( '/^[' . $chars . ']+|[' . $chars . ']+$/u', '', (string) $s );
	}

	/**
	 * Текст для сравнения «то же самое или нет»: только буквы и цифры в нижнем регистре.
	 */
	public static function norm( $s ) {
		$s = html_entity_decode( wp_strip_all_tags( (string) $s ), ENT_QUOTES, 'UTF-8' );
		return (string) preg_replace( '/[^\p{L}\p{N}]+/u', '', mb_strtolower( $s, 'UTF-8' ) );
	}

	/**
	 * Слова текста (буквы и цифры, нижний регистр) без повторов.
	 */
	public static function words( $s ) {
		$s = mb_strtolower( html_entity_decode( wp_strip_all_tags( (string) $s ), ENT_QUOTES, 'UTF-8' ), 'UTF-8' );
		preg_match_all( '/[\p{L}\p{N}]+/u', $s, $m );
		return array_values( array_unique( $m[0] ) );
	}

	/**
	 * Одно и то же сказано разными словами? Доля слов короткого текста, которые есть в длинном.
	 */
	public static function same_text( $a, $b, $min = 0.8 ) {
		$wa = self::words( $a );
		$wb = self::words( $b );
		$n  = min( count( $wa ), count( $wb ) );
		if ( $n < 3 ) {
			return '' !== self::norm( $a ) && self::norm( $a ) === self::norm( $b );
		}
		return count( array_intersect( $wa, $wb ) ) / $n >= $min;
	}

	/**
	 * Улица из адреса как начало слова: «ул. Мясницкая, 15» → «мясниц». Пусто, если улицы не найти.
	 */
	public static function street_stem( $addr ) {
		if ( ! preg_match( '/([А-Яа-яЁё]{5,})/u', (string) preg_replace( '/^\s*(ул\.|улица)\s*/u', '', (string) $addr ), $m ) ) {
			return '';
		}
		return mb_substr( mb_strtolower( $m[1], 'UTF-8' ), 0, 6, 'UTF-8' );
	}

	/**
	 * Есть ли в тексте улица из адреса.
	 */
	public static function mentions_street( $text, $addr ) {
		$stem = self::street_stem( $addr );
		if ( '' === $stem ) {
			return false;
		}
		$low = mb_strtolower( html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES, 'UTF-8' ), 'UTF-8' );
		return false !== mb_strpos( $low, $stem, 0, 'UTF-8' );
	}

	/** Слова-связки сносок, которые не несут сведений: на них «новое» в сноске не считается. */
	const FILLER = array( 'внимание', 'внимания', 'обратите', 'важно', 'концерт', 'концерты', 'проходит', 'пройдет', 'пройдёт', 'состоится', 'находится', 'расположена', 'расположен', 'сцена', 'сцене', 'сцену', 'адрес', 'адресу', 'клуб', 'клуба' );

	/**
	 * Основы значимых слов: без связок из FILLER и коротких слов из букв («на», «по», «ул»),
	 * окончания отрезаны («Мясницкая» и «Мясницкой» → «мясницк»). Цифры остаются целиком.
	 *
	 * @param string $s Текст.
	 * @return string[]
	 */
	public static function stems( $s ) {
		$out = array();
		foreach ( self::words( $s ) as $w ) {
			$n = mb_strlen( $w, 'UTF-8' );
			if ( in_array( $w, self::FILLER, true ) || ( $n <= 2 && ! preg_match( '/\d/u', $w ) ) ) {
				continue;
			}
			$out[] = $n <= 4 ? $w : mb_substr( $w, 0, max( 4, $n - 2 ), 'UTF-8' );
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Сказано ли в сноске только то, что уже есть в другом тексте. Новых значимых слов в ней
	 * не больше одного и не больше четверти: так «вход через паб Lions Head» совпадает с «вход через
	 * Lions Head Pub», а сноска с дополнительной инструкцией («вход со двора через арку») остаётся.
	 *
	 * @param string $note Сноска.
	 * @param string $by   Текст, с которым сравниваем.
	 * @return bool
	 */
	public static function covered( $note, $by ) {
		$a = self::stems( $note );
		if ( ! $a ) {
			return false; // одни связки: не трогаем
		}
		$new = array_diff( $a, self::stems( $by ) );
		foreach ( $new as $w ) {
			if ( preg_match( '/\d/u', $w ) ) {
				return false; // новое число (возраст, цена, этаж, время) всегда сведения
			}
		}
		return count( $new ) <= 1 && count( $new ) <= 0.25 * count( $a );
	}

	/**
	 * Сноска повторяет текст сцены из настроек? Тогда показывается только текст сцены.
	 *
	 * @param string $html  Сноска.
	 * @param array  $place Из KEP_Settings::stage_note().
	 * @return bool
	 */
	public static function same_place( $html, $place ) {
		return self::covered( $html, trim( $place['title'] . ' ' . $place['text'] ) );
	}

	/**
	 * Сноска из текста анонса: без «Внимание!» в начале (блок и так называется «Важно знать») и без цвета из редактора.
	 */
	public static function notice_html( $html ) {
		$h = wp_kses( (string) $html, KEP_Data::INLINE_TAGS );
		$h = preg_replace( '/^((?:\s*<(?:strong|b|em|i)>)*)\s*(?:Внимание|Обратите внимание|Важно)\s*[!:.]+\s*/iu', '$1', $h );
		$h = preg_replace( '#<(strong|b|em|i)>\s*</\1>#u', '', $h );
		$h = trim( preg_replace( '/^(\s*<br\s*\/?>)+/iu', '', $h ) );
		// Целиком жирная сноска: рамка и так выделяет, жирный не нужен.
		if ( preg_match( '#^<(strong|b)>(.*)</\1>$#su', $h, $m ) && false === strpos( $m[2], '<' . $m[1] . '>' ) ) {
			$h = trim( $m[2] );
		}
		return self::decaps( $h );
	}

	/**
	 * Сноска, набранная заглавными: в обычный регистр, чтобы читалась.
	 * С заглавной остаются начало предложения, слова в «кавычках», латиница и слово после «ул.», «м.» и т. п.
	 */
	public static function decaps( $h ) {
		$plain = wp_strip_all_tags( $h );
		preg_match_all( '/\p{Lu}/u', $plain, $up );
		preg_match_all( '/\p{L}/u', $plain, $all );
		if ( count( $all[0] ) < 8 || count( $up[0] ) / count( $all[0] ) < 0.6 ) {
			return $h;
		}
		$st    = array( 'start' => true, 'cap' => false, 'quote' => false, 'prev' => '' );
		$parts = preg_split( '/(<[^>]+>)/u', $h, -1, PREG_SPLIT_DELIM_CAPTURE );
		foreach ( $parts as $k => $part ) {
			if ( '' === $part || '<' === $part[0] ) {
				continue;
			}
			$parts[ $k ] = preg_replace_callback(
				'/[\p{L}]+|[^\p{L}]+/u',
				function ( $m ) use ( &$st ) {
					$t = $m[0];
					if ( ! preg_match( '/\p{L}/u', $t ) ) {
						if ( preg_match( '/[.!?]/u', $t ) && ! in_array( $st['prev'], array( 'ул', 'пер', 'м', 'руб', 'д', 'стр', 'пр', 'пл' ), true ) ) {
							$st['start'] = true;
						}
						if ( false !== mb_strpos( $t, '«', 0, 'UTF-8' ) ) {
							$st['quote'] = true;
						}
						return $t;
					}
					$low = mb_strtolower( $t, 'UTF-8' );
					$out = $t;
					if ( mb_strtoupper( $t, 'UTF-8' ) === $t ) {
						$title = $st['start'] || $st['cap'] || $st['quote'] || preg_match( '/^[a-z]/', $low );
						$out   = $title ? mb_strtoupper( mb_substr( $low, 0, 1, 'UTF-8' ), 'UTF-8' ) . mb_substr( $low, 1, null, 'UTF-8' ) : $low;
					}
					$st['cap']   = in_array( $low, array( 'ул', 'улица', 'улице', 'улицы', 'пер', 'переулок', 'м', 'метро', 'пл', 'площадь', 'проспект', 'пр', 'бульвар' ), true );
					$st['start'] = false;
					$st['quote'] = false;
					$st['prev']  = $low;
					return $out;
				},
				$part
			);
		}
		return implode( '', $parts );
	}

	public static function count_img( $html ) {
		return preg_match_all( '/<img\b/i', (string) $html );
	}

	public static function count_link( $html ) {
		return preg_match_all( '/<a\b[^>]*\bhref\s*=/i', (string) $html );
	}

	/**
	 * Длина текста без пробелов: для сравнения «было» и «перенесено».
	 */
	public static function len( $s ) {
		return mb_strlen( (string) preg_replace( '/\s+/u', '', self::clean( $s ) ), 'UTF-8' );
	}

	private static function lower( $s ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $s, 'UTF-8' ) : strtolower( (string) $s );
	}
}
