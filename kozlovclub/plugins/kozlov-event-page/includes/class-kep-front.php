<?php
/**
 * Сайт: решает, показывать ли новую страницу, и перестраивает готовый HTML темы.
 * Любая ошибка: отдаётся страница темы без изменений.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KEP_Front {

	/** @var int */
	private static $post_id = 0;

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_start' ), 1 );
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 90 );
	}

	/**
	 * Показывать ли новую страницу этой записи сейчас.
	 *
	 * @param int $post_id ID записи.
	 * @return bool
	 */
	public static function should_render( $post_id ) {
		$editor = current_user_can( 'edit_post', $post_id );
		$force  = isset( $_GET['kep'] ) ? sanitize_key( wp_unslash( $_GET['kep'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $editor && 'old' === $force ) {
			return false;
		}
		if ( $editor && 'new' === $force ) {
			return true;
		}
		if ( ! KEP_Data::hero_id( $post_id ) ) {
			return false;
		}
		$mode = KEP_Settings::get( 'mode' );
		if ( 'all' === $mode ) {
			return true;
		}
		if ( 'editors' === $mode ) {
			return current_user_can( 'edit_posts' );
		}
		return false;
	}

	public static function maybe_start() {
		if ( is_admin() || is_feed() || is_preview() || ! is_singular( KEP_POST_TYPE ) ) {
			return;
		}
		$post_id = (int) get_queried_object_id();
		if ( ! $post_id ) {
			return;
		}
		// Вошедшим страница собирается под них (режим «Только редакторы», ?kep=old/new):
		// такую страницу нельзя класть в общий кэш, иначе её увидят гости.
		if ( is_user_logged_in() ) {
			self::no_cache();
		}
		if ( ! self::should_render( $post_id ) ) {
			// Старая страница: тексты из полей «Коротко о концерте» и «Важно знать» тоже должны быть видны.
			if ( '' !== KEP_Data::lead( $post_id ) || KEP_Data::notes( $post_id ) ) {
				self::$post_id = $post_id;
				ob_start( array( __CLASS__, 'filter_old' ) );
			}
			return;
		}
		self::$post_id = $post_id;
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 20 );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		self::social_image( KEP_Data::hero_id( $post_id ) );
		ob_start( array( __CLASS__, 'filter_html' ) );
	}

	/**
	 * Запрет кэширования этой страницы (W3 Total Cache и другие кэши уважают DONOTCACHEPAGE).
	 */
	private static function no_cache() {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		if ( ! headers_sent() ) {
			nocache_headers();
		}
	}

	/**
	 * Заглавное фото становится картинкой для соцсетей в Yoast (первой в списке).
	 *
	 * @param int $hero ID фото.
	 */
	private static function social_image( $hero ) {
		if ( ! $hero ) {
			return;
		}
		add_filter(
			'wpseo_add_opengraph_images',
			function ( $container ) use ( $hero ) {
				if ( is_object( $container ) && method_exists( $container, 'add_image_by_id' ) ) {
					$container->add_image_by_id( $hero );
				}
				return $container;
			}
		);
		add_filter(
			'wpseo_twitter_image',
			function ( $url ) use ( $hero ) {
				$img = wp_get_attachment_image_url( $hero, 'large' );
				return $img ? $img : $url;
			}
		);
	}

	public static function assets() {
		wp_enqueue_style( 'kep', KEP_URL . 'assets/kep.css', array(), KEP_VERSION );
		wp_enqueue_script( 'kep', KEP_URL . 'assets/kep.js', array(), KEP_VERSION, true );
	}

	public static function body_class( $classes ) {
		$classes[] = 'kep-page';
		return $classes;
	}

	/**
	 * Перестроить страницу. Если что-то не так, вернуть как было.
	 *
	 * @param string $html Готовая страница.
	 * @return string
	 */
	public static function filter_html( $html ) {
		try {
			$frag = KEP_Parser::find_fragment( $html );
			if ( ! $frag ) {
				return self::fail( $html, 'Не найден блок концерта в разметке темы.' );
			}
			$d = KEP_Parser::parse( $frag['html'] );
			if ( ! $d ) {
				return self::fail( $html, 'Разметка концерта не распознана.' );
			}
			if ( $d['bad'] ) {
				return self::fail( $html, 'В анонсе есть встроенный блок, который новая страница не показывает (' . implode( ', ', array_unique( $d['bad'] ) ) . ').' );
			}
			if ( $d['unknown'] ) {
				return self::fail( $html, 'В анонсе есть ' . implode( ', ', array_unique( $d['unknown'] ) ) . ': новая страница его не показывает.' );
			}
			if ( $d['kept_img'] < $d['orig_img'] ) {
				return self::fail( $html, sprintf( 'Потерялась картинка из анонса: перенесено %d из %d.', $d['kept_img'], $d['orig_img'] ) );
			}
			if ( $d['kept_link'] < $d['orig_link'] ) {
				return self::fail( $html, sprintf( 'Потерялась ссылка из анонса: перенесено %d из %d.', $d['kept_link'], $d['orig_link'] ) );
			}
			if ( $d['orig_len'] > 0 && $d['kept_len'] < 0.9 * $d['orig_len'] ) {
				return self::fail( $html, sprintf( 'Часть текста анонса не распознана: перенесено %d из %d знаков.', $d['kept_len'], $d['orig_len'] ) );
			}
			if ( 'past' !== $d['type'] && false !== strpos( $frag['html'], 'tc-event=' ) && empty( $d['tc'] ) ) {
				return self::fail( $html, 'Кнопка Ticketscloud есть в анонсе, но не распознана.' );
			}
			$post_id = self::$post_id;
			if ( empty( $d['artists'] ) ) {
				$d['artists'] = KEP_Data::ka_artists( $post_id );
			}
			$d = self::apply_fields( $d, $post_id );
			$slug    = (string) get_post_field( 'post_name', $post_id );
			$entry   = KEP_Data::by_slug( $slug );
			$date    = $entry ? $entry['date'] : KEP_Parser::infer_date( $d['day'], $d['month'], $d['upcoming'], current_time( 'timestamp' ) );
			$code    = ( $entry && '' !== $entry['stage'] ) ? $entry['stage'] : KEP_Settings::stage_code( $d['stage'] );
			$ctx     = array(
				'post_id' => $post_id,
				'hero_id' => KEP_Data::hero_id( $post_id ),
				'program' => KEP_Data::program( $post_id ),
				'date'    => $date,
				'code'    => $code,
				'more'    => ( $d['upcoming'] && '' !== $date ) ? KEP_Data::same_day( $slug, $date ) : array(),
			);
			$new = KEP_Render::page( $d, $ctx );
			if ( '' === $new ) {
				return self::fail( $html, 'Пустой результат вёрстки.' );
			}
			return substr_replace( $html, $new, $frag['start'], $frag['length'] );
		} catch ( Exception $e ) {
			return self::fail( $html, $e->getMessage() );
		} catch ( Error $e ) {
			return self::fail( $html, $e->getMessage() );
		}
	}

	/**
	 * Поля редактора поверх разобранного анонса: текст сверху и сноски.
	 * Если тот же текст остался и в анонсе, он не повторяется.
	 *
	 * @param array $d       Данные анонса.
	 * @param int   $post_id ID записи.
	 * @return array
	 */
	public static function apply_fields( $d, $post_id ) {
		$lead  = KEP_Data::lead( $post_id );
		$notes = KEP_Data::notes( $post_id );
		if ( '' !== $lead ) {
			$ln = KEP_Parser::norm( $lead );
			foreach ( $d['paras'] as $i => $p ) {
				if ( 'lead' === $p['kind'] ) {
					$d['paras'][ $i ]['kind'] = 'text';
				}
				$pn = KEP_Parser::norm( $p['text'] );
				if ( in_array( $d['paras'][ $i ]['kind'], array( 'text', 'notice' ), true ) && strlen( $pn ) > 20 && false !== strpos( $ln, $pn ) ) {
					$d['paras'][ $i ]['kind'] = 'dup'; // этот абзац уже стоит сверху
				}
			}
			$d['lead']      = wp_strip_all_tags( $lead );
			$d['lead_html'] = $lead;
			$d['lead_rich'] = true;
		}
		$d['notices'] = $notes;
		foreach ( $d['paras'] as $i => $p ) {
			if ( 'notice' !== $p['kind'] ) {
				continue;
			}
			$html = KEP_Parser::notice_html( $p['html'] );
			$dup  = '' === KEP_Parser::norm( $html );
			foreach ( $d['notices'] as $have ) {
				if ( KEP_Parser::same_text( $have, $html ) ) {
					$dup = true; // та же сноска уже есть в поле «Важно знать»
				}
			}
			if ( ! $dup ) {
				$d['notices'][] = $html;
			}
		}
		return $d;
	}

	/**
	 * Старая страница: тексты из полей редактора вставляются в начало описания,
	 * если такого текста там ещё нет. Иначе после переноса сносок в поле они пропали бы со старой страницы.
	 *
	 * @param string $html Страница.
	 * @return string
	 */
	public static function filter_old( $html ) {
		try {
			return self::inject_old( $html, self::$post_id );
		} catch ( Exception $e ) {
			return $html;
		} catch ( Error $e ) {
			return $html;
		}
	}

	public static function inject_old( $html, $post_id ) {
		if ( ! is_string( $html ) || ! $post_id ) {
			return $html;
		}
		$lead  = KEP_Data::lead( $post_id );
		$notes = KEP_Data::notes( $post_id );
		if ( '' === $lead && ! $notes ) {
			return $html;
		}
		$frag = KEP_Parser::find_fragment( $html );
		if ( ! $frag || ! preg_match( '/<div\b[^>]*\bclass\s*=\s*["\'][^"\']*\bdescription\b[^"\']*["\'][^>]*>/i', $frag['html'], $m, PREG_OFFSET_CAPTURE ) ) {
			return $html;
		}
		$desc  = KEP_Parser::words( substr( $frag['html'], $m[0][1] ) );
		$known = function ( $s ) use ( $desc ) {
			$w = KEP_Parser::words( $s );
			return $w && count( array_intersect( $w, $desc ) ) / count( $w ) >= 0.8;
		};
		$extra = '';
		foreach ( $notes as $n ) {
			if ( ! $known( $n ) ) {
				$extra .= '<p class="kep-old-note"><strong style="color:#f24841">' . $n . '</strong></p>';
			}
		}
		if ( '' !== $lead && ! $known( $lead ) ) {
			$extra .= $lead;
		}
		if ( '' === $extra ) {
			return $html;
		}
		$at = $frag['start'] + $m[0][1] + strlen( $m[0][0] );
		return substr_replace( $html, '<div class="kep-old-extra textblock">' . $extra . '</div>', $at, 0 );
	}

	/**
	 * Вернуть страницу как есть и запомнить причину для админки.
	 */
	private static function fail( $html, $why ) {
		$last = get_option( 'kep_last_error' );
		$same = is_array( $last ) && isset( $last['post_id'], $last['time'] ) && (int) $last['post_id'] === self::$post_id && $last['time'] > time() - 600;
		if ( $same ) {
			return self::inject_old( str_replace( 'kep-page', 'kep-off', $html ), self::$post_id );
		}
		update_option(
			'kep_last_error',
			array(
				'time'    => time(),
				'post_id' => self::$post_id,
				'why'     => $why,
			),
			false
		);
		$html = str_replace( 'kep-page', 'kep-off', $html );
		return self::inject_old( $html, self::$post_id );
	}

	/**
	 * Пункт в верхней панели для редакторов: сравнить старую и новую страницу.
	 *
	 * @param WP_Admin_Bar $bar Панель.
	 */
	public static function admin_bar( $bar ) {
		if ( is_admin() || ! is_singular( KEP_POST_TYPE ) ) {
			return;
		}
		$post_id = (int) get_queried_object_id();
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$is_new = self::$post_id === $post_id;
		$url    = get_permalink( $post_id );
		$bar->add_node(
			array(
				'id'    => 'kep',
				'title' => $is_new ? 'Новая страница · показать старую' : ( KEP_Data::hero_id( $post_id ) ? 'Старая страница · показать новую' : 'Старая страница · примерить новую' ),
				'href'  => add_query_arg( 'kep', $is_new ? 'old' : 'new', $url ),
			)
		);
	}
}
