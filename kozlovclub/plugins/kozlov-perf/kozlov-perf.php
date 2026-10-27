<?php
/**
 * Plugin Name:       Kozlov Perf
 * Plugin URI:        https://kozlovclub.ru/
 * Description:       Изолированные оптимизации загрузки: нативная ленивая загрузка вместо a3 Lazy Load, уборка неиспользуемых скриптов (FancyBox, YouTube Embed Plus, emoji), preconnect, дедупликация тегов. Не меняет тему, данные и другие плагины. Отключается галочкой в настройках, параметром ?kperf=off или деактивацией.
 * Version:           1.1.0
 * Author:            Kozlov Club
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 * Text Domain:       kozlov-perf
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Kozlov_Perf {

	const OPTION  = 'kozlov_perf_options';
	const VERSION = '1.1.0';

	/**
	 * Тело тега с учётом кавычек: символ > внутри значения атрибута не завершает тег.
	 */
	const ATTRS = '(?:[^>"\']|"[^"]*"|\'[^\']*\')*';

	/** @var Kozlov_Perf|null */
	private static $instance = null;

	/** @var array */
	private $opts = array();

	/** @var array Статистика для HTML-комментария в конце страницы. */
	public $stats = array();

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function defaults() {
		return array(
			'enabled'          => 1,
			'native_lazy'      => 1,
			'eager_count'      => 8,
			'thumb_srcset'     => 1,
			'strip_unused'     => 1,
			'dequeue_patterns' => "jquery.animateNumber",
			'defer_patterns'   => '',
			'emoji'            => 1,
			'head_cleanup'     => 1,
			'preconnect'       => "https://fonts.googleapis.com\nhttps://fonts.gstatic.com\nhttps://mc.yandex.ru\nhttps://top-fwz1.mail.ru\nhttps://ticketscloud.com",
			'dedupe'           => 1,
			'fix_ver'          => 1,
			'block_css'        => 0,
			'mobile_zoom'      => 1,
		);
	}

	private function __construct() {
		$saved      = get_option( self::OPTION, array() );
		$this->opts = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );

		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_init', array( $this, 'admin_init' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'action_links' ) );

		add_action( 'init', array( $this, 'front_init' ), 1 );
	}

	/* ------------------------------------------------------------------ */
	/* Опции                                                               */
	/* ------------------------------------------------------------------ */

	private function opt( $key ) {
		return isset( $this->opts[ $key ] ) ? $this->opts[ $key ] : null;
	}

	/** Список непустых строк из textarea-опции. */
	private function lines( $key ) {
		$raw = (string) $this->opt( $key );
		$out = array();
		foreach ( preg_split( '/[\r\n]+/', $raw ) as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				$out[] = $line;
			}
		}
		return $out;
	}

	/** Шаблоны для поиска в src: только строки длиной от 6 символов, чтобы случайно не задеть jQuery. */
	private function patterns( $key ) {
		$out = array();
		foreach ( $this->lines( $key ) as $p ) {
			if ( strlen( $p ) >= 6 && ! preg_match( '/^jquery(\.min)?(\.js)?$/i', $p ) ) {
				$out[] = $p;
			}
		}
		return $out;
	}

	private function matches( $src, array $patterns ) {
		if ( ! is_string( $src ) || '' === $src ) {
			return false;
		}
		foreach ( $patterns as $p ) {
			if ( false !== stripos( $src, $p ) ) {
				return true;
			}
		}
		return false;
	}

	/** Полный обход плагина параметром ?kperf=off (для сравнения до/после). */
	private function bypassed() {
		return isset( $_GET['kperf'] ) && 'off' === $_GET['kperf']; // phpcs:ignore WordPress.Security.NonceVerification
	}

	private function active() {
		return ! empty( $this->opts['enabled'] ) && ! $this->bypassed();
	}

	/* ------------------------------------------------------------------ */
	/* Фронт                                                               */
	/* ------------------------------------------------------------------ */

	public function front_init() {
		if ( is_admin() || ! $this->active() ) {
			return;
		}

		if ( $this->opt( 'emoji' ) ) {
			remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
			remove_action( 'wp_print_styles', 'print_emoji_styles' );
			remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
			remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
			remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
			add_filter( 'wp_resource_hints', array( $this, 'drop_emoji_prefetch' ), 10, 2 );
		}

		if ( $this->opt( 'head_cleanup' ) ) {
			remove_action( 'wp_head', 'rsd_link' );
			remove_action( 'wp_head', 'wlwmanifest_link' );
			remove_action( 'wp_head', 'wp_generator' );
			remove_action( 'wp_head', 'wp_shortlink_wp_head' );
			add_filter( 'the_generator', '__return_empty_string' );
			add_action( 'send_headers', array( $this, 'remove_powered_by' ) );
		}

		if ( $this->opt( 'preconnect' ) ) {
			add_filter( 'wp_resource_hints', array( $this, 'resource_hints' ), 20, 2 );
		}

		if ( $this->opt( 'mobile_zoom' ) ) {
			add_action( 'wp_head', array( $this, 'touch_css' ), 2 );
		}

		if ( $this->opt( 'fix_ver' ) ) {
			add_filter( 'script_loader_src', array( $this, 'fix_ver' ), 99 );
			add_filter( 'style_loader_src', array( $this, 'fix_ver' ), 99 );
		}

		if ( $this->opt( 'native_lazy' ) ) {
			// Если a3 Lazy Load ещё активен: просим его не трогать разметку (фильтр из самого a3).
			add_filter( 'a3_lazy_load_run_filter', '__return_false', 99 );
		}

		// Снятие ненужных скриптов и стилей из очереди (несколько точек, чтобы поймать поздние enqueue).
		add_action( 'wp_enqueue_scripts', array( $this, 'dequeue_assets' ), 9999 );
		add_action( 'wp_print_scripts', array( $this, 'dequeue_assets' ), 1 );
		add_action( 'wp_print_styles', array( $this, 'dequeue_assets' ), 1 );
		add_action( 'wp_print_footer_scripts', array( $this, 'dequeue_assets' ), 1 );

		// defer через штатную стратегию загрузки WordPress 6.3+: ядро само проверяет зависимости и inline-хвосты.
		if ( $this->patterns( 'defer_patterns' ) ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'defer_strategy' ), 9998 );
		}

		// Обработка готового HTML. Приоритет большой, чтобы наш буфер был внутренним относительно кеш-плагинов:
		// тогда кеш сохраняет уже оптимизированную страницу.
		add_action( 'template_redirect', array( $this, 'maybe_buffer' ), 9999 );
	}

	public function drop_emoji_prefetch( $urls, $relation ) {
		if ( 'dns-prefetch' === $relation && is_array( $urls ) ) {
			$emoji = apply_filters( 'emoji_svg_url', 'https://s.w.org/images/core/emoji/15.0.3/svg/' );
			$urls  = array_values( array_diff( $urls, array( $emoji ) ) );
		}
		return $urls;
	}

	/**
	 * Касания на мобильных: кнопки и ссылки отвечают сразу (без ожидания второго тапа и без
	 * случайного зума по двойному тапу), поля ввода 16px, чтобы iOS не увеличивал страницу при фокусе.
	 * Пинч-зум при этом остаётся: он разрешён в viewport (см. шаг viewport в буфере).
	 */
	public function touch_css() {
		echo '<style id="kperf-touch">a,button,input,select,textarea,label,summary,[role=button],[onclick]{touch-action:manipulation}@media (pointer:coarse){input:not([type=checkbox]):not([type=radio]):not([type=range]),select,textarea{font-size:max(16px,1em)}}</style>' . "\n";
	}

	public function remove_powered_by() {
		if ( ! headers_sent() && function_exists( 'header_remove' ) ) {
			@header_remove( 'X-Powered-By' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}

	public function resource_hints( $urls, $relation ) {
		if ( 'preconnect' !== $relation || ! is_array( $urls ) ) {
			return $urls;
		}
		foreach ( $this->lines( 'preconnect' ) as $u ) {
			$u = esc_url_raw( $u );
			if ( '' === $u ) {
				continue;
			}
			$host = (string) wp_parse_url( $u, PHP_URL_HOST );
			if ( false !== strpos( $host, 'gstatic' ) ) {
				$urls[] = array( 'href' => $u, 'crossorigin' );
			} else {
				$urls[] = $u;
			}
		}
		return $urls;
	}

	/** Убирает задвоенный параметр ver (…?ver=X&ver=X). */
	public function fix_ver( $src ) {
		if ( is_string( $src ) && substr_count( $src, 'ver=' ) > 1 ) {
			$fixed = preg_replace( '/([?&])ver=([^&]*)(?:&(?:#038;|amp;)?ver=[^&]*)+/', '$1ver=$2', $src );
			if ( is_string( $fixed ) && '' !== $fixed ) {
				return $fixed;
			}
		}
		return $src;
	}

	/**
	 * defer для скриптов из списка через wp_script_add_data( 'strategy' ).
	 * Ядро применяет defer только если все зависимые скрипты тоже могут быть отложены
	 * и у скрипта нет inline-кода "after"; иначе стратегия понижается само собой.
	 */
	public function defer_strategy() {
		if ( ! function_exists( 'wp_script_add_data' ) ) {
			return;
		}
		$patterns = $this->patterns( 'defer_patterns' );
		if ( ! $patterns ) {
			return;
		}
		$protected = array( 'jquery', 'jquery-core', 'jquery-migrate' );
		foreach ( wp_scripts()->registered as $handle => $dep ) {
			if ( in_array( $handle, $protected, true ) || empty( $dep->src ) ) {
				continue;
			}
			if ( $this->matches( $dep->src, $patterns ) ) {
				wp_script_add_data( $handle, 'strategy', 'defer' );
				$this->stats['defer'][] = $handle;
			}
		}
	}

	public function dequeue_assets() {
		static $done = array();
		$key = current_action();
		if ( isset( $done[ $key ] ) ) {
			return;
		}
		$done[ $key ] = true;

		if ( $this->opt( 'block_css' ) ) {
			foreach ( array( 'wp-block-library', 'wp-block-library-theme', 'global-styles', 'classic-theme-styles', 'core-block-supports' ) as $h ) {
				wp_dequeue_style( $h );
			}
		}

		$patterns = $this->patterns( 'dequeue_patterns' );
		if ( $this->opt( 'native_lazy' ) ) {
			$patterns[] = 'plugins/a3-lazy-load/';
			$patterns[] = 'uploads/sass/a3_lazy_load';
		}
		if ( ! $patterns ) {
			return;
		}

		$protected = array( 'jquery', 'jquery-core', 'jquery-migrate' );

		foreach ( wp_scripts()->registered as $handle => $dep ) {
			if ( in_array( $handle, $protected, true ) ) {
				continue;
			}
			if ( ! empty( $dep->src ) && $this->matches( $dep->src, $patterns ) ) {
				wp_dequeue_script( $handle );
				$this->stats['dequeued'][] = $handle;
			}
		}
		foreach ( wp_styles()->registered as $handle => $dep ) {
			if ( ! empty( $dep->src ) && $this->matches( $dep->src, $patterns ) ) {
				wp_dequeue_style( $handle );
				$this->stats['dequeued'][] = $handle;
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/* Буфер вывода                                                        */
	/* ------------------------------------------------------------------ */

	public function maybe_buffer() {
		if ( ! $this->active() ) {
			return;
		}
		if ( is_admin() || is_feed() || is_embed() || is_robots() || is_trackback() || is_preview() || is_customize_preview() ) {
			return;
		}
		if ( ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {
			return;
		}
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'GET' !== strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) ) {
			return;
		}
		if ( ! $this->opt( 'native_lazy' ) && ! $this->opt( 'strip_unused' ) && ! $this->opt( 'dedupe' ) && ! $this->opt( 'mobile_zoom' ) && ! $this->patterns( 'dequeue_patterns' ) ) {
			return;
		}
		ob_start( array( $this, 'process_html' ) );
	}

	/**
	 * Callback буфера. Исключение PHP внутри возвращает исходный HTML; каждый шаг
	 * дополнительно проверяет, что регулярное выражение отработало (не null).
	 */
	public function process_html( $html ) {
		try {
			if ( ! is_string( $html ) || strlen( $html ) < 512 ) {
				return $html;
			}
			if ( false === stripos( $html, '</html' ) ) {
				return $html; // не полная страница (кусок буфера, JSON и т.п.)
			}
			foreach ( headers_list() as $h ) {
				if ( 0 === stripos( $h, 'content-type:' ) && false === stripos( $h, 'text/html' ) ) {
					return $html;
				}
			}

			$t0  = microtime( true );
			$out = $html;

			if ( $this->opt( 'native_lazy' ) ) {
				$out = $this->step( $out, 'images' );
			}
			if ( $this->opt( 'strip_unused' ) || $this->opt( 'native_lazy' ) || $this->patterns( 'dequeue_patterns' ) ) {
				$out = $this->step( $out, 'strip_unused' );
			}
			if ( $this->opt( 'dedupe' ) ) {
				$out = $this->step( $out, 'dedupe_assets' );
				$out = $this->step( $out, 'dedupe_og' );
			}
			if ( $this->opt( 'mobile_zoom' ) ) {
				$out = $this->step( $out, 'viewport' );
			}

			$ms  = round( ( microtime( true ) - $t0 ) * 1000, 1 );
			$sum = array();
			foreach ( $this->stats as $k => $v ) {
				$sum[] = $k . '=' . ( is_array( $v ) ? implode( '|', array_unique( $v ) ) : $v );
			}
			$comment = "\n<!-- Kozlov Perf " . self::VERSION . ': ' . $ms . 'ms; ' . implode( '; ', $sum ) . " -->\n";
			$pos     = strripos( $out, '</body>' );
			$out     = ( false !== $pos ) ? substr_replace( $out, $comment, $pos, 0 ) : $out . $comment;

			return $out;
		} catch ( \Throwable $e ) {
			return $html;
		}
	}

	private function step( $html, $method ) {
		$res = $this->$method( $html );
		return ( is_string( $res ) && strlen( $res ) > 0 ) ? $res : $html;
	}

	/**
	 * Выполняет $cb только на «живой» разметке: куски внутри указанных блоков
	 * (комментарии, script, noscript, style, template, textarea) не трогаются.
	 * Возвращает null, если разбиение не удалось.
	 */
	private function outside( $html, array $blocks, $cb ) {
		$alts = array();
		foreach ( $blocks as $b ) {
			if ( 'comment' === $b ) {
				$alts[] = '<!--.*?-->';
			} else {
				$alts[] = '<' . $b . '\b' . self::ATTRS . '>.*?<\/' . $b . '\s*>';
			}
		}
		$parts = preg_split( '/(' . implode( '|', $alts ) . ')/is', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( ! is_array( $parts ) ) {
			return null;
		}
		foreach ( $parts as $i => $part ) {
			if ( $i % 2 === 1 || '' === $part ) {
				continue;
			}
			$res = $cb( $part );
			if ( ! is_string( $res ) ) {
				return null;
			}
			$parts[ $i ] = $res;
		}
		return implode( '', $parts );
	}

	/* ---------- шаг 1: картинки ---------- */

	private function images( $html ) {
		$eager    = max( 0, (int) $this->opt( 'eager_count' ) );
		$thumbs   = (bool) $this->opt( 'thumb_srcset' );
		$a3_page  = ( false !== stripos( $html, 'data-lazy-type' ) || false !== stripos( $html, 'lazy_placeholder' ) );
		$count    = 0;
		$lazied   = 0;
		$reverted = 0;
		$srcset_dropped = 0;
		$self     = $this;

		$res = $this->outside(
			$html,
			array( 'comment', 'script', 'noscript', 'style', 'template', 'textarea' ),
			function ( $part ) use ( &$count, &$lazied, &$reverted, &$srcset_dropped, $eager, $thumbs, $a3_page, $self ) {
				return preg_replace_callback(
					'/<(img|iframe|video|source)\b' . Kozlov_Perf::ATTRS . '>/i',
					function ( $m ) use ( &$count, &$lazied, &$reverted, &$srcset_dropped, $eager, $thumbs, $a3_page, $self ) {
						$tag  = $m[0];
						$name = strtolower( $m[1] );

						$is_a3 = ( false !== stripos( $tag, 'data-lazy-type' ) || false !== stripos( $tag, 'lazy_placeholder' ) );
						// <source data-srcset> внутри <picture> a3 не помечает: восстанавливаем, если на странице есть a3-разметка.
						if ( ! $is_a3 && 'source' === $name && $a3_page && preg_match( '/\sdata-srcset\s*=/i', $tag ) && ! preg_match( '/\ssrcset\s*=/i', $tag ) ) {
							$is_a3 = true;
						}
						if ( $is_a3 ) {
							$tag = $self->revert_a3( $tag );
							$reverted++;
						}
						if ( 'img' !== $name ) {
							return $tag;
						}

						// Превью (src вида …-450x338.jpg) со srcset: браузер на retina берёт из srcset
						// оригинал 800+ КБ вместо превью. Оставляем только src.
						if ( $thumbs && preg_match( '/\ssrcset\s*=/i', $tag ) && preg_match( '/\ssrc\s*=\s*["\'][^"\']*-\d{2,4}x\d{2,4}\.(?:jpe?g|png|gif|webp|avif)(?:[?#][^"\']*)?["\']/i', $tag ) ) {
							$tag = preg_replace( '/\ssrcset\s*=\s*(["\'])[^"\']*\1/i', '', $tag, 1 );
							$tag = preg_replace( '/\ssizes\s*=\s*(["\'])[^"\']*\1/i', '', $tag, 1 );
							$srcset_dropped++;
						}

						$count++;
						$has_loading = (bool) preg_match( '/\sloading\s*=/i', $tag );
						if ( $count <= $eager ) {
							// Первые N картинок: грузим сразу (убираем lazy, если ядро его поставило).
							if ( $has_loading ) {
								$tag = preg_replace( '/\sloading\s*=\s*(["\'])lazy\1/i', '', $tag, 1 );
							}
						} elseif ( ! $has_loading ) {
							$tag = $self->add_attr( $tag, 'loading="lazy"' );
							if ( ! preg_match( '/\sdecoding\s*=/i', $tag ) ) {
								$tag = $self->add_attr( $tag, 'decoding="async"' );
							}
							$lazied++;
						}
						return $tag;
					},
					$part
				);
			}
		);

		if ( null === $res ) {
			return $html;
		}
		$this->stats['img_total']   = $count;
		$this->stats['img_lazy']    = $lazied;
		$this->stats['a3_reverted'] = $reverted;
		$this->stats['srcset_dropped'] = $srcset_dropped;
		return $res;
	}

	/** Возвращает разметку a3 Lazy Load к обычной: data-src → src, убирает заглушку и классы lazy. */
	public function revert_a3( $tag ) {
		if ( preg_match( '/\sdata-src\s*=\s*(["\'])(.*?)\1/is', $tag ) ) {
			$tag = preg_replace( '/\ssrc\s*=\s*(["\'])[^"\']*\1/i', '', $tag, 1 );
			$tag = preg_replace( '/\sdata-src\s*=/i', ' src=', $tag, 1 );
		}
		if ( preg_match( '/\sdata-srcset\s*=/i', $tag ) ) {
			$tag = preg_replace( '/\ssrcset\s*=\s*(["\'])[^"\']*\1/i', '', $tag, 1 );
			$tag = preg_replace( '/\sdata-srcset\s*=/i', ' srcset=', $tag, 1 );
		}
		if ( preg_match( '/\sdata-sizes\s*=/i', $tag ) ) {
			$tag = preg_replace( '/\ssizes\s*=\s*(["\'])[^"\']*\1/i', '', $tag, 1 );
			$tag = preg_replace( '/\sdata-sizes\s*=/i', ' sizes=', $tag, 1 );
		}
		$tag = preg_replace( '/\sdata-lazy-type\s*=\s*(["\'])[^"\']*\1/i', '', $tag, 1 );
		$tag = preg_replace_callback(
			'/\sclass\s*=\s*(["\'])(.*?)\1/is',
			function ( $c ) {
				$classes = preg_split( '/\s+/', trim( $c[2] ) );
				$classes = array_filter(
					$classes,
					function ( $x ) {
						return '' !== $x && ! in_array( $x, array( 'lazy', 'lazy-hidden', 'lazy-loaded' ), true );
					}
				);
				return $classes ? ' class=' . $c[1] . implode( ' ', $classes ) . $c[1] : '';
			},
			$tag,
			1
		);
		return $tag;
	}

	public function add_attr( $tag, $attr ) {
		if ( '/>' === substr( $tag, -2 ) ) {
			return rtrim( substr( $tag, 0, -2 ) ) . ' ' . $attr . ' />';
		}
		return rtrim( substr( $tag, 0, -1 ) ) . ' ' . $attr . '>';
	}

	/* ---------- шаг 2: неиспользуемые ассеты ---------- */

	/**
	 * Нужен ли FancyBox на странице: любая ссылка, которую он может перехватить
	 * (класс/rel/data с fancybox; href на картинку, PDF, SWF; YouTube/Vimeo/Dailymotion).
	 */
	private function fancybox_needed( $html ) {
		if ( ! preg_match_all( '/<a\b' . self::ATTRS . '>/i', $html, $anchors ) ) {
			return false;
		}
		foreach ( $anchors[0] as $a ) {
			if ( false !== stripos( $a, 'fancybox' ) ) {
				return true;
			}
			if ( ! preg_match( '/\shref\s*=\s*(?:(["\'])(.*?)\1|([^\s>]+))/is', $a, $h ) ) {
				continue;
			}
			$href = isset( $h[3] ) && '' !== $h[3] ? $h[3] : $h[2];
			$href = strtolower( trim( $href ) );
			$path = (string) wp_parse_url( $href, PHP_URL_PATH );
			if ( preg_match( '/\.(?:jpe?g|png|gif|webp|svg|pdf|swf)$/', $path ) ) {
				return true;
			}
			// Только ссылки на конкретное видео (их FancyBox умеет открывать), не на канал или профиль.
			if ( preg_match( '/youtube\.com\/(?:watch\?|embed\/|v\/|shorts\/)|youtu\.be\/[a-z0-9_-]{6,}|vimeo\.com\/(?:video\/)?\d+|dailymotion\.com\/video\//', $href ) ) {
				return true;
			}
		}
		return false;
	}

	private function asset_groups( $live_html ) {
		$groups = array();
		if ( $this->opt( 'strip_unused' ) ) {
			$groups['fancybox'] = array(
				'patterns'  => array( 'plugins/easy-fancybox/' ),
				'needed'    => $this->fancybox_needed( $live_html ),
				'extra_ids' => array(),
			);
			$groups['ytprefs'] = array(
				'patterns'  => array( 'plugins/youtube-embed-plus/' ),
				// Нужен только там, где плагин сам обработал embed (его классы) или есть его шорткод.
				'needed'    => (bool) preg_match( '/class\s*=\s*["\'][^"\']*(?:__youtube_prefs__|\bepyt-)|\[embedyt/i', $live_html ),
				'extra_ids' => array( 'fit-vids-style' ),
			);
		}
		if ( $this->opt( 'native_lazy' ) ) {
			$groups['a3'] = array(
				'patterns'  => array( 'plugins/a3-lazy-load/', 'uploads/sass/a3_lazy_load' ),
				'needed'    => false,
				'extra_ids' => array(),
			);
		}
		// Список «не подключать»: ловит и скрипты, вписанные в шаблон темы напрямую (мимо wp_enqueue).
		$manual = $this->patterns( 'dequeue_patterns' );
		if ( $manual ) {
			$groups['manual'] = array(
				'patterns'  => $manual,
				'needed'    => false,
				'extra_ids' => array(),
			);
		}
		return $groups;
	}

	private function strip_unused( $html ) {
		// Для решения «нужен/не нужен» смотрим только живую разметку, без комментариев и noscript.
		$live = $this->outside( $html, array( 'comment', 'noscript' ), function ( $p ) { return $p; } );
		$live = ( null === $live ) ? $html : preg_replace( '/<!--.*?-->|<noscript\b' . self::ATTRS . '>.*?<\/noscript\s*>/is', '', $html );
		if ( ! is_string( $live ) ) {
			$live = $html;
		}

		foreach ( $this->asset_groups( $live ) as $name => $g ) {
			if ( ! empty( $g['needed'] ) ) {
				$this->stats['kept'][] = $name;
				continue;
			}
			$alts = array();
			foreach ( $g['patterns'] as $p ) {
				$alts[] = preg_quote( $p, '/' );
			}
			$alt     = implode( '|', $alts );
			$handles = array();

			// <link ... href="...pattern...">
			$res = preg_replace_callback(
				'/<link\b' . self::ATTRS . '>[ \t]*\r?\n?/i',
				function ( $m ) use ( &$handles, $alt ) {
					if ( ! preg_match( '/\shref\s*=\s*["\'][^"\']*(?:' . $alt . ')/i', $m[0] ) ) {
						return $m[0];
					}
					if ( preg_match( '/\bid=["\']([^"\']+)-css["\']/i', $m[0], $id ) ) {
						$handles[] = $id[1];
					}
					return '';
				},
				$html
			);
			if ( is_string( $res ) ) {
				$html = $res;
			}

			// <script ... src="...pattern..."></script>
			$res = preg_replace_callback(
				'/<script\b' . self::ATTRS . '>\s*<\/script>[ \t]*\r?\n?/i',
				function ( $m ) use ( &$handles, $alt ) {
					if ( ! preg_match( '/\ssrc\s*=\s*["\'][^"\']*(?:' . $alt . ')/i', $m[0] ) ) {
						return $m[0];
					}
					if ( preg_match( '/\bid=["\']([^"\']+)-js["\']/i', $m[0], $id ) ) {
						$handles[] = $id[1];
					}
					return '';
				},
				$html
			);
			if ( is_string( $res ) ) {
				$html = $res;
			}

			// Привязанные inline-блоки: HANDLE-js-before/after/extra, HANDLE-inline-css и явные id.
			$ids = array();
			foreach ( array_unique( $handles ) as $h ) {
				$q     = preg_quote( $h, '/' );
				$ids[] = $q . '-js-(?:before|after|extra|translations)';
				$ids[] = $q . '-inline-css';
			}
			foreach ( $g['extra_ids'] as $x ) {
				$ids[] = preg_quote( $x, '/' );
			}
			if ( $ids ) {
				$res = preg_replace( '/<(script|style)\b[^>]*\bid=["\'](?:' . implode( '|', $ids ) . ')["\'][^>]*>.*?<\/\1\s*>[ \t]*\r?\n?/is', '', $html );
				if ( is_string( $res ) ) {
					$html = $res;
				}
			}
			$this->stats['stripped'][] = $name;
		}
		return $html;
	}

	/* ---------- шаг 2б: viewport ---------- */

	/**
	 * Разрешает масштабирование: убирает user-scalable=no|0, maximum-scale и minimum-scale из meta viewport.
	 * iOS и так игнорирует запрет с 2016 года, на Android он мешает увеличивать текст.
	 */
	private function viewport( $html ) {
		$self = $this;
		$res  = $this->outside(
			$html,
			array( 'comment', 'script', 'noscript', 'template', 'textarea' ),
			function ( $part ) use ( $self ) {
				return preg_replace_callback(
					'/<meta\b' . Kozlov_Perf::ATTRS . '>/i',
					function ( $m ) use ( $self ) {
						$tag = $m[0];
						if ( ! preg_match( '/\sname\s*=\s*(["\'])viewport\1/i', $tag ) ) {
							return $tag;
						}
						if ( ! preg_match( '/\scontent\s*=\s*(["\'])(.*?)\1/is', $tag, $c ) ) {
							return $tag;
						}
						$parts = array();
						foreach ( explode( ',', $c[2] ) as $p ) {
							$p = trim( $p );
							if ( '' === $p ) {
								continue;
							}
							$key = strtolower( trim( strtok( $p, '=' ) ) );
							if ( in_array( $key, array( 'user-scalable', 'maximum-scale', 'minimum-scale' ), true ) ) {
								continue;
							}
							$parts[] = $p;
						}
						$new = implode( ', ', $parts );
						if ( $new === trim( $c[2] ) ) {
							return $tag;
						}
						$self->stats['viewport'] = 'fixed';
						return str_replace( $c[0], ' content=' . $c[1] . $new . $c[1], $tag );
					},
					$part
				);
			}
		);
		return null === $res ? $html : $res;
	}

	/* ---------- шаг 3: дубли ---------- */

	/**
	 * Одинаковые статические подключения: остаётся первое.
	 * Скрипты сравниваются по src; стили по href + media (print и screen это разные подключения).
	 * Комментарии и noscript не участвуют, чтобы закомментированный тег не «занял» место живого.
	 */
	private function dedupe_assets( $html ) {
		$seen    = array();
		$removed = 0;

		$res = $this->outside(
			$html,
			array( 'comment', 'noscript', 'textarea' ),
			function ( $part ) use ( &$seen, &$removed ) {
				$part = preg_replace_callback(
					'/<script\b' . Kozlov_Perf::ATTRS . '>\s*<\/script\s*>[ \t]*\r?\n?/i',
					function ( $m ) use ( &$seen, &$removed ) {
						if ( ! preg_match( '/\ssrc\s*=\s*(["\'])([^"\']+)\1/i', $m[0], $s ) ) {
							return $m[0];
						}
						$type = preg_match( '/\stype\s*=\s*(["\'])([^"\']*)\1/i', $m[0], $t ) ? strtolower( $t[2] ) : '';
						$k    = 'js:' . $type . ':' . html_entity_decode( $s[2], ENT_QUOTES );
						if ( isset( $seen[ $k ] ) ) {
							$removed++;
							return '';
						}
						$seen[ $k ] = 1;
						return $m[0];
					},
					$part
				);
				if ( ! is_string( $part ) ) {
					return null;
				}
				return preg_replace_callback(
					'/<link\b' . Kozlov_Perf::ATTRS . '>[ \t]*\r?\n?/i',
					function ( $m ) use ( &$seen, &$removed ) {
						if ( ! preg_match( '/\srel\s*=\s*(["\'])[^"\']*\bstylesheet\b[^"\']*\1/i', $m[0] ) ) {
							return $m[0];
						}
						if ( ! preg_match( '/\shref\s*=\s*(["\'])([^"\']+)\1/i', $m[0], $h ) ) {
							return $m[0];
						}
						$media = preg_match( '/\smedia\s*=\s*(["\'])([^"\']*)\1/i', $m[0], $md ) ? strtolower( trim( $md[2] ) ) : 'all';
						$k     = 'css:' . $media . ':' . html_entity_decode( $h[2], ENT_QUOTES );
						if ( isset( $seen[ $k ] ) ) {
							$removed++;
							return '';
						}
						$seen[ $k ] = 1;
						return $m[0];
					},
					$part
				);
			}
		);
		if ( null === $res ) {
			return $html;
		}
		$this->stats['dup_assets'] = $removed;
		return $res;
	}

	/**
	 * Дубли Open Graph.
	 * og:url/title/type/description/locale/site_name: остаётся первый непустой.
	 * og:image: повторная картинка (тот же URL) удаляется вместе со своими og:image:* (width/height/type/alt),
	 * потому что эти свойства относятся к конкретной предшествующей картинке. Остальные og:* не трогаются.
	 * Комментарии и скрипты не участвуют.
	 */
	private function dedupe_og( $html ) {
		$live = $this->outside( $html, array( 'comment', 'script', 'noscript' ), function ( $p ) { return $p; } );
		if ( null === $live ) {
			return $html;
		}

		$re = '/<meta\b' . self::ATTRS . '>[ \t]*\r?\n?/i';
		if ( ! preg_match_all( $re, $html, $all, PREG_OFFSET_CAPTURE ) ) {
			return $html;
		}

		// Индексы мета-тегов, которые лежат внутри комментария или скрипта: их пропускаем.
		$dead = array();
		if ( preg_match_all( '/<!--.*?-->|<script\b' . self::ATTRS . '>.*?<\/script\s*>|<noscript\b' . self::ATTRS . '>.*?<\/noscript\s*>/is', $html, $blocks, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $blocks[0] as $b ) {
				$dead[] = array( $b[1], $b[1] + strlen( $b[0] ) );
			}
		}
		$in_dead = function ( $pos ) use ( $dead ) {
			foreach ( $dead as $d ) {
				if ( $pos >= $d[0] && $pos < $d[1] ) {
					return true;
				}
			}
			return false;
		};

		$single      = array( 'url', 'title', 'type', 'description', 'locale', 'site_name' );
		$tags        = array();
		foreach ( $all[0] as $idx => $m ) {
			if ( $in_dead( $m[1] ) ) {
				continue;
			}
			if ( ! preg_match( '/\sproperty\s*=\s*(["\'])og:([a-z0-9_:]+)\1/i', $m[0], $p ) ) {
				continue;
			}
			$content = '';
			if ( preg_match( '/\scontent\s*=\s*(["\'])(.*?)\1/is', $m[0], $c ) ) {
				$content = trim( $c[2] );
			}
			$tags[] = array( 'idx' => $idx, 'prop' => strtolower( $p[2] ), 'content' => $content );
		}
		if ( count( $tags ) < 2 ) {
			return $html;
		}

		$remove = array();

		// 1) Одиночные свойства: первый непустой.
		$by_prop = array();
		foreach ( $tags as $t ) {
			if ( in_array( $t['prop'], $single, true ) ) {
				$by_prop[ $t['prop'] ][] = $t;
			}
		}
		foreach ( $by_prop as $items ) {
			if ( count( $items ) < 2 ) {
				continue;
			}
			$keep = null;
			foreach ( $items as $it ) {
				if ( '' !== $it['content'] ) {
					$keep = $it['idx'];
					break;
				}
			}
			if ( null === $keep ) {
				$keep = $items[0]['idx'];
			}
			foreach ( $items as $it ) {
				if ( $it['idx'] !== $keep ) {
					$remove[] = $it['idx'];
				}
			}
		}

		// 2) og:image группами: повтор URL удаляется вместе со своими og:image:*.
		$seen_img = array();
		$dropping = false;
		foreach ( $tags as $t ) {
			if ( 'image' === $t['prop'] || 'image:url' === $t['prop'] ) {
				$dropping = isset( $seen_img[ $t['content'] ] );
				$seen_img[ $t['content'] ] = 1;
				if ( $dropping ) {
					$remove[] = $t['idx'];
				}
			} elseif ( 0 === strpos( $t['prop'], 'image:' ) ) {
				if ( $dropping ) {
					$remove[] = $t['idx'];
				}
			} else {
				$dropping = false;
			}
		}

		$remove = array_unique( $remove );
		if ( ! $remove ) {
			return $html;
		}
		rsort( $remove );
		foreach ( $remove as $idx ) {
			$html = substr_replace( $html, '', $all[0][ $idx ][1], strlen( $all[0][ $idx ][0] ) );
		}
		$this->stats['dup_og'] = count( $remove );
		return $html;
	}

	/* ------------------------------------------------------------------ */
	/* Админка                                                             */
	/* ------------------------------------------------------------------ */

	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=kozlov-perf' ) ) . '">Настройки</a>' );
		return $links;
	}

	public function admin_menu() {
		add_options_page( 'Kozlov Perf', 'Kozlov Perf', 'manage_options', 'kozlov-perf', array( $this, 'render_page' ) );
	}

	public function admin_init() {
		register_setting(
			'kozlov_perf',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	public function sanitize( $in ) {
		$in  = is_array( $in ) ? $in : array();
		$out = array();
		foreach ( array( 'enabled', 'native_lazy', 'thumb_srcset', 'strip_unused', 'emoji', 'head_cleanup', 'dedupe', 'fix_ver', 'block_css', 'mobile_zoom' ) as $k ) {
			$out[ $k ] = empty( $in[ $k ] ) ? 0 : 1;
		}
		$out['eager_count'] = isset( $in['eager_count'] ) ? max( 0, min( 50, (int) $in['eager_count'] ) ) : 8;
		foreach ( array( 'dequeue_patterns', 'defer_patterns', 'preconnect' ) as $k ) {
			$out[ $k ] = isset( $in[ $k ] ) ? sanitize_textarea_field( $in[ $k ] ) : '';
		}
		return $out;
	}

	private function plugin_state( $file ) {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return is_plugin_active( $file ) ? '<span style="color:#b32d2e">активен</span>' : '<span style="color:#1a7f37">не активен</span>';
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$o  = $this->opts;
		$n  = self::OPTION;
		$cb = function ( $key, $label, $hint = '' ) use ( $o, $n ) {
			printf(
				'<label style="display:block;margin:6px 0"><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s> %4$s</label>%5$s',
				esc_attr( $n ),
				esc_attr( $key ),
				checked( ! empty( $o[ $key ] ), true, false ),
				esc_html( $label ),
				$hint ? '<p class="description" style="margin:0 0 10px 24px">' . wp_kses_post( $hint ) . '</p>' : ''
			);
		};
		?>
		<div class="wrap">
			<h1>Kozlov Perf <small style="font-weight:normal;color:#666">v<?php echo esc_html( self::VERSION ); ?></small></h1>

			<div style="background:#fff;border:1px solid #c3c4c7;padding:10px 14px;margin:12px 0;max-width:820px">
				<strong>Состояние плагинов, которые Kozlov Perf обходит:</strong>
				<ul style="margin:6px 0 0 18px;list-style:disc">
					<li>a3 Lazy Load: <?php echo $this->plugin_state( 'a3-lazy-load/a3-lazy-load.php' ); // phpcs:ignore ?> (рекомендуется деактивировать, плагин заменяет его нативной ленивой загрузкой)</li>
					<li>Easy FancyBox: <?php echo $this->plugin_state( 'easy-fancybox/easy-fancybox.php' ); // phpcs:ignore ?> (его файлы убираются только со страниц без ссылок, которые он может перехватить)</li>
					<li>YouTube Embed Plus: <?php echo $this->plugin_state( 'youtube-embed-plus/youtube.php' ); // phpcs:ignore ?> (его файлы убираются со страниц, где нет его embed)</li>
				</ul>
				<p style="margin:8px 0 0">Проверка: откройте любую страницу с <code>?kperf=off</code>, чтобы увидеть её без оптимизаций. В конце исходного кода страницы есть комментарий <code>&lt;!-- Kozlov Perf … --&gt;</code> с тем, что было сделано.</p>
			</div>

			<form method="post" action="options.php" style="max-width:820px">
				<?php settings_fields( 'kozlov_perf' ); ?>

				<h2 class="title">Главный выключатель</h2>
				<?php $cb( 'enabled', 'Плагин включён', 'Снятая галочка полностью отключает всё ниже, файлы и настройки остаются.' ); ?>

				<h2 class="title">Картинки</h2>
				<?php $cb( 'native_lazy', 'Нативная ленивая загрузка (loading="lazy") вместо a3 Lazy Load', 'Разметка a3 (заглушка + data-src) возвращается к обычной, его скрипты и стили не грузятся. Первые N картинок страницы грузятся сразу, остальные лениво. Разметка внутри script, noscript, style, template, textarea и комментариев не трогается.' ); ?>
				<p style="margin:0 0 10px 24px">
					<label>Сколько первых картинок грузить сразу: <input type="number" min="0" max="50" name="<?php echo esc_attr( $n ); ?>[eager_count]" value="<?php echo esc_attr( (int) $o['eager_count'] ); ?>" style="width:70px"></label>
					<span class="description">(логотип + слайдер + первый ряд афиши, обычно 6–10)</span>
				</p>
				<?php $cb( 'thumb_srcset', 'У превью (src вида …-450x338.jpg) убирать srcset и sizes', 'Без этого браузер на экранах retina берёт из srcset оригинал 200–850 КБ вместо превью 40 КБ: на главной это сотни мегабайт при прокрутке. Превью 450 px для карточки шириной ~200 px это уже 2x.' ); ?>

				<h2 class="title">Лишние скрипты и стили</h2>
				<?php $cb( 'strip_unused', 'Убирать Easy FancyBox и YouTube Embed Plus со страниц, где они не нужны', 'Решение по готовому HTML страницы. FancyBox остаётся, если есть хоть одна ссылка, которую он может перехватить: класс/rel/data с fancybox, ссылка на картинку, PDF, SWF, YouTube, Vimeo, Dailymotion. YouTube Embed Plus остаётся, если на странице есть его embed (классы __youtube_prefs__/epyt-) или шорткод [embedyt].' ); ?>
				<p style="margin:0 0 10px 24px">
					<label>Не подключать скрипты/стили, в адресе которых есть (по одному на строку):<br>
					<textarea name="<?php echo esc_attr( $n ); ?>[dequeue_patterns]" rows="3" style="width:100%;max-width:600px"><?php echo esc_textarea( $o['dequeue_patterns'] ); ?></textarea></label>
					<span class="description">Снимаются из очереди WordPress и вырезаются из HTML (ловит и скрипты, вписанные в шаблон темы напрямую). По умолчанию: jquery.animateNumber (в теме его вызов закомментирован). jQuery защищён от попадания в этот список.</span>
				</p>
				<p style="margin:0 0 10px 24px">
					<label>Добавлять defer скриптам, в адресе которых есть (по одному на строку):<br>
					<textarea name="<?php echo esc_attr( $n ); ?>[defer_patterns]" rows="3" style="width:100%;max-width:600px"><?php echo esc_textarea( $o['defer_patterns'] ); ?></textarea></label>
					<span class="description">По умолчанию пусто. Используется штатная стратегия загрузки WordPress 6.3+: ядро применит defer только если зависимые скрипты тоже отложены и нет inline-хвоста. jQuery исключён.</span>
				</p>
				<?php $cb( 'emoji', 'Отключить wp-emoji (скрипт и стили эмодзи WordPress)' ); ?>
				<?php $cb( 'block_css', 'Не подключать CSS блоков Гутенберга (wp-block-library, global-styles)', 'По умолчанию выключено. Включать только после проверки: если анонсы набраны блоками, стили могут понадобиться.' ); ?>

				<h2 class="title">Мобильные</h2>
				<?php $cb( 'mobile_zoom', 'Разрешить масштабирование и убрать случайный зум', 'Из meta viewport убираются user-scalable=no и maximum-scale (iOS их и так игнорирует, на Android они мешают увеличивать текст). Кнопкам, ссылкам и полям ставится touch-action: manipulation: отклик без задержки и без зума по двойному тапу, пинч-зум остаётся. Полям ввода на тач-экранах 16px, чтобы iOS не увеличивал страницу при фокусе.' ); ?>

				<h2 class="title">Сеть и заголовки</h2>
				<p style="margin:0 0 10px 0">
					<label>Preconnect к доменам (по одному на строку):<br>
					<textarea name="<?php echo esc_attr( $n ); ?>[preconnect]" rows="5" style="width:100%;max-width:600px"><?php echo esc_textarea( $o['preconnect'] ); ?></textarea></label>
					<span class="description">Пусто = не добавлять.</span>
				</p>
				<?php $cb( 'head_cleanup', 'Убрать из head RSD, wlwmanifest, shortlink, версию WordPress (generator) и заголовок X-Powered-By' ); ?>
				<?php $cb( 'fix_ver', 'Исправлять задвоенный параметр ver в адресах скриптов и стилей (…?ver=X&ver=X)' ); ?>

				<h2 class="title">Дубли</h2>
				<?php $cb( 'dedupe', 'Убирать повторные <script src> и <link rel=stylesheet> с одинаковым адресом, а также дубли Open Graph (og:*)', 'Стили сравниваются вместе с media (print и screen это разные подключения). Комментарии и noscript не учитываются. Для og:url/title/type/description/locale/site_name остаётся первый непустой тег; повторная og:image удаляется вместе со своими og:image:width/height/type.' ); ?>

				<?php submit_button( 'Сохранить' ); ?>
			</form>
		</div>
		<?php
	}
}

Kozlov_Perf::instance();

register_uninstall_hook( __FILE__, 'kozlov_perf_uninstall' );
function kozlov_perf_uninstall() {
	delete_option( Kozlov_Perf::OPTION );
}
