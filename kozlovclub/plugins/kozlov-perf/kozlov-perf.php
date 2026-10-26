<?php
/**
 * Plugin Name:       Kozlov Perf
 * Plugin URI:        https://kozlovclub.ru/
 * Description:       Изолированные оптимизации загрузки: нативная ленивая загрузка вместо a3 Lazy Load, уборка неиспользуемых скриптов (FancyBox, YouTube Embed Plus, emoji), preconnect, дедупликация тегов. Не меняет тему, данные и другие плагины. Отключается галочкой в настройках, параметром ?kperf=off или деактивацией.
 * Version:           1.0.0
 * Author:            Kozlov Club
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 * Text Domain:       kozlov-perf
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Kozlov_Perf {

	const OPTION  = 'kozlov_perf_options';
	const VERSION = '1.0.0';

	/** @var Kozlov_Perf|null */
	private static $instance = null;

	/** @var array */
	private $opts = array();

	/** @var array Статистика для HTML-комментария в конце страницы. */
	private $stats = array();

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
			'strip_unused'     => 1,
			'dequeue_patterns' => "jquery.animateNumber",
			'defer_patterns'   => "wpglobus/includes/js/",
			'emoji'            => 1,
			'head_cleanup'     => 1,
			'preconnect'       => "https://fonts.googleapis.com\nhttps://fonts.gstatic.com\nhttps://mc.yandex.ru\nhttps://top-fwz1.mail.ru\nhttps://ticketscloud.com",
			'dedupe'           => 1,
			'fix_ver'          => 1,
			'block_css'        => 0,
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

		if ( $this->opt( 'fix_ver' ) ) {
			add_filter( 'script_loader_src', array( $this, 'fix_ver' ), 99 );
			add_filter( 'style_loader_src', array( $this, 'fix_ver' ), 99 );
		}

		if ( $this->patterns( 'defer_patterns' ) ) {
			add_filter( 'script_loader_tag', array( $this, 'defer_tag' ), 99, 3 );
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

	/** defer для скриптов из списка; jQuery и скрипты с inline-хвостом (-js-after) не трогаем. */
	public function defer_tag( $tag, $handle, $src ) {
		if ( in_array( $handle, array( 'jquery', 'jquery-core', 'jquery-migrate' ), true ) ) {
			return $tag;
		}
		if ( ! $this->matches( $src, $this->patterns( 'defer_patterns' ) ) ) {
			return $tag;
		}
		if ( false !== strpos( $tag, '-js-after' ) || false !== stripos( $tag, 'type="module"' ) || false !== stripos( $tag, "type='module'" ) ) {
			return $tag;
		}
		$new = preg_replace( '/<script\b(?![^>]*\b(?:defer|async)\b)([^>]*\bsrc=)/i', '<script defer$1', $tag, 1 );
		return is_string( $new ) ? $new : $tag;
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

		$scripts = wp_scripts();
		foreach ( $scripts->registered as $handle => $dep ) {
			if ( in_array( $handle, $protected, true ) ) {
				continue;
			}
			if ( ! empty( $dep->src ) && $this->matches( $dep->src, $patterns ) ) {
				wp_dequeue_script( $handle );
				$this->stats['dequeued'][] = $handle;
			}
		}
		$styles = wp_styles();
		foreach ( $styles->registered as $handle => $dep ) {
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
		if ( ! $this->opt( 'native_lazy' ) && ! $this->opt( 'strip_unused' ) && ! $this->opt( 'dedupe' ) ) {
			return;
		}
		ob_start( array( $this, 'process_html' ) );
	}

	/**
	 * Callback буфера. Любая ошибка внутри возвращает исходный HTML без изменений.
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
			if ( $this->opt( 'strip_unused' ) || $this->opt( 'native_lazy' ) ) {
				$out = $this->step( $out, 'strip_unused' );
			}
			if ( $this->opt( 'dedupe' ) ) {
				$out = $this->step( $out, 'dedupe_assets' );
				$out = $this->step( $out, 'dedupe_og' );
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

	/* ---------- шаг 1: картинки ---------- */

	private function images( $html ) {
		$eager = max( 0, (int) $this->opt( 'eager_count' ) );

		// <noscript> и <template> не трогаем: там пиксели счётчиков и запасные копии картинок.
		$parts = preg_split( '/(<noscript\b[^>]*>.*?<\/noscript>|<template\b[^>]*>.*?<\/template>)/is', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( ! is_array( $parts ) ) {
			return $html;
		}

		$count    = 0;
		$lazied   = 0;
		$reverted = 0;
		$self     = $this;

		foreach ( $parts as $i => $part ) {
			if ( $i % 2 === 1 ) {
				continue;
			}
			$res = preg_replace_callback(
				'/<(img|iframe|video|source)\b[^>]*>/i',
				function ( $m ) use ( &$count, &$lazied, &$reverted, $eager, $self ) {
					$tag  = $m[0];
					$name = strtolower( $m[1] );

					if ( false !== stripos( $tag, 'data-lazy-type' ) || false !== stripos( $tag, 'lazy_placeholder' ) ) {
						$tag = $self->revert_a3( $tag );
						$reverted++;
					}
					if ( 'img' !== $name ) {
						return $tag;
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
			if ( is_string( $res ) ) {
				$parts[ $i ] = $res;
			}
		}

		$this->stats['img_total'] = $count;
		$this->stats['img_lazy']  = $lazied;
		$this->stats['a3_reverted'] = $reverted;

		return implode( '', $parts );
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

	private function asset_groups() {
		$groups = array();
		if ( $this->opt( 'strip_unused' ) ) {
			$groups['fancybox'] = array(
				'patterns'  => array( 'plugins/easy-fancybox/' ),
				// Нужен, если на странице есть ссылки-триггеры FancyBox или прямые ссылки на картинки (галереи).
				'needed'    => '/<a\b[^>]*(?:class=["\'][^"\']*fancybox|rel=["\'][^"\']*fancybox|data-fancybox)[^>]*>|<a\b[^>]*href=["\'][^"\']+\.(?:jpe?g|png|gif|webp)(?:\?[^"\']*)?["\']/i',
				'extra_ids' => array(),
			);
			$groups['ytprefs'] = array(
				'patterns'  => array( 'plugins/youtube-embed-plus/' ),
				// Нужен только там, где плагин сам обработал embed (его классы) или есть его шорткод.
				'needed'    => '/class=["\'][^"\']*(?:__youtube_prefs__|\bepyt-)|\[embedyt/i',
				'extra_ids' => array( 'fit-vids-style' ),
			);
		}
		if ( $this->opt( 'native_lazy' ) ) {
			$groups['a3'] = array(
				'patterns'  => array( 'plugins/a3-lazy-load/', 'uploads/sass/a3_lazy_load' ),
				'needed'    => null,
				'extra_ids' => array(),
			);
		}
		return $groups;
	}

	private function strip_unused( $html ) {
		foreach ( $this->asset_groups() as $name => $g ) {
			if ( ! empty( $g['needed'] ) && preg_match( $g['needed'], $html ) ) {
				$this->stats['kept'][] = $name;
				continue;
			}
			$alts    = array();
			foreach ( $g['patterns'] as $p ) {
				$alts[] = preg_quote( $p, '/' );
			}
			$alt     = implode( '|', $alts );
			$handles = array();

			// <link ... href="...pattern...">
			$res = preg_replace_callback(
				'/<link\b[^>]*\bhref=["\'][^"\']*(?:' . $alt . ')[^"\']*["\'][^>]*>\s*/i',
				function ( $m ) use ( &$handles ) {
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
				'/<script\b[^>]*\bsrc=["\'][^"\']*(?:' . $alt . ')[^"\']*["\'][^>]*>\s*<\/script>\s*/i',
				function ( $m ) use ( &$handles ) {
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
				$res = preg_replace( '/<(script|style)\b[^>]*\bid=["\'](?:' . implode( '|', $ids ) . ')["\'][^>]*>.*?<\/\1>\s*/is', '', $html );
				if ( is_string( $res ) ) {
					$html = $res;
				}
			}
			$this->stats['stripped'][] = $name;
		}
		return $html;
	}

	/* ---------- шаг 3: дубли ---------- */

	/** Одинаковые статические <script src> и <link rel=stylesheet href>: остаётся первый. */
	private function dedupe_assets( $html ) {
		$seen    = array();
		$removed = 0;

		$res = preg_replace_callback(
			'/<script\b[^>]*\bsrc=(["\'])([^"\']+)\1[^>]*>\s*<\/script>\s*/i',
			function ( $m ) use ( &$seen, &$removed ) {
				$k = 'js:' . html_entity_decode( $m[2], ENT_QUOTES );
				if ( isset( $seen[ $k ] ) ) {
					$removed++;
					return '';
				}
				$seen[ $k ] = 1;
				return $m[0];
			},
			$html
		);
		if ( is_string( $res ) ) {
			$html = $res;
		}

		$res = preg_replace_callback(
			'/<link\b[^>]*>\s*/i',
			function ( $m ) use ( &$seen, &$removed ) {
				if ( ! preg_match( '/\brel=(["\'])[^"\']*\bstylesheet\b[^"\']*\1/i', $m[0] ) ) {
					return $m[0];
				}
				if ( ! preg_match( '/\bhref=(["\'])([^"\']+)\1/i', $m[0], $h ) ) {
					return $m[0];
				}
				$k = 'css:' . html_entity_decode( $h[2], ENT_QUOTES );
				if ( isset( $seen[ $k ] ) ) {
					$removed++;
					return '';
				}
				$seen[ $k ] = 1;
				return $m[0];
			},
			$html
		);
		if ( is_string( $res ) ) {
			$html = $res;
		}

		$this->stats['dup_assets'] = $removed;
		return $html;
	}

	/** Дубли Open Graph: для url/title/type/description/locale/site_name остаётся первый непустой, для og:image* убираются точные повторы. */
	private function dedupe_og( $html ) {
		if ( ! preg_match_all( '/<meta\b[^>]*\bproperty=(["\'])og:([a-z0-9_:]+)\1[^>]*>[ \t]*\r?\n?/i', $html, $all, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
			return $html;
		}
		$single = array( 'url', 'title', 'type', 'description', 'locale', 'site_name' );
		$groups = array();
		foreach ( $all as $idx => $m ) {
			$prop    = strtolower( $m[2][0] );
			$content = '';
			if ( preg_match( '/\bcontent=(["\'])(.*?)\1/is', $m[0][0], $c ) ) {
				$content = trim( $c[2] );
			}
			$groups[ $prop ][] = array( 'idx' => $idx, 'content' => $content );
		}

		$remove = array();
		foreach ( $groups as $prop => $items ) {
			if ( count( $items ) < 2 ) {
				continue;
			}
			if ( in_array( $prop, $single, true ) ) {
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
			} else {
				$seen = array();
				foreach ( $items as $it ) {
					if ( isset( $seen[ $it['content'] ] ) ) {
						$remove[] = $it['idx'];
					}
					$seen[ $it['content'] ] = 1;
				}
			}
		}
		if ( ! $remove ) {
			return $html;
		}
		rsort( $remove );
		foreach ( $remove as $idx ) {
			$html = substr_replace( $html, '', $all[ $idx ][0][1], strlen( $all[ $idx ][0][0] ) );
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
		foreach ( array( 'enabled', 'native_lazy', 'strip_unused', 'emoji', 'head_cleanup', 'dedupe', 'fix_ver', 'block_css' ) as $k ) {
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
		$o = $this->opts;
		$n = self::OPTION;
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
					<li>Easy FancyBox: <?php echo $this->plugin_state( 'easy-fancybox/easy-fancybox.php' ); // phpcs:ignore ?> (его файлы убираются только со страниц без галерей и ссылок на картинки)</li>
					<li>YouTube Embed Plus: <?php echo $this->plugin_state( 'youtube-embed-plus/youtube.php' ); // phpcs:ignore ?> (его файлы убираются со страниц, где нет его embed)</li>
				</ul>
				<p style="margin:8px 0 0">Проверка: откройте любую страницу с <code>?kperf=off</code>, чтобы увидеть её без оптимизаций. В конце исходного кода страницы есть комментарий <code>&lt;!-- Kozlov Perf … --&gt;</code> с тем, что было сделано.</p>
			</div>

			<form method="post" action="options.php" style="max-width:820px">
				<?php settings_fields( 'kozlov_perf' ); ?>

				<h2 class="title">Главный выключатель</h2>
				<?php $cb( 'enabled', 'Плагин включён', 'Снятая галочка полностью отключает всё ниже, файлы и настройки остаются.' ); ?>

				<h2 class="title">Картинки</h2>
				<?php $cb( 'native_lazy', 'Нативная ленивая загрузка (loading="lazy") вместо a3 Lazy Load', 'Разметка a3 (заглушка + data-src) возвращается к обычной, его скрипты и стили не грузятся. Первые N картинок страницы грузятся сразу, остальные лениво.' ); ?>
				<p style="margin:0 0 10px 24px">
					<label>Сколько первых картинок грузить сразу: <input type="number" min="0" max="50" name="<?php echo esc_attr( $n ); ?>[eager_count]" value="<?php echo esc_attr( (int) $o['eager_count'] ); ?>" style="width:70px"></label>
					<span class="description">(логотип + слайдер + первый ряд афиши, обычно 6–10)</span>
				</p>

				<h2 class="title">Лишние скрипты и стили</h2>
				<?php $cb( 'strip_unused', 'Убирать Easy FancyBox и YouTube Embed Plus со страниц, где они не нужны', 'Решение принимается по готовому HTML страницы: FancyBox остаётся, если есть ссылки с классом fancybox или прямые ссылки на картинки; YouTube Embed Plus остаётся, если на странице есть его embed (классы __youtube_prefs__/epyt) или шорткод [embedyt].' ); ?>
				<p style="margin:0 0 10px 24px">
					<label>Не подключать скрипты/стили, в адресе которых есть (по одному на строку):<br>
					<textarea name="<?php echo esc_attr( $n ); ?>[dequeue_patterns]" rows="3" style="width:100%;max-width:600px"><?php echo esc_textarea( $o['dequeue_patterns'] ); ?></textarea></label>
					<span class="description">По умолчанию: jquery.animateNumber (в теме его вызов закомментирован). jQuery защищён от попадания в этот список.</span>
				</p>
				<p style="margin:0 0 10px 24px">
					<label>Добавлять defer скриптам, в адресе которых есть (по одному на строку):<br>
					<textarea name="<?php echo esc_attr( $n ); ?>[defer_patterns]" rows="3" style="width:100%;max-width:600px"><?php echo esc_textarea( $o['defer_patterns'] ); ?></textarea></label>
					<span class="description">jQuery и скрипты с inline-хвостом (-js-after) никогда не получают defer.</span>
				</p>
				<?php $cb( 'emoji', 'Отключить wp-emoji (скрипт и стили эмодзи WordPress)' ); ?>
				<?php $cb( 'block_css', 'Не подключать CSS блоков Гутенберга (wp-block-library, global-styles)', 'По умолчанию выключено. Включать только после проверки: если анонсы набраны блоками, стили могут понадобиться.' ); ?>

				<h2 class="title">Сеть и заголовки</h2>
				<p style="margin:0 0 10px 0">
					<label>Preconnect к доменам (по одному на строку):<br>
					<textarea name="<?php echo esc_attr( $n ); ?>[preconnect]" rows="5" style="width:100%;max-width:600px"><?php echo esc_textarea( $o['preconnect'] ); ?></textarea></label>
					<span class="description">Пусто = не добавлять.</span>
				</p>
				<?php $cb( 'head_cleanup', 'Убрать из head RSD, wlwmanifest, shortlink, версию WordPress (generator) и заголовок X-Powered-By' ); ?>
				<?php $cb( 'fix_ver', 'Исправлять задвоенный параметр ver в адресах скриптов и стилей (…?ver=X&ver=X)' ); ?>

				<h2 class="title">Дубли</h2>
				<?php $cb( 'dedupe', 'Убирать повторные <script src> и <link rel=stylesheet> с одинаковым адресом, а также дубли Open Graph (og:*)', 'Для og:url/title/type/description/locale/site_name остаётся первый непустой тег, у og:image удаляются только точные повторы.' ); ?>

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
