<?php
/**
 * Текст для StreamVi: русская часть WPGlobus, чистый текст, первый абзац, обрезка по словам и предложениям.
 */

defined( 'ABSPATH' ) || exit;

final class KZSV_Text {

	/**
	 * Русская часть строки с разметкой WPGlobus: {:ru}текст{:}{:en}text{:} или [:ru]текст[:en]text[:].
	 * Строка без разметки возвращается как есть. (Та же логика, что в Kozlov Smart Search.)
	 */
	public static function ru( $s ) {
		$s = (string) $s;
		if ( false === strpos( $s, '{:' ) && false === strpos( $s, '[:' ) ) {
			return $s;
		}
		if ( false !== strpos( $s, '{:' ) ) {
			if ( preg_match_all( '/\{:ru\}(.*?)(?:\{:\}|$)/s', $s, $m ) && ! empty( $m[1] ) ) {
				return implode( "\n\n", $m[1] );
			}
			$stripped = preg_replace( '/\{:[a-z]{2}\}.*?(?:\{:\}|$)/s', '', $s );
			if ( is_string( $stripped ) && $stripped !== $s ) {
				return $stripped;
			}
		}
		if ( false !== strpos( $s, '[:' ) ) {
			if ( preg_match_all( '/\[:ru\](.*?)(?=\[:[a-z]{2}\]|\[:\]|$)/s', $s, $m ) && ! empty( $m[1] ) ) {
				return implode( "\n\n", $m[1] );
			}
			$stripped = preg_replace( '/\[:[a-z]{2}\].*?(?=\[:[a-z]{2}\]|\[:\]|$)/s', '', $s );
			if ( is_string( $stripped ) && $stripped !== $s ) {
				return str_replace( '[:]', '', $stripped );
			}
		}
		return $s;
	}

	/** Текст без тегов, скриптов и HTML-сущностей, в одну строку. */
	public static function plain( $html ) {
		$s = (string) $html;
		if ( '' === $s ) {
			return '';
		}
		if ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $s, 'UTF-8' ) ) {
			$s = mb_convert_encoding( $s, 'UTF-8', 'UTF-8' );
		}
		$s = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', ' ', $s );
		$s = preg_replace( '/<[^>]*>/', ' ', (string) $s );
		$s = html_entity_decode( (string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$s = str_replace( array( "\xC2\xA0", "\xE2\x80\x8B", "\xC2\xAD" ), array( ' ', '', '' ), $s );
		$s = preg_replace( '/\s+/u', ' ', $s );
		return trim( (string) $s );
	}

	/** Название так же, как его печатает афиша: русская часть, типографские кавычки WordPress. */
	public static function title( $raw ) {
		$t = self::plain( self::ru( $raw ) );
		if ( '' === $t ) {
			return '';
		}
		if ( function_exists( 'wptexturize' ) ) {
			try {
				$tx = html_entity_decode( (string) wptexturize( $t ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				if ( '' !== trim( $tx ) ) {
					$t = trim( $tx );
				}
			} catch ( \Throwable $e ) {
				kzsv_log_exception( $e );
			}
		}
		return $t;
	}

	/**
	 * Абзацы текста анонса: по <p>, двойным <br>, пустым строкам.
	 *
	 * @return string[] Абзацы чистым текстом, без пустых.
	 */
	public static function paragraphs( $html ) {
		$s = self::ru( (string) $html );
		if ( '' === trim( $s ) ) {
			return array();
		}
		if ( function_exists( 'strip_shortcodes' ) ) {
			$s = strip_shortcodes( $s );
		}
		$s = str_replace( array( "\r\n", "\r" ), "\n", $s );
		$s = preg_replace( '~<br\s*/?>\s*(?:&nbsp;|\xC2\xA0|\s)*<br\s*/?>~i', "\n\n", $s );
		$s = preg_replace( '~</p\s*>|<p(?:\s[^>]*)?>|</h[1-6]\s*>|</li\s*>|</div\s*>|</blockquote\s*>|<hr[^>]*>~i', "\n\n", (string) $s );
		$s = preg_replace( '~<br\s*/?>~i', "\n", (string) $s );

		$parts = preg_split( '~\n[ \t\xC2\xA0]*\n~u', (string) $s );
		if ( ! is_array( $parts ) ) {
			return array();
		}
		$out = array();
		foreach ( $parts as $part ) {
			$p = self::plain( $part );
			if ( '' !== $p ) {
				$out[] = $p;
			}
		}
		// весь текст одним куском с переносами строк: каждая строка считается абзацем
		if ( 1 === count( $out ) && 1 === count( $parts ) && false !== strpos( (string) $s, "\n" ) ) {
			$lines = array();
			foreach ( explode( "\n", (string) $s ) as $line ) {
				$p = self::plain( $line );
				if ( '' !== $p ) {
					$lines[] = $p;
				}
			}
			if ( count( $lines ) > 1 ) {
				$out = $lines;
			}
		}
		return $out;
	}

	/**
	 * Первый абзац анонса. Абзацы короче $min символов (например «Состав:» или «16+») пропускаются;
	 * если длинных нет, берётся первый непустой.
	 *
	 * @param array $sources Тексты анонса по порядку вывода на странице концерта.
	 */
	public static function first_paragraph( array $sources, $min ) {
		$first = '';
		foreach ( $sources as $src ) {
			foreach ( self::paragraphs( $src ) as $p ) {
				if ( '' === $first ) {
					$first = $p;
				}
				if ( self::len( $p ) >= (int) $min ) {
					return $p;
				}
			}
		}
		return $first;
	}

	/** Убирает символы, которые не принимает YouTube (< и >), и лишние пробелы. */
	public static function platform_safe( $s ) {
		$s = str_replace( array( '<', '>' ), array( '‹', '›' ), (string) $s );
		$s = preg_replace( "/[^\S\n]+/u", ' ', $s );
		$s = preg_replace( "/ *\n */u", "\n", (string) $s );
		$s = preg_replace( "/\n{3,}/u", "\n\n", (string) $s );
		return trim( (string) $s );
	}

	/**
	 * Обрезает до $max символов. $sentence: сначала пробует закончить на конце предложения.
	 */
	public static function clip( $s, $max, $sentence = false ) {
		$s   = (string) $s;
		$max = (int) $max;
		if ( $max <= 0 || self::len( $s ) <= $max ) {
			return $s;
		}
		$cut = self::sub( $s, 0, $max - 1 );
		if ( $sentence ) {
			if ( preg_match_all( '/[.!?…](?=\s|$)/u', $cut, $m, PREG_OFFSET_CAPTURE ) && ! empty( $m[0] ) ) {
				$last = end( $m[0] );
				$pos  = self::len( substr( $cut, 0, $last[1] ) ) + 1;
				if ( $pos >= (int) ( $max * 0.5 ) ) {
					return rtrim( self::sub( $cut, 0, $pos ) );
				}
			}
		}
		$space = function_exists( 'mb_strrpos' ) ? mb_strrpos( $cut, ' ', 0, 'UTF-8' ) : strrpos( $cut, ' ' );
		if ( false !== $space && $space >= (int) ( $max * 0.6 ) ) {
			$cut = self::sub( $cut, 0, $space );
		}
		return rtrim( $cut, " \t\n,;:-–—" ) . '…';
	}

	public static function len( $s ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $s, 'UTF-8' ) : strlen( (string) $s );
	}

	public static function sub( $s, $start, $length ) {
		return function_exists( 'mb_substr' ) ? mb_substr( (string) $s, $start, $length, 'UTF-8' ) : substr( (string) $s, $start, $length );
	}
}
