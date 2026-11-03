<?php
/**
 * Данные о концертах: заглавное фото, программа и индекс Kozlov Smart Search
 * (даты, сцены, «ещё в этот вечер», список будущих анонсов).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KEP_Data {

	/** @var array|false|null */
	private static $index = null;

	/**
	 * ID заглавного фото анонса, если оно есть и это картинка.
	 *
	 * @param int $post_id ID записи.
	 * @return int
	 */
	public static function hero_id( $post_id ) {
		$id = (int) get_post_meta( $post_id, KEP_META_HERO, true );
		if ( $id && wp_attachment_is_image( $id ) ) {
			return $id;
		}
		return 0;
	}

	/**
	 * Картинка для шапки новой страницы: заглавное фото, а если его нет, обложка записи
	 * (в том числе заглушка). Так новая страница открывается у всех концертов.
	 *
	 * @param int $post_id ID записи.
	 * @return int
	 */
	public static function display_hero_id( $post_id ) {
		$id = self::hero_id( $post_id );
		if ( $id ) {
			return $id;
		}
		$th = (int) get_post_thumbnail_id( $post_id );
		if ( $th && wp_attachment_is_image( $th ) ) {
			return $th;
		}
		return 0;
	}

	/**
	 * Название программы.
	 *
	 * @param int $post_id ID записи.
	 * @return string
	 */
	public static function program( $post_id ) {
		return trim( (string) get_post_meta( $post_id, KEP_META_PROGRAM, true ) );
	}

	/** Разрешённая разметка в тексте сверху и в сносках: жирный, курсив, ссылки, перенос строки. */
	const INLINE_TAGS = array(
		'strong' => array(),
		'b'      => array(),
		'em'     => array(),
		'i'      => array(),
		'br'     => array(),
		'a'      => array( 'href' => true, 'target' => true, 'rel' => true ),
	);

	/**
	 * Очистить строку текста для поля «Коротко о концерте» или «Важно знать».
	 *
	 * @param string $s Текст.
	 * @return string
	 */
	public static function inline( $s ) {
		$s = wp_kses( (string) $s, self::INLINE_TAGS );
		return trim( preg_replace( '/[ \t]+/u', ' ', $s ) );
	}

	/**
	 * «Коротко о концерте»: текст для верха страницы, абзацы через пустую строку.
	 *
	 * @param int $post_id ID записи.
	 * @return string HTML из абзацев <p>.
	 */
	public static function lead( $post_id ) {
		$raw = trim( (string) get_post_meta( $post_id, KEP_META_LEAD, true ) );
		if ( '' === $raw ) {
			return '';
		}
		$out = '';
		foreach ( preg_split( '/\n\s*\n/u', str_replace( "\r", '', $raw ) ) as $para ) {
			$para = self::inline( nl2br( trim( $para ), false ) );
			if ( '' !== $para ) {
				$out .= '<p>' . $para . '</p>';
			}
		}
		return $out;
	}

	/**
	 * Галочка «Не забирать сноски из текста анонса».
	 *
	 * @param int $post_id ID записи.
	 * @return bool
	 */
	public static function keep_text( $post_id ) {
		return '1' === (string) get_post_meta( $post_id, KEP_META_KEEP, true );
	}

	/**
	 * «Важно знать»: сноски по одной на строку (депозит, бронь, вход, адрес).
	 *
	 * @param int $post_id ID записи.
	 * @return string[] HTML строк.
	 */
	public static function notes( $post_id ) {
		$raw = trim( (string) get_post_meta( $post_id, KEP_META_NOTES, true ) );
		$out = array();
		foreach ( preg_split( '/\n/u', str_replace( "\r", '', $raw ) ) as $line ) {
			$line = self::inline( $line );
			if ( '' !== $line ) {
				$out[] = $line;
			}
		}
		return $out;
	}

	/**
	 * Проверка фото: размер и пропорции. Те же правила, что в подсказке в админке.
	 *
	 * @param int $w Ширина.
	 * @param int $h Высота.
	 * @return array{level:string,text:string}
	 */
	public static function check_size( $w, $h ) {
		$w = (int) $w;
		$h = (int) $h;
		if ( $w <= 0 || $h <= 0 ) {
			return array( 'level' => 'warn', 'text' => 'Не удалось узнать размер фото.' );
		}
		if ( $h > $w ) {
			return array( 'level' => 'bad', 'text' => sprintf( 'Вертикальное фото %d×%d: сверху и снизу обрежется много. Нужно горизонтальное.', $w, $h ) );
		}
		if ( $w < 1600 || $h < 1000 ) {
			return array( 'level' => 'warn', 'text' => sprintf( 'Маловато: %d×%d. На компьютере будет нечётким, нужно от 1600×1000.', $w, $h ) );
		}
		$ratio = $w / $h;
		if ( abs( $ratio - 1.6 ) / 1.6 > 0.04 ) {
			return array( 'level' => 'warn', 'text' => sprintf( '%d×%d, пропорции не 16:10: края обрежутся. Что останется, видно в рамке.', $w, $h ) );
		}
		return array( 'level' => 'ok', 'text' => sprintf( '%d×%d, подходит.', $w, $h ) );
	}

	/**
	 * Индекс Kozlov Smart Search (будущие концерты). false, если плагина нет.
	 *
	 * @return array|false
	 */
	public static function index() {
		if ( null !== self::$index ) {
			return self::$index;
		}
		self::$index = false;
		if ( ! function_exists( 'rest_do_request' ) || ! class_exists( 'WP_REST_Request' ) ) {
			return false;
		}
		try {
			$res = rest_do_request( new WP_REST_Request( 'GET', '/kozlov-search/v1/index' ) );
			if ( ! $res || $res->is_error() ) {
				return false;
			}
			$data = $res->get_data();
			if ( is_string( $data ) ) {
				$data = json_decode( $data, true );
			}
			if ( is_array( $data ) && isset( $data['ev'] ) && is_array( $data['ev'] ) ) {
				self::$index = $data;
			}
		} catch ( Exception $e ) {
			self::$index = false;
		} catch ( Error $e ) {
			self::$index = false;
		}
		return self::$index;
	}

	/**
	 * Будущие концерты из индекса, по дате и времени.
	 *
	 * @return array Список массивов: title, date, time, stage, price, slug, img.
	 */
	public static function upcoming() {
		$idx = self::index();
		if ( ! $idx ) {
			return array();
		}
		$out = array();
		foreach ( $idx['ev'] as $e ) {
			if ( ! is_array( $e ) || count( $e ) < 7 ) {
				continue;
			}
			$out[] = array(
				'title' => (string) $e[0],
				'date'  => (string) $e[1],
				'time'  => (string) $e[2],
				'stage' => (string) $e[3],
				'price' => (string) $e[4],
				'slug'  => (string) $e[5],
				'img'   => (string) $e[6],
			);
		}
		usort( $out, array( __CLASS__, 'cmp' ) );
		return $out;
	}

	/**
	 * Сортировка по дате и времени.
	 *
	 * @param array $a Концерт.
	 * @param array $b Концерт.
	 * @return int
	 */
	public static function cmp( $a, $b ) {
		return strcmp( $a['date'] . ' ' . $a['time'], $b['date'] . ' ' . $b['time'] );
	}

	/**
	 * Концерт из индекса по адресу записи.
	 *
	 * @param string $slug Адрес записи.
	 * @return array|null
	 */
	public static function by_slug( $slug ) {
		foreach ( self::upcoming() as $e ) {
			if ( $e['slug'] === $slug ) {
				return $e;
			}
		}
		return null;
	}

	/**
	 * Другие концерты того же дня.
	 *
	 * @param string $slug Адрес текущей записи.
	 * @param string $date Дата Y-m-d.
	 * @return array
	 */
	public static function same_day( $slug, $date ) {
		$out = array();
		foreach ( self::upcoming() as $e ) {
			if ( $e['date'] === $date && $e['slug'] !== $slug ) {
				$out[] = $e;
			}
		}
		return array_slice( $out, 0, 6 );
	}

	/**
	 * Адрес картинки из индекса.
	 *
	 * @param string $path Путь из индекса.
	 * @return string
	 */
	public static function img_url( $path ) {
		if ( '' === $path ) {
			return '';
		}
		if ( preg_match( '#^https?://#', $path ) ) {
			return $path;
		}
		$idx  = self::index();
		$base = ( $idx && ! empty( $idx['imgBase'] ) ) ? $idx['imgBase'] : '/wp-content/uploads/';
		return home_url( rtrim( $base, '/' ) . '/' . ltrim( $path, '/' ) );
	}

	/**
	 * Адрес страницы концерта из индекса.
	 *
	 * @param string $slug Адрес записи.
	 * @return string
	 */
	public static function event_url( $slug ) {
		$idx  = self::index();
		$base = ( $idx && ! empty( $idx['evBase'] ) ) ? $idx['evBase'] : '/event/';
		return home_url( rtrim( $base, '/' ) . '/' . $slug . '/' );
	}

	/**
	 * Сбросить кэш страницы записи (W3 Total Cache), если он есть.
	 *
	 * @param int $post_id ID записи.
	 */
	public static function flush_post( $post_id ) {
		if ( function_exists( 'w3tc_flush_post' ) ) {
			w3tc_flush_post( $post_id );
		}
		if ( function_exists( 'clean_post_cache' ) ) {
			clean_post_cache( $post_id );
		}
	}

	/**
	 * Артисты концерта из плагина Kozlov Artists, если он установлен.
	 * На сайте тема не выводит текст записи, поэтому блок «Об артисте» Kozlov Artists вставляет скриптом
	 * уже в браузере: в готовом HTML его нет, данные берутся прямо из плагина.
	 *
	 * @param int $post_id ID концерта.
	 * @return array Список: url, name, photo, text, more.
	 */
	public static function ka_artists( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array();
		}
		try {
			if ( function_exists( 'ka_event_artists' ) ) {
				$list = ka_event_artists( $post );
				return is_array( $list ) ? array_values( $list ) : array();
			}
			if ( ! function_exists( 'ka_artists_for_event' ) ) {
				return array();
			}
			$out = array();
			foreach ( (array) ka_artists_for_event( $post ) as $aid ) {
				$aid = (int) $aid;
				$out[] = array(
					'url'   => (string) get_permalink( $aid ),
					'name'  => (string) get_the_title( $aid ),
					'photo' => has_post_thumbnail( $aid ) ? (string) get_the_post_thumbnail_url( $aid, 'medium' ) : '',
					'text'  => trim( wp_strip_all_tags( (string) get_post_field( 'post_excerpt', $aid ) ) ),
					'more'  => 'Афиша, видео и история артиста',
				);
			}
			return $out;
		} catch ( Exception $e ) {
			return array();
		} catch ( Error $e ) {
			return array();
		}
	}

	/**
	 * Сбросить кэш главной (там карточки с программами).
	 */
	public static function flush_home() {
		if ( function_exists( 'w3tc_flush_url' ) ) {
			w3tc_flush_url( home_url( '/' ) );
		}
	}

	/**
	 * Сбросить кэш страниц афиши, где видна программа концерта: главная, архив мероприятий,
	 * страницы его жанров и рубрик (красивый адрес и вариант ?жанр=…). Весь кэш сайта не трогается.
	 *
	 * @param int $post_id ID концерта.
	 */
	public static function flush_listings( $post_id ) {
		self::flush_home();
		if ( ! function_exists( 'w3tc_flush_url' ) ) {
			return;
		}
		$urls = array();
		$arch = get_post_type_archive_link( KEP_POST_TYPE );
		if ( $arch ) {
			$urls[] = $arch;
		}
		foreach ( get_object_taxonomies( KEP_POST_TYPE, 'objects' ) as $tax ) {
			if ( empty( $tax->public ) ) {
				continue;
			}
			$terms = wp_get_post_terms( $post_id, $tax->name );
			if ( is_wp_error( $terms ) ) {
				continue;
			}
			foreach ( $terms as $t ) {
				$link = get_term_link( $t );
				if ( ! is_wp_error( $link ) ) {
					$urls[] = $link;
				}
				if ( ! empty( $tax->query_var ) ) {
					$urls[] = add_query_arg( $tax->query_var, $t->slug, home_url( '/' ) );
				}
			}
		}
		foreach ( array_slice( array_values( array_unique( $urls ) ), 0, 40 ) as $u ) {
			w3tc_flush_url( $u );
		}
	}

	/**
	 * Сбросить весь кэш страниц (при смене режима).
	 */
	public static function flush_all() {
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
		}
	}
}
