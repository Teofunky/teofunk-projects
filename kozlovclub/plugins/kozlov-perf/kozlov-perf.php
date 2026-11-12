<?php
/**
 * Plugin Name:       Kozlov Perf
 * Plugin URI:        https://kozlovclub.ru/
 * Description:       Изолированные оптимизации загрузки: нативная ленивая загрузка вместо a3 Lazy Load, превью нужного размера для retina (srcset только из лёгких вариантов с WebP), уборка неиспользуемых скриптов (FancyBox, YouTube Embed Plus, emoji), preconnect, дедупликация тегов. Не меняет тему, данные и другие плагины. Отключается галочкой в настройках, параметром ?kperf=off или деактивацией.
 * Version:           1.2.2
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
	const VERSION = '1.2.2';

	/**
	 * Опция-кеш вариантов для превью без srcset: адрес превью → вложение и srcset WordPress. Поиск вложения по
	 * адресу файла на Beget стоит 60–70 мс, а кеш объектов (Redis в W3TC) попаданий не даёт, поэтому кеш свой.
	 * Не автозагружается, читается одним запросом только на странице, где есть такие превью.
	 */
	const FILL_OPTION = 'kozlov_perf_fill_cache';

	/**
	 * sizes для карточек афиши главной (size-category-thumb2): ширина картинки в теме KozlovCLub по замеру
	 * 12.11.2026 (2 колонки до 750 px, 3 до 1030, 2 до 1130, 3 до 1490, 4 до 1800, дальше 375 px).
	 */
	const CARD_SIZES = '(max-width: 750px) calc(50vw - 22px), (max-width: 1030px) calc(33.33vw - 50px), (max-width: 1130px) calc(50vw - 90px), (max-width: 1490px) calc(33.33vw - 80px), (max-width: 1800px) calc(25vw - 75px), 375px';

	/** Сколько превью без srcset за одну страницу можно сопоставить с вложением (запросы к базе). */
	const FILL_LIMIT = 40;

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
			'srcset_mode'      => 'cap',
			'srcset_max'       => 1024,
			'srcset_webp'      => 1,
			'srcset_fill'      => 1,
			'card_sizes'       => self::CARD_SIZES,
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
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		// Настройки 1.1.0: галочка thumb_srcset. Снятая галочка значила «srcset не трогать».
		if ( ! isset( $saved['srcset_mode'] ) && isset( $saved['thumb_srcset'] ) && empty( $saved['thumb_srcset'] ) ) {
			$saved['srcset_mode'] = 'keep';
		}
		unset( $saved['thumb_srcset'] );
		$this->opts = wp_parse_args( $saved, self::defaults() );

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

	/** Работает ли обработка srcset у превью (ограничение, удаление или дополнение). */
	private function srcset_on() {
		return 'keep' !== $this->opt( 'srcset_mode' ) || (bool) $this->opt( 'srcset_fill' );
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
		// Второй, ранний буфер (приоритет 0 = снаружи плагинов, которые собирают страницу в своём буфере,
		// например Kozlov Event Page с приоритетом 1). Он видит их разметку и дописывает srcset превью,
		// которые основной буфер не видел. Внешний буфер WebP Express (init, 1) работает ещё позже.
		add_action( 'template_redirect', array( $this, 'maybe_buffer_late' ), 0 );
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

	private function can_buffer() {
		if ( ! $this->active() ) {
			return false;
		}
		if ( is_admin() || is_feed() || is_embed() || is_robots() || is_trackback() || is_preview() || is_customize_preview() ) {
			return false;
		}
		if ( ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {
			return false;
		}
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'GET' !== strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) ) {
			return false;
		}
		return true;
	}

	public function maybe_buffer_late() {
		if ( $this->can_buffer() && 'cap' === $this->opt( 'srcset_mode' ) && $this->opt( 'srcset_fill' ) ) {
			ob_start( array( $this, 'process_late' ) );
		}
	}

	/** Ранний буфер: только srcset превью, которые основной проход не видел (у них нет метки data-kperf). */
	public function process_late( $html ) {
		try {
			if ( ! $this->html_page( $html ) ) {
				return $html;
			}
			$t0 = microtime( true );
			foreach ( array( 'srcset_capped', 'srcset_dropped', 'srcset_filled' ) as $k ) {
				$this->stats[ $k ] = 0;
			}
			$out = $this->step( $html, 'srcset_only' );
			$this->fill_cache_save();
			$sum = array();
			foreach ( array( 'srcset_capped', 'srcset_dropped', 'srcset_filled' ) as $k ) {
				if ( ! empty( $this->stats[ $k ] ) ) {
					$sum[] = $k . '=' . $this->stats[ $k ];
				}
			}
			if ( $sum && $this->lookups ) {
				$sum[] = 'db_lookups=' . $this->lookups;
			}
			if ( ! $sum ) {
				return $out;
			}
			$comment = "\n<!-- Kozlov Perf " . self::VERSION . ' late: ' . round( ( microtime( true ) - $t0 ) * 1000, 1 ) . 'ms; ' . implode( '; ', $sum ) . " -->\n";
			$pos     = strripos( $out, '</body>' );
			return ( false !== $pos ) ? substr_replace( $out, $comment, $pos, 0 ) : $out . $comment;
		} catch ( \Throwable $e ) {
			return $html;
		}
	}

	/** Полная HTML-страница (не JSON, не кусок буфера). */
	private function html_page( $html ) {
		if ( ! is_string( $html ) || strlen( $html ) < 512 || false === stripos( $html, '</html' ) ) {
			return false;
		}
		foreach ( headers_list() as $h ) {
			if ( 0 === stripos( $h, 'content-type:' ) && false === stripos( $h, 'text/html' ) ) {
				return false;
			}
		}
		return true;
	}

	public function maybe_buffer() {
		if ( ! $this->can_buffer() ) {
			return;
		}
		if ( ! $this->opt( 'native_lazy' ) && ! $this->srcset_on() && ! $this->opt( 'strip_unused' ) && ! $this->opt( 'dedupe' ) && ! $this->opt( 'mobile_zoom' ) && ! $this->patterns( 'dequeue_patterns' ) ) {
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

			if ( $this->opt( 'native_lazy' ) || $this->srcset_on() ) {
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

			$this->fill_cache_save();
			if ( $this->lookups ) {
				$this->stats['db_lookups'] = $this->lookups;
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
		$lazy     = (bool) $this->opt( 'native_lazy' );
		$eager    = max( 0, (int) $this->opt( 'eager_count' ) );
		$a3_page  = $lazy && ( false !== stripos( $html, 'data-lazy-type' ) || false !== stripos( $html, 'lazy_placeholder' ) );
		$count    = 0;
		$lazied   = 0;
		$reverted = 0;
		$self     = $this;

		$this->stats['srcset_capped']  = 0;
		$this->stats['srcset_dropped'] = 0;
		$this->stats['srcset_filled']  = 0;

		if ( $this->srcset_on() ) {
			$html = $this->step( $html, 'pictures' );
		}

		$res = $this->outside(
			$html,
			array( 'comment', 'script', 'noscript', 'style', 'template', 'textarea' ),
			function ( $part ) use ( &$count, &$lazied, &$reverted, $lazy, $eager, $a3_page, $self ) {
				return preg_replace_callback(
					'/<(img|iframe|video|source)\b' . Kozlov_Perf::ATTRS . '>/i',
					function ( $m ) use ( &$count, &$lazied, &$reverted, $lazy, $eager, $a3_page, $self ) {
						$tag  = $m[0];
						$name = strtolower( $m[1] );

						if ( $lazy ) {
							$is_a3 = ( false !== stripos( $tag, 'data-lazy-type' ) || false !== stripos( $tag, 'lazy_placeholder' ) );
							// <source data-srcset> внутри <picture> a3 не помечает: восстанавливаем, если на странице есть a3-разметка.
							if ( ! $is_a3 && 'source' === $name && $a3_page && preg_match( '/\sdata-srcset\s*=/i', $tag ) && ! preg_match( '/\ssrcset\s*=/i', $tag ) ) {
								$is_a3 = true;
							}
							if ( $is_a3 ) {
								$tag = $self->revert_a3( $tag );
								$reverted++;
							}
						}
						if ( 'img' !== $name ) {
							return $tag;
						}

						$tag = $self->thumb_srcset( $tag );

						if ( $lazy ) {
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
						}

						return $self->sizes_auto( $tag );
					},
					$part
				);
			}
		);

		if ( null === $res ) {
			return $html;
		}
		if ( $this->srcset_on() ) {
			$res = $this->step( $res, 'sync_sources' );
		}
		if ( $lazy ) {
			$this->stats['img_total']   = $count;
			$this->stats['img_lazy']    = $lazied;
			$this->stats['a3_reverted'] = $reverted;
		}
		foreach ( array( 'srcset_capped', 'srcset_dropped', 'srcset_filled' ) as $k ) {
			if ( empty( $this->stats[ $k ] ) ) {
				unset( $this->stats[ $k ] );
			}
		}
		return $res;
	}

	/* ---------- превью и srcset ---------- */

	/** Значение атрибута тега или null. */
	public function attr( $tag, $name ) {
		if ( preg_match( '/\s' . preg_quote( $name, '/' ) . '\s*=\s*(["\'])(.*?)\1/is', $tag, $m ) ) {
			return html_entity_decode( $m[2], ENT_QUOTES, 'UTF-8' );
		}
		return null;
	}

	/** Ставит атрибут (заменяет существующий или добавляет). */
	public function set_attr( $tag, $name, $value ) {
		$pair = $name . '="' . esc_attr( $value ) . '"';
		$re   = '/\s' . preg_quote( $name, '/' ) . '\s*=\s*(["\'])[^"\']*\1/i';
		if ( preg_match( $re, $tag ) ) {
			return preg_replace_callback(
				$re,
				function () use ( $pair ) {
					return ' ' . $pair;
				},
				$tag,
				1
			);
		}
		return $this->add_attr( $tag, $pair );
	}

	public function drop_attr( $tag, $name ) {
		return preg_replace( '/\s' . preg_quote( $name, '/' ) . '\s*=\s*(["\'])[^"\']*\1/i', '', $tag, 1 );
	}

	/** Ширина и высота превью из имени файла (…-450x338.jpg) или null для оригинала. */
	private function thumb_dims( $url ) {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( preg_match( '/-(\d{2,4})x(\d{2,4})\.(?:jpe?g|png|gif|webp)$/i', $path, $m ) ) {
			return array( (int) $m[1], (int) $m[2] );
		}
		return null;
	}

	/** Ранний проход: srcset у превью без метки data-kperf (их разметку собрали плагины снаружи основного буфера). */
	private function srcset_only( $html ) {
		$self = $this;
		$res  = $this->outside(
			$html,
			array( 'comment', 'script', 'noscript', 'style', 'template', 'textarea' ),
			function ( $part ) use ( $self ) {
				return preg_replace_callback(
					'/<picture\b' . Kozlov_Perf::ATTRS . '>.*?<\/picture\s*>|<img\b' . Kozlov_Perf::ATTRS . '>/is',
					function ( $m ) use ( $self ) {
						$x = $m[0];
						if ( false !== strpos( $x, 'data-kperf' ) || ! preg_match( '/-\d{2,4}x\d{2,4}\.(?:jpe?g|png|gif|webp)/i', $x ) ) {
							return $x; // уже обработано основным проходом или не превью
						}
						if ( 0 === stripos( $x, '<picture' ) ) {
							return $self->picture_sync( $self->picture_srcset( $x ) );
						}
						return $self->sizes_auto( $self->thumb_srcset( $x ) );
					},
					$part
				);
			}
		);
		return null === $res ? $html : $res;
	}

	/** Все <picture> страницы (вне script, noscript, style, template, textarea, комментариев). */
	private function each_picture( $html, $method ) {
		$self = $this;
		return $this->outside(
			$html,
			array( 'comment', 'script', 'noscript', 'style', 'template', 'textarea' ),
			function ( $part ) use ( $self, $method ) {
				return preg_replace_callback(
					'/<picture\b' . Kozlov_Perf::ATTRS . '>.*?<\/picture\s*>/is',
					function ( $m ) use ( $self, $method ) {
						return $self->$method( $m[0] );
					},
					$part
				);
			}
		);
	}

	private function pictures( $html ) {
		return $this->each_picture( $html, 'picture_srcset' );
	}

	private function sync_sources( $html ) {
		return $this->each_picture( $html, 'picture_sync' );
	}

	/**
	 * <picture>, который внутренний буфер WebP Express собрал раньше нас (template_redirect, 10000): в <source>
	 * полный srcset WordPress в WebP, браузер берёт его. Ограничиваем список и в <source>, и в <img>.
	 * Варианты берутся из самого <source> (WebP Express пишет туда «адрес.webp» и уже проверил, что такие файлы
	 * есть), поэтому диск не трогаем. Другой формат WebP: <picture> не трогаем.
	 */
	public function picture_srcset( $pic ) {
		try {
			if ( ! preg_match( '/<img\b' . self::ATTRS . '>/i', $pic, $im ) ) {
				return $pic;
			}
			$img  = $im[0];
			$mode = (string) $this->opt( 'srcset_mode' );
			if ( 'keep' === $mode || false !== strpos( $img, 'data-kperf' ) ) {
				return $pic;
			}
			$src = $this->attr( $img, 'src' );
			if ( null === $src || ! $this->thumb_dims( $src ) || null === $this->upload_rel( $src ) || ! preg_match_all( '/<source\b' . self::ATTRS . '>/i', $pic, $sm ) ) {
				return $pic;
			}
			$sources = array();
			$hay     = '';
			foreach ( $sm[0] as $s ) {
				$set = $this->attr( $s, 'srcset' );
				if ( null !== $set && 'image/webp' === strtolower( trim( (string) $this->attr( $s, 'type' ) ) ) ) {
					$sources[] = $s;
					$hay      .= ' ' . $set . ' ,';
				}
			}
			$has = function ( $url ) use ( $hay ) {
				return false !== strpos( $hay, ' ' . $url . '.webp ' ) || false !== strpos( $hay, ', ' . $url . '.webp ' );
			};
			if ( ! $sources || ! $has( $src ) ) {
				return $pic; // нет WebP превью в формате «адрес.webp»: обычная потеговая обработка
			}
			$self   = $this;
			$srcset = $this->attr( $img, 'srcset' );
			$filled = false;
			if ( null !== $srcset && '' !== trim( $srcset ) ) {
				$list = ( 'cap' === $mode ) ? $this->pick( $this->parse_srcset( $srcset ), $src, $has ) : null;
			} elseif ( 'cap' === $mode && $this->opt( 'srcset_fill' ) ) {
				// Превью без srcset в <picture> с одним WebP: варианты по вложению, их WebP проверяем на диске.
				$list   = $this->fill_list(
					$src,
					function ( $url ) use ( $self, $has ) {
						return $has( $url ) || $self->has_webp( $url );
					}
				);
				$filled = true;
				if ( null === $list ) {
					return $pic;
				}
			} else {
				return $pic;
			}

			if ( null === $list ) {
				// Как в 1.1.0: только превью и в <img>, и в <source>.
				$out = $this->replace_once( $pic, $img, $this->drop_attr( $this->drop_attr( $img, 'srcset' ), 'sizes' ) );
				foreach ( $sources as $s ) {
					$out = $this->replace_once( $out, $s, $this->drop_attr( $this->set_attr( $s, 'srcset', $src . '.webp' ), 'sizes' ) );
				}
				$this->stats['srcset_dropped']++;
				return $out;
			}

			$new_img = $this->apply_srcset( $img, $list, $src );
			$parts   = array();
			foreach ( $list as $e ) {
				$parts[] = $e['url'] . '.webp ' . $e['w'] . 'w';
			}
			$set_webp = implode( ', ', $parts );
			$sizes    = (string) $this->attr( $new_img, 'sizes' );
			$out      = $this->replace_once( $pic, $img, $new_img );
			foreach ( $sources as $s ) {
				$out = $this->replace_once( $out, $s, $this->set_attr( $this->set_attr( $s, 'srcset', $set_webp ), 'sizes', $sizes ) );
			}
			$this->stats[ $filled ? 'srcset_filled' : 'srcset_capped' ]++;
			return $out;
		} catch ( \Throwable $e ) {
			return $pic;
		}
	}

	/** После ленивой загрузки у <img> мог появиться sizes="auto, …": WebP-источник получает тот же sizes. */
	public function picture_sync( $pic ) {
		if ( false === strpos( $pic, 'data-kperf' ) || ! preg_match( '/<img\b' . self::ATTRS . '>/i', $pic, $im ) || 'srcset' !== $this->attr( $im[0], 'data-kperf' ) ) {
			return $pic;
		}
		$sizes = $this->attr( $im[0], 'sizes' );
		if ( null === $sizes || ! preg_match_all( '/<source\b' . self::ATTRS . '>/i', $pic, $sm ) ) {
			return $pic;
		}
		foreach ( $sm[0] as $s ) {
			if ( 'image/webp' === strtolower( trim( (string) $this->attr( $s, 'type' ) ) ) && $this->attr( $s, 'sizes' ) !== $sizes ) {
				$pic = $this->replace_once( $pic, $s, $this->set_attr( $s, 'sizes', $sizes ) );
			}
		}
		return $pic;
	}

	private function replace_once( $hay, $from, $to ) {
		$pos = strpos( $hay, $from );
		return false === $pos ? $hay : substr_replace( $hay, $to, $pos, strlen( $from ) );
	}

	/**
	 * Превью (src вида …-450x338.jpg) получает srcset нужного размера.
	 *
	 * Режим cap (по умолчанию): из srcset WordPress остаются только варианты не шире srcset_max, у которых рядом
	 * лежит WebP (его отдаёт WebP Express через <picture>), и без почти одинаковых по ширине. Тогда экран retina
	 * берёт вариант 768–1024 px весом в десятки килобайт, а не тяжёлый JPEG/PNG оригинал, как было до 1.1.0.
	 * Режим drop: srcset убирается (поведение 1.1.0). Режим keep: srcset не трогаем.
	 * Превью без srcset (собранные плагинами вручную) получают его по адресу картинки, если включено srcset_fill.
	 */
	public function thumb_srcset( $tag ) {
		try {
			if ( false !== strpos( $tag, 'data-kperf' ) || ! preg_match( '/-\d{2,4}x\d{2,4}\./', $tag ) ) {
				return $tag;
			}
			$src = $this->attr( $tag, 'src' );
			if ( null === $src || '' === $src || ! $this->thumb_dims( $src ) || null !== $this->attr( $tag, 'data-kperf' ) ) {
				return $tag;
			}
			$mode   = (string) $this->opt( 'srcset_mode' );
			$srcset = $this->attr( $tag, 'srcset' );
			if ( null === $this->upload_rel( $src ) ) {
				// Превью не из uploads (чужой домен, CDN): как в 1.1.0, без srcset.
				if ( null !== $srcset && 'keep' !== $mode ) {
					$this->stats['srcset_dropped']++;
					return $this->drop_attr( $this->drop_attr( $tag, 'srcset' ), 'sizes' );
				}
				return $tag;
			}

			if ( null !== $srcset && '' !== trim( $srcset ) ) {
				if ( 'keep' === $mode ) {
					return $tag;
				}
				$list = ( 'cap' === $mode ) ? $this->pick( $this->parse_srcset( $srcset ), $src ) : null;
				if ( null === $list ) {
					$tag = $this->drop_attr( $this->drop_attr( $tag, 'srcset' ), 'sizes' );
					$this->stats['srcset_dropped']++;
					return $tag;
				}
				$this->stats['srcset_capped']++;
				return $this->apply_srcset( $tag, $list, $src );
			}

			if ( 'cap' !== $mode || ! $this->opt( 'srcset_fill' ) ) {
				return $tag;
			}
			$list = $this->fill_list( $src );
			if ( null === $list ) {
				return $tag;
			}
			$this->stats['srcset_filled']++;
			return $this->apply_srcset( $tag, $list, $src );
		} catch ( \Throwable $e ) {
			return $tag;
		}
	}

	private function apply_srcset( $tag, array $list, $src ) {
		$parts = array();
		foreach ( $list as $e ) {
			$parts[] = $e['url'] . ' ' . $e['w'] . 'w';
		}
		// Разделитель строго ", ": так его разбирает WebP Express при сборке <picture>.
		$tag = $this->set_attr( $tag, 'srcset', implode( ', ', $parts ) );

		$class = (string) $this->attr( $tag, 'class' );
		$card  = trim( (string) $this->opt( 'card_sizes' ) );
		if ( '' !== $card && preg_match( '/(?:^|\s)size-category-thumb2(?:\s|$)/', $class ) ) {
			$sizes = $card;
		} else {
			$sizes = $this->attr( $tag, 'sizes' );
			$sizes = ( null !== $sizes ) ? preg_replace( '/^\s*auto\s*,\s*/i', '', $sizes ) : '';
			if ( '' === trim( $sizes ) ) {
				$w     = (int) $this->attr( $tag, 'width' );
				$dims  = $this->thumb_dims( $src );
				$w     = $w > 0 ? $w : (int) $dims[0];
				$sizes = '(max-width: ' . $w . 'px) 100vw, ' . $w . 'px';
			}
		}
		$tag = $this->set_attr( $tag, 'sizes', $sizes );
		return $this->set_attr( $tag, 'data-kperf', 'srcset' );
	}

	/**
	 * Ленивой картинке с нашим srcset: sizes="auto, …". Браузеры с поддержкой (Chrome 126+) берут вариант по
	 * фактической ширине картинки, остальные пропускают auto и читают список дальше.
	 */
	public function sizes_auto( $tag ) {
		if ( false === strpos( $tag, 'data-kperf' ) || 'srcset' !== $this->attr( $tag, 'data-kperf' ) || ! preg_match( '/\sloading\s*=\s*(["\'])lazy\1/i', $tag ) ) {
			return $tag;
		}
		$sizes = (string) $this->attr( $tag, 'sizes' );
		if ( '' === $sizes || preg_match( '/^\s*auto\b/i', $sizes ) ) {
			return $tag;
		}
		return $this->set_attr( $tag, 'sizes', 'auto, ' . $sizes );
	}

	/** srcset → [ [url, w], … ] или null, если есть дескрипторы не по ширине (x) или запись не разобрать. */
	private function parse_srcset( $srcset ) {
		$out = array();
		foreach ( preg_split( '/,\s+/', trim( $srcset ) ) as $item ) {
			$item = trim( $item );
			if ( '' === $item ) {
				continue;
			}
			if ( ! preg_match( '/^(\S+)\s+(\d+)w$/i', $item, $m ) ) {
				return null;
			}
			$out[] = array(
				'url' => $m[1],
				'w'   => (int) $m[2],
			);
		}
		return $out ? $out : null;
	}

	/**
	 * Отбор вариантов: src всегда остаётся; остальные только из uploads, не шире srcset_max и с WebP рядом
	 * (если включено srcset_webp). Из почти одинаковых по ширине (разница меньше 10 %) остаётся больший:
	 * 800 px оригинал вместо 768 px нарезки (он и чётче: один круг сжатия вместо двух).
	 *
	 * @return array|null Список по возрастанию ширины или null, если вариантов меньше двух.
	 */
	private function pick( $list, $src, $avail = null ) {
		if ( ! is_array( $list ) ) {
			return null;
		}
		$self = $this;
		if ( null === $avail ) {
			$avail = $this->opt( 'srcset_webp' )
				? function ( $url ) use ( $self ) {
					return $self->has_webp( $url );
				}
				: function () {
					return true;
				};
		}
		$max     = max( 450, (int) $this->opt( 'srcset_max' ) );
		$dims    = $this->thumb_dims( $src );
		$src_rel = $this->upload_rel( $src );
		// WebP Express собирает <picture> только если WebP есть для всех вариантов. Нет WebP у самого превью:
		// <picture> не будет, и браузер взял бы большой JPEG/PNG. Тогда, как в 1.1.0, только превью.
		if ( null === $src_rel || ! $avail( $src ) ) {
			return null;
		}
		$src_w = (int) $dims[0];
		$cand  = array();
		foreach ( $list as $e ) {
			$rel = $this->upload_rel( $e['url'] );
			if ( null === $rel ) {
				continue;
			}
			if ( $rel === $src_rel ) {
				$src_w = (int) $e['w'];
				continue;
			}
			if ( $e['w'] > $max || isset( $cand[ $rel ] ) ) {
				continue;
			}
			$cand[ $rel ] = array(
				'url' => $e['url'],
				'w'   => (int) $e['w'],
				'src' => false,
			);
		}
		$cand[ $src_rel ] = array(
			'url' => $src,
			'w'   => $src_w,
			'src' => true,
		);
		$cand = array_values( $cand );
		usort(
			$cand,
			function ( $a, $b ) {
				return ( $b['w'] - $a['w'] ) ?: ( (int) $b['src'] - (int) $a['src'] );
			}
		);
		// От широкого к узкому: почти одинаковый по ширине (меньше 10 %) пропускаем, WebP проверяем только у
		// тех, кто иначе попал бы в список (каждая проверка это обращение к диску).
		$out  = array();
		$last = 0;
		foreach ( $cand as $e ) {
			if ( ! $e['src'] && ( ( $last && $e['w'] >= 0.9 * $last ) || ! $avail( $e['url'] ) ) ) {
				continue;
			}
			$out[] = $e;
			$last  = $e['w'];
		}
		if ( count( $out ) < 2 ) {
			return null;
		}
		return array_reverse( $out );
	}

	/** Варианты для превью без srcset: через вложение WordPress, которому принадлежит файл. */
	private function fill_list( $src, $avail = null ) {
		$srcset = $this->fill_srcset( $src );
		if ( '' === $srcset ) {
			return null;
		}
		return $this->pick( $this->parse_srcset( $srcset ), $src, $avail );
	}

	/** srcset WordPress для превью без srcset: из своего кеша или по вложению (с записью в кеш). '' = нет. */
	private function fill_srcset( $src ) {
		$key = md5( $src );
		$hit = $this->fill_cache_get( $key );
		// найденное хранится неделю, «не найдено» сутки (вдруг вложение загрузят)
		if ( is_array( $hit ) && isset( $hit['t'], $hit['s'] ) && $hit['t'] > time() - ( '' !== $hit['s'] ? 7 : 1 ) * DAY_IN_SECONDS ) {
			return (string) $hit['s'];
		}
		if ( $this->lookups >= self::FILL_LIMIT ) {
			return '';
		}
		$srcset = '';
		$id     = $this->attachment_for( $src );
		if ( $id ) {
			$meta = wp_get_attachment_metadata( $id );
			$dims = $this->thumb_dims( $src );
			if ( is_array( $meta ) && $dims && function_exists( 'wp_calculate_image_srcset' ) ) {
				$calc   = wp_calculate_image_srcset( $dims, $src, $meta, $id );
				$srcset = is_string( $calc ) ? $calc : '';
			}
		}
		$this->fill_cache_set(
			$key,
			array(
				's' => $srcset,
				't' => time(),
			)
		);
		return $srcset;
	}

	/** @var array|null Кеш вариантов (опция FILL_OPTION), читается при первой надобности. */
	private $fill_cache = null;

	/** @var bool В кеше вариантов есть несохранённые записи. */
	private $fill_dirty = false;

	private function fill_cache_get( $key ) {
		if ( null === $this->fill_cache ) {
			$c                = get_option( self::FILL_OPTION, array() );
			$this->fill_cache = is_array( $c ) ? $c : array();
		}
		return isset( $this->fill_cache[ $key ] ) ? $this->fill_cache[ $key ] : null;
	}

	private function fill_cache_set( $key, $val ) {
		if ( null === $this->fill_cache ) {
			$this->fill_cache_get( $key );
		}
		if ( count( $this->fill_cache ) >= 3000 ) {
			$this->fill_cache = array(); // не даём опции разрастись: начинаем заново
		}
		$this->fill_cache[ $key ] = $val;
		$this->fill_dirty         = true;
	}

	/** Сохраняет кеш вариантов, если в нём есть новые записи (один запрос, только при промахе кеша страниц). */
	private function fill_cache_save() {
		if ( $this->fill_dirty && is_array( $this->fill_cache ) ) {
			$this->fill_dirty = false;
			update_option( self::FILL_OPTION, $this->fill_cache, false );
		}
	}

	/** ID вложения по адресу превью (…-300x225.jpg → оригинал). Результат кешируется, запросов не больше FILL_LIMIT за страницу. */
	private function attachment_for( $src ) {
		$key = 'att_' . md5( $src );
		if ( isset( $this->memo[ $key ] ) ) {
			return $this->memo[ $key ];
		}
		$this->lookups++;
		$abs = $this->absolute_upload_url( $src );
		$id  = 0;
		if ( null !== $abs ) {
			$orig = preg_replace( '/-\d{2,4}x\d{2,4}(\.[a-z0-9]+)$/i', '$1', $abs );
			$id   = (int) attachment_url_to_postid( $orig );
			if ( ! $id ) {
				$id = (int) attachment_url_to_postid( preg_replace( '/(\.[a-z0-9]+)$/i', '-scaled$1', $orig ) );
			}
		}
		return $this->memo[ $key ] = $id;
	}

	/** @var array Кеш на время запроса: пути, WebP, вложения. */
	private $memo = array();

	/** @var int Сколько вложений уже искали в базе на этой странице. */
	private $lookups = 0;

	/** @var array|null uploads: базовый адрес без схемы, путь, папка. */
	private $up = null;

	private function uploads() {
		if ( null === $this->up ) {
			$u        = wp_get_upload_dir();
			$base     = isset( $u['baseurl'] ) ? (string) $u['baseurl'] : '';
			$this->up = array(
				'abs'  => rtrim( $base, '/' ) . '/',
				'host' => rtrim( preg_replace( '#^https?:#i', '', $base ), '/' ) . '/',
				'path' => rtrim( (string) wp_parse_url( $base, PHP_URL_PATH ), '/' ) . '/',
				'dir'  => isset( $u['basedir'] ) ? rtrim( (string) $u['basedir'], '/\\' ) : '',
			);
		}
		return $this->up;
	}

	/** Путь файла внутри uploads (декодированный) или null, если адрес не из uploads. */
	public function upload_rel( $url ) {
		$k = 'rel_' . $url;
		if ( array_key_exists( $k, $this->memo ) ) {
			return $this->memo[ $k ];
		}
		$up = $this->uploads();
		$u  = (string) $url;
		// Быстрый путь: обычный абсолютный адрес из uploads без параметров и сущностей.
		if ( 0 === strpos( $u, $up['abs'] ) && false === strpbrk( $u, '?#&%' ) ) {
			$rel = substr( $u, strlen( $up['abs'] ) );
			if ( '' !== $rel && false === strpos( $rel, '..' ) && false === strpos( $rel, "\0" ) ) {
				return $this->memo[ $k ] = $rel;
			}
		}
		$u   = preg_replace( '/[?#].*$/', '', html_entity_decode( $u, ENT_QUOTES, 'UTF-8' ) );
		$u   = preg_replace( '#^https?:#i', '', $u );
		$rel = null;
		if ( '/' !== $up['host'] && 0 === stripos( $u, $up['host'] ) ) {
			$rel = substr( $u, strlen( $up['host'] ) );
		} elseif ( '/' !== $up['path'] && 0 === strpos( $u, $up['path'] ) && 0 !== strpos( $u, '//' ) ) {
			$rel = substr( $u, strlen( $up['path'] ) );
		}
		if ( null !== $rel ) {
			$rel = rawurldecode( $rel );
			if ( '' === $rel || false !== strpos( $rel, '..' ) || false !== strpos( $rel, "\0" ) ) {
				$rel = null;
			}
		}
		return $this->memo[ $k ] = $rel;
	}

	private function absolute_upload_url( $url ) {
		$rel = $this->upload_rel( $url );
		if ( null === $rel ) {
			return null;
		}
		$up = $this->uploads();
		// Без процентного кодирования: в _wp_attached_file имена хранятся как есть (кириллица).
		return $up['abs'] . $rel;
	}

	/** Есть ли WebP рядом с файлом (форматы хранения WebP Express: рядом с оригиналом или в отдельной папке). */
	public function has_webp( $url ) {
		$k = 'webp_' . $url;
		if ( array_key_exists( $k, $this->memo ) ) {
			return $this->memo[ $k ];
		}
		$rel = $this->upload_rel( $url );
		$ok  = false;
		if ( null !== $rel ) {
			$up    = $this->uploads();
			$file  = $up['dir'] . '/' . $rel;
			$noext = preg_replace( '/\.[^.\/]+$/', '', $rel );
			$wx    = WP_CONTENT_DIR . '/webp-express/webp-images';
			$doc   = ( 0 === strpos( $file, ABSPATH ) ) ? substr( $file, strlen( ABSPATH ) ) : null;
			$try   = array( $file . '.webp', $up['dir'] . '/' . $noext . '.webp', $wx . '/uploads/' . $rel . '.webp', $wx . '/uploads/' . $noext . '.webp' );
			if ( null !== $doc ) {
				$try[] = $wx . '/doc-root/' . $doc . '.webp';
				$try[] = $wx . '/doc-root/' . preg_replace( '/\.[^.\/]+$/', '', $doc ) . '.webp';
			}
			foreach ( $try as $f ) {
				if ( @is_file( $f ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
					$ok = true;
					break;
				}
			}
		}
		return $this->memo[ $k ] = $ok;
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
		delete_option( self::FILL_OPTION ); // настройки поменялись: варианты соберутся заново
		$in  = is_array( $in ) ? $in : array();
		$out = array();
		foreach ( array( 'enabled', 'native_lazy', 'srcset_webp', 'srcset_fill', 'strip_unused', 'emoji', 'head_cleanup', 'dedupe', 'fix_ver', 'block_css', 'mobile_zoom' ) as $k ) {
			$out[ $k ] = empty( $in[ $k ] ) ? 0 : 1;
		}
		$out['eager_count'] = isset( $in['eager_count'] ) ? max( 0, min( 50, (int) $in['eager_count'] ) ) : 8;
		$mode               = isset( $in['srcset_mode'] ) ? (string) $in['srcset_mode'] : 'cap';
		$out['srcset_mode'] = in_array( $mode, array( 'cap', 'drop', 'keep' ), true ) ? $mode : 'cap';
		$out['srcset_max']  = isset( $in['srcset_max'] ) ? max( 450, min( 2048, (int) $in['srcset_max'] ) ) : 1024;
		$sizes              = isset( $in['card_sizes'] ) ? trim( preg_replace( '/\s+/', ' ', sanitize_text_field( $in['card_sizes'] ) ) ) : self::CARD_SIZES;
		// sizes: только символы медиа-выражений и calc(); иначе значение по умолчанию.
		$out['card_sizes']  = ( '' === $sizes || preg_match( '/^[a-z0-9\s(),.:%+\-*\/]{1,600}$/i', $sizes ) ) ? $sizes : self::CARD_SIZES;
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
				<h2 class="title">Превью для экранов retina</h2>
				<p style="margin:0 0 6px 0">Превью (src вида …-450x338.jpg) со srcset от WordPress:</p>
				<?php
				foreach ( array(
					'cap'  => 'Оставлять только лёгкие варианты (рекомендуется). Retina получает чёткую картинку 768–1024 px в WebP, обычный экран прежнее превью 450 px. Тяжёлые оригиналы в srcset не попадают.',
					'drop' => 'Убирать srcset (как в 1.1.0). Везде одно превью 450 px: на retina и на широких карточках оно растягивается и мылится.',
					'keep' => 'Не трогать (srcset WordPress целиком, вместе с оригиналами 200–850 КБ).',
				) as $val => $label ) {
					printf(
						'<label style="display:block;margin:4px 0 4px 12px"><input type="radio" name="%1$s[srcset_mode]" value="%2$s" %3$s> %4$s</label>',
						esc_attr( $n ),
						esc_attr( $val ),
						checked( $o['srcset_mode'], $val, false ),
						esc_html( $label )
					);
				}
				?>
				<p style="margin:8px 0 10px 24px">
					<label>Самый широкий вариант в srcset, px: <input type="number" min="450" max="2048" name="<?php echo esc_attr( $n ); ?>[srcset_max]" value="<?php echo esc_attr( (int) $o['srcset_max'] ); ?>" style="width:80px"></label>
					<span class="description">(1024 хватает карточкам до 512 px на retina; оригинал 800 px из srcset тоже проходит)</span>
				</p>
				<?php $cb( 'srcset_webp', 'Брать в srcset только варианты, у которых рядом есть WebP', 'WebP Express собирает &lt;picture&gt; только если WebP есть для каждого варианта. С галочкой так будет всегда, и retina получает WebP весом в десятки килобайт. Если WebP нет у самого превью, у него остаётся только src.' ); ?>
				<?php $cb( 'srcset_fill', 'Добавлять srcset превью, у которых его нет', 'Например, «Ещё в этот вечер» на странице концерта (картинки 300×225 без srcset). Вложение находится по адресу файла, результат кешируется на сутки, не больше ' . self::FILL_LIMIT . ' поисков на страницу.' ); ?>
				<p style="margin:0 0 10px 24px">
					<label>sizes для карточек афиши (класс size-category-thumb2):<br>
					<textarea name="<?php echo esc_attr( $n ); ?>[card_sizes]" rows="3" style="width:100%;max-width:600px;font-family:monospace;font-size:12px"><?php echo esc_textarea( $o['card_sizes'] ); ?></textarea></label>
					<span class="description">Ширина картинки карточки в теме при разной ширине окна (замер 12.11.2026). Пусто = sizes от WordPress. Ленивым картинкам впереди добавляется auto: Chrome сам берёт вариант по фактической ширине.</span>
				</p>

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
	delete_option( Kozlov_Perf::FILL_OPTION );
}
