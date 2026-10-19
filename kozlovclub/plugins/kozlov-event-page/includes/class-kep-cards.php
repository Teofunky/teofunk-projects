<?php
/**
 * Название программы на карточках концертов в афише (главная, жанры, поиск):
 * строка под названием в сетке и подзаголовок в слайдере.
 * Тема не меняется: плагин дописывает строку в готовый HTML. Если что-то не так, страница отдаётся как была.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KEP_Cards {

	/** @var array|null slug => программа */
	private static $map = null;

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_start' ), 2 );
	}

	/**
	 * Видит ли текущий посетитель программы на карточках (тот же режим, что у новых страниц).
	 *
	 * @return bool
	 */
	public static function visible() {
		$mode = KEP_Settings::get( 'mode' );
		if ( 'all' === $mode ) {
			return true;
		}
		return 'editors' === $mode && current_user_can( 'edit_posts' );
	}

	/**
	 * Концерты с заполненной программой: адрес записи => программа.
	 *
	 * @return array
	 */
	public static function programs() {
		if ( null !== self::$map ) {
			return self::$map;
		}
		global $wpdb;
		self::$map = array();
		$rows      = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT p.post_name, m.meta_value FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = %s AND m.meta_value <> '' AND p.post_type = %s AND p.post_status = 'publish'",
				KEP_META_PROGRAM,
				KEP_POST_TYPE
			),
			ARRAY_N
		);
		foreach ( (array) $rows as $r ) {
			$prog = trim( (string) $r[1] );
			if ( '' !== $prog ) {
				self::$map[ self::slug_key( $r[0] ) ] = $prog;
			}
		}
		return self::$map;
	}

	private static function slug_key( $slug ) {
		return strtolower( rawurldecode( (string) $slug ) );
	}

	public static function maybe_start() {
		if ( is_admin() || is_feed() || is_singular() ) {
			return;
		}
		if ( ! ( is_front_page() || is_home() || is_archive() || is_search() ) ) {
			return;
		}
		if ( ! self::visible() || ! self::programs() ) {
			return;
		}
		if ( 'all' !== KEP_Settings::get( 'mode' ) && ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // версия для редакторов не должна попасть в кэш для гостей
		}
		ob_start( array( __CLASS__, 'filter_html' ) );
	}

	/**
	 * Дописать программы в карточки.
	 *
	 * @param string $html Готовая страница.
	 * @return string
	 */
	public static function filter_html( $html ) {
		if ( ! is_string( $html ) || false === strpos( $html, '/event/' ) ) {
			return $html;
		}
		try {
			$done = 0;
			$out  = self::add_to_grid( $html, $done );
			$out  = self::add_to_slider( $out, $done );
			if ( ! $done || ! is_string( $out ) || '' === $out ) {
				return $html;
			}
			// Программа сразу под названием, как подзаголовок. У однострочного названия дата остаётся на своём месте:
			// 10 + 20 (название) + 16 (программа) + 4 (отступ до даты) = 50 px, ровно высота, которую тема даёт названию.
			$css = '<style id="kep-cards">'
				. '.events.view_cell .event .desc .kep-sub{display:block;height:16px;margin:0 0 4px;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;font-size:13px;line-height:16px;font-weight:400;text-transform:none;letter-spacing:normal;color:#6b6f76}'
				. '.events.view_cell .event .desc:has(.kep-sub) .title.set_height{height:auto!important;min-height:0;padding-bottom:0}'
				. '.events.view_line .event .desc .kep-sub{display:none}'
				. '@media (max-width:688px){.events.view_cell .event .desc .kep-sub{padding:0 10px}}'
				. '.kz-sub{display:block;text-transform:none}'
				. '</style>';
			$pos = stripos( $out, '</head>' );
			return false === $pos ? $out : substr_replace( $out, $css, $pos, 0 );
		} catch ( Exception $e ) {
			return $html;
		} catch ( Error $e ) {
			return $html;
		}
	}

	/**
	 * Сетка афиши: <a class="title set_height" href="/event/slug/">НАЗВАНИЕ</a> → строка с программой под ним.
	 */
	private static function add_to_grid( $html, &$done ) {
		$res = preg_replace_callback(
			'#<a\b([^>]*\bclass="title(?:\s[^"]*)?"[^>]*)>(.*?)</a>#s',
			function ( $m ) use ( &$done ) {
				$prog = self::program_for( $m[1], $m[2] );
				if ( '' === $prog ) {
					return $m[0];
				}
				$done++;
				return $m[0] . '<span class="kep-sub" title="' . esc_attr( $prog ) . '">' . esc_html( $prog ) . '</span>';
			},
			$html
		);
		return is_string( $res ) ? $res : $html;
	}

	/**
	 * Слайдер на главной: подзаголовок в стиле темы (.kz-sub), если у названия ещё нет своего.
	 */
	private static function add_to_slider( $html, &$done ) {
		$res = preg_replace_callback(
			'#(<div class="title">\s*<a\b([^>]*)>)(.*?)(</a>)#s',
			function ( $m ) use ( &$done ) {
				if ( false !== strpos( $m[3], 'kz-sub' ) ) {
					return $m[0];
				}
				$prog = self::program_for( $m[2], $m[3] );
				if ( '' === $prog ) {
					return $m[0];
				}
				$done++;
				return $m[1] . $m[3] . '<span class="kz-sub">' . esc_html( $prog ) . '</span>' . $m[4];
			},
			$html
		);
		return is_string( $res ) ? $res : $html;
	}

	/**
	 * Программа для ссылки на концерт, если она есть и ещё не написана в названии.
	 *
	 * @param string $attrs Атрибуты ссылки.
	 * @param string $inner Текст ссылки.
	 * @return string
	 */
	private static function program_for( $attrs, $inner ) {
		if ( ! preg_match( '#\bhref="[^"]*/event/([^/?\#"]+)/?(?:[?\#][^"]*)?"#', $attrs, $mm ) ) {
			return '';
		}
		$map = self::programs();
		$key = self::slug_key( $mm[1] );
		if ( empty( $map[ $key ] ) ) {
			return '';
		}
		$prog = $map[ $key ];
		$p    = self::norm( $prog );
		if ( '' === $p || false !== strpos( self::norm( $inner ), $p ) ) {
			return ''; // программа уже есть в названии концерта
		}
		return $prog;
	}

	private static function norm( $s ) {
		$s = html_entity_decode( wp_strip_all_tags( (string) $s ), ENT_QUOTES, 'UTF-8' );
		$s = mb_strtolower( $s, 'UTF-8' );
		return (string) preg_replace( '/[^\p{L}\p{N}]+/u', '', $s );
	}
}
