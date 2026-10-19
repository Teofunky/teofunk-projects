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
		$start = $m[0][1];
		$pos   = $start + strlen( $m[0][0] );
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

		$btn     = self::q1( $x, './/*[@tc-event]', $info );
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

		$banner      = self::q1( $x, './/*[' . self::c( 'anons_thumb' ) . ']//img', $root );
		$d['banner'] = $banner ? array(
			'src' => self::img_src( $banner ),
			'alt' => $banner->getAttribute( 'alt' ),
		) : null;

		$d['quote']     = null;
		$d['paras']     = array();
		$d['musicians'] = array();
		$d['videos']    = array();
		$d['extra']     = array();
		$d['flow']      = array();
		$seen_artist    = false;

		foreach ( $desc->childNodes as $block ) {
			if ( ! ( $block instanceof DOMElement ) ) {
				continue;
			}
			$cls = ' ' . preg_replace( '/\s+/', ' ', $block->getAttribute( 'class' ) ) . ' ';
			if ( false === strpos( $cls, ' meta_block ' ) ) {
				continue;
			}
			if ( false !== strpos( $cls, ' buy_button ' ) || false !== strpos( $cls, ' share_panel ' ) ) {
				continue;
			}
			// Видео из любого блока.
			foreach ( $x->query( './/iframe', $block ) as $f ) {
				$v = self::video( $f->getAttribute( 'src' ) ? $f->getAttribute( 'src' ) : $f->getAttribute( 'data-src' ) );
				if ( $v ) {
					$d['videos'][] = $v;
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
				$text = self::text( self::q1( $x, './/*[' . self::c( 'desc' ) . ']', $block ) );
				if ( mb_strlen( $text, 'UTF-8' ) > 10 && ! $d['quote'] ) {
					$d['quote'] = array(
						'text'   => self::utrim( $text, '\s«»"“”„' ),
						'author' => self::text( self::q1( $x, './/*[' . self::c( 'author' ) . ']', $block ) ),
					);
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
					$d['flow'][]        = array( 't' => 'p', 'i' => count( $d['paras'] ) );
					$d['paras'][]       = $p;
				}
				continue;
			}
			if ( false !== strpos( $cls, ' youtube ' ) ) {
				continue;
			}
			$plain = self::text( $block );
			if ( '' !== $plain ) {
				$d['flow'][]  = array( 't' => 'x', 'i' => count( $d['extra'] ) );
				$d['extra'][] = self::inner( $block );
			}
		}

		// Короткое описание: первый обычный абзац до блоков о музыкантах.
		$d['lead'] = '';
		foreach ( $d['paras'] as $i => $p ) {
			$len = mb_strlen( $p['text'], 'UTF-8' );
			if ( 'text' === $p['kind'] && $p['before_artist'] && $len >= 30 && $len <= 420 ) {
				$d['lead']                  = $p['text'];
				$d['paras'][ $i ]['kind'] = 'lead';
				break;
			}
		}

		// Состав из строки «На сцену выйдут: …».
		$d['lineup'] = array();
		foreach ( $d['paras'] as $p ) {
			if ( 'lineup' === $p['kind'] ) {
				$d['lineup'] = self::lineup( $p['text'] );
				if ( $d['lineup'] ) {
					break;
				}
			}
		}

		$d['videos'] = self::unique_videos( $d['videos'] );
		$d['type']   = self::cta_type( $d );
		return $d;
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
		if ( preg_match( '/(В анонсе использован|Внимание!\s*Состав|Состав коллектива может быть изм|Состав (может|могут) быть изм)/iu', $text ) ) {
			return 'note';
		}
		if ( preg_match( '/(выйд[уеё]т|выступ[ая]т)[^:.]{0,40}:/iu', $text ) || preg_match( '/^\s*(В составе|Состав)\s*:/iu', $text ) ) {
			return 'lineup';
		}
		return 'text';
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
			if ( '' !== $text ) {
				$out[] = array( 'html' => trim( $buffer ), 'text' => $text, 'tag' => 'p' );
			}
			$buffer = '';
		};
		foreach ( $block->childNodes as $n ) {
			if ( $n instanceof DOMElement && in_array( strtolower( $n->nodeName ), array( 'p', 'div', 'ul', 'ol', 'h2', 'h3', 'h4', 'blockquote' ), true ) ) {
				$flush();
				$tag  = strtolower( $n->nodeName );
				$text = self::text( $n );
				if ( '' === $text ) {
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

	private static function lower( $s ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $s, 'UTF-8' ) : strtolower( (string) $s );
	}
}
