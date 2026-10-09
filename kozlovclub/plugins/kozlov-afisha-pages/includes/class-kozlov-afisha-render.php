<?php
/**
 * Вывод афиши в разметке карточек темы KozlovCLub.
 *
 * Разметка карточки повторяет главную страницу (div.events.view_cell > div.event ...),
 * поэтому карточки берут стили темы. Свои стили: только заголовки групп и отступы,
 * все внутри .kozlov-afisha.
 *
 * @package Kozlov_Afisha_Pages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'Kozlov_Afisha_Render' ) ) {
	return;
}

/**
 * Render.
 */
class Kozlov_Afisha_Render {

	/** Сколько секунд держать готовый HTML в кэше. */
	const CACHE_TTL = 300;

	const CACHE_PREFIX = 'kozlov_afisha_';

	const STYLE_HANDLE = 'kozlov-afisha-pages';

	/**
	 * Выведены ли уже стили на этой странице.
	 *
	 * @var bool
	 */
	private static $style_done = false;

	/**
	 * Хуки фронта.
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_style' ) );
	}

	/**
	 * Стили в <head> только на страницах, где есть шорткод.
	 */
	public static function enqueue_style() {
		try {
			if ( ! is_singular() ) {
				return;
			}
			$post = get_queried_object();
			if ( ! ( $post instanceof WP_Post ) || ! has_shortcode( (string) $post->post_content, 'kozlov_afisha' ) ) {
				return;
			}
			wp_register_style( self::STYLE_HANDLE, false, array(), KOZLOV_AFISHA_PAGES_VERSION );
			wp_enqueue_style( self::STYLE_HANDLE );
			wp_add_inline_style( self::STYLE_HANDLE, self::css() );
			self::$style_done = true;
		} catch ( Throwable $e ) {
			return;
		}
	}

	/**
	 * Собственные стили. Карточки оформляет тема.
	 *
	 * @return string
	 */
	public static function css() {
		$k = '.kozlov-afisha.kozlov-afisha';
		return $k . '{margin:10px 0 40px;line-height:normal}'
			. $k . ' .kozlov-afisha__group{margin:0}'
			. $k . ' .event_month{font-size:16px;font-weight:500;letter-spacing:.05em;line-height:1.3;margin:30px 0 54px;padding:0}'
			. $k . ' .kozlov-afisha__group:first-child .event_month{margin-top:10px}'
			. $k . ' .events.view_cell{margin:0}'
			. $k . ' .kozlov-afisha__group--plain .events.view_cell{margin-top:44px}'
			. $k . ' .events .event a{text-decoration:none}'
			. $k . ' .events .event .desc a.title,' . $k . ' .events .event .top_info{color:#424242}'
			. $k . ' .events.view_cell .event .desc .title{height:50px;overflow:hidden}'
			. $k . ' .events .event .readmore a{color:#fff}'
			. $k . ' .kozlov-afisha__note,' . $k . ' .kozlov-afisha__empty,' . $k . ' .kozlov-afisha__more{padding:10px 0;margin:0}'
			. $k . ' .kozlov-afisha__more a{text-decoration:underline}';
	}

	/**
	 * Шорткод: HTML афиши (из кэша, если есть).
	 *
	 * @param array $atts Параметры шорткода.
	 * @return string
	 */
	public static function shortcode( array $atts ) {
		$args = Kozlov_Afisha_Data::normalize_args( $atts );

		$club  = new DateTimeZone( Kozlov_Afisha_Data::CLUB_TZ );
		$today = new DateTime( 'now', $club );
		$key   = self::CACHE_PREFIX . md5( wp_json_encode( $args ) . '|' . $today->format( 'Y-m-d' ) . '|' . KOZLOV_AFISHA_PAGES_VERSION . '|' . home_url( '/' ) );

		$html = get_transient( $key );
		if ( ! is_string( $html ) || '' === $html ) {
			$html = self::render( $args );
			set_transient( $key, $html, self::CACHE_TTL );
		}

		if ( ! self::$style_done ) {
			// Шорткод вне основной записи страницы: стили выводим один раз прямо перед афишей.
			self::$style_done = true;
			$html             = '<style id="kozlov-afisha-pages-inline-css">' . self::css() . '</style>' . $html;
		}

		return $html;
	}

	/**
	 * HTML афиши.
	 *
	 * @param array    $args Нормализованные параметры.
	 * @param int|null $now  Текущее время (unix), для тестов.
	 * @return string
	 */
	public static function render( array $args, $now = null ) {
		$now    = null === $now ? time() : (int) $now;
		$result = Kozlov_Afisha_Data::select( $args, $now );
		$club   = new DateTimeZone( Kozlov_Afisha_Data::CLUB_TZ );
		$today  = new DateTime( '@' . $now );
		$today->setTimezone( $club );
		$home = home_url( '/' );

		$out = '<div class="kozlov-afisha" data-when="' . esc_attr( $args['when'] ) . '">';

		if ( empty( $result['items'] ) ) {
			$empty = '' !== $args['empty'] ? $args['empty'] : self::default_empty( $args['when'] );
			$out  .= '<p class="kozlov-afisha__empty">' . esc_html( $empty ) . ' <a href="' . esc_url( $home ) . '">Смотрите всю афишу клуба</a>.</p>';
			return $out . '</div>';
		}

		if ( '' !== $result['fallback'] ) {
			if ( 'today' === $args['when'] ) {
				$note = 'Сегодня концертов больше нет. Ближайшие концерты:';
			} else {
				$note = 'Концерты этих выходных закончились. Следующие выходные:';
			}
			$out .= '<p class="kozlov-afisha__note">' . esc_html( $note ) . '</p>';
		}

		$groups = array();
		foreach ( $result['items'] as $item ) {
			if ( 'month' === $args['group'] ) {
				$gkey = $item['start']->format( 'Y-m' );
			} elseif ( 'day' === $args['group'] ) {
				$gkey = $item['date'];
			} else {
				$gkey = 'all';
			}
			if ( ! isset( $groups[ $gkey ] ) ) {
				$groups[ $gkey ] = array(
					'title' => self::group_title( $args['group'], $item['start'], $today ),
					'items' => array(),
				);
			}
			$groups[ $gkey ]['items'][] = $item;
		}

		foreach ( $groups as $group ) {
			$plain = '' === $group['title'];
			$out  .= '<div class="kozlov-afisha__group' . ( $plain ? ' kozlov-afisha__group--plain' : '' ) . '">';
			if ( ! $plain ) {
				$out .= '<h2 class="event_month"><span>' . esc_html( $group['title'] ) . '</span></h2>';
			}
			$out .= '<div class="events view_cell">';
			foreach ( $group['items'] as $item ) {
				$out .= self::card( $item, $today );
			}
			$out .= '</div></div>';
		}

		if ( $args['more'] ) {
			$more = '';
			if ( $result['total'] > count( $result['items'] ) ) {
				$more = 'Показаны ближайшие ' . count( $result['items'] ) . ' ' . self::plural( count( $result['items'] ), 'концерт', 'концерта', 'концертов' ) . '. ';
			}
			$out .= '<p class="kozlov-afisha__more">' . esc_html( $more ) . '<a href="' . esc_url( $home ) . '">Вся афиша клуба</a></p>';
		}

		return $out . '</div>';
	}

	/**
	 * Текст при пустом списке.
	 *
	 * @param string $when Режим.
	 * @return string
	 */
	private static function default_empty( $when ) {
		if ( 'today' === $when ) {
			return 'Ближайших концертов пока нет.';
		}
		if ( 'weekend' === $when ) {
			return 'На эти выходные концертов пока нет.';
		}
		return 'Ближайших концертов в этом разделе пока нет.';
	}

	/**
	 * Заголовок группы.
	 *
	 * @param string   $mode  month|day|none.
	 * @param DateTime $start Начало концерта.
	 * @param DateTime $today Сейчас по Москве.
	 * @return string
	 */
	private static function group_title( $mode, DateTime $start, DateTime $today ) {
		if ( 'month' === $mode ) {
			$title = 'Концерты в ' . self::month( $start, 'prep' );
			if ( $start->format( 'Y' ) !== $today->format( 'Y' ) ) {
				$title .= ' ' . $start->format( 'Y' );
			}
			return $title;
		}
		if ( 'day' === $mode ) {
			$date     = $start->format( 'Y-m-d' );
			$tomorrow = clone $today;
			$tomorrow->modify( '+1 day' );
			$tail = (int) $start->format( 'j' ) . ' ' . self::month( $start, 'gen' );
			if ( $date === $today->format( 'Y-m-d' ) ) {
				return 'Сегодня, ' . $tail;
			}
			if ( $date === $tomorrow->format( 'Y-m-d' ) ) {
				return 'Завтра, ' . $tail;
			}
			return self::weekday( $start ) . ', ' . $tail;
		}
		return '';
	}

	/**
	 * Карточка концерта (разметка как на главной).
	 *
	 * @param array    $item  Концерт.
	 * @param DateTime $today Сейчас по Москве.
	 * @return string
	 */
	private static function card( array $item, DateTime $today ) {
		$post     = $item['post'];
		$url      = get_permalink( $post );
		$title    = Kozlov_Afisha_Data::plain_text( get_the_title( $post ) );
		$is_today = $item['date'] === $today->format( 'Y-m-d' );
		$stages   = Kozlov_Afisha_Data::stages();

		$classes = array( 'event', $is_today ? 'today_1' : 'today_' );
		foreach ( array( 'mansarda', 'the_beat', 'main_scene', 'myasnitskaya' ) as $key ) {
			$classes[] = $key . ( in_array( $key, $item['stages'], true ) ? '_1' : '_0' );
		}

		$out = '<div class="' . esc_attr( implode( ' ', $classes ) ) . '">';

		// Сцена (и «Концерт сегодня»). Сцена текстом, без ссылки на ?sort=: это дубль главной.
		$stage_label = ! empty( $item['stages'] ) ? $stages[ $item['stages'][0] ]['label'] : '';
		if ( $is_today ) {
			$out .= '<div class="top_info">Концерт сегодня' . ( '' !== $stage_label ? ' | <span>' . esc_html( $stage_label ) . '</span>' : '' ) . '</div>';
		} elseif ( '' !== $stage_label ) {
			$out .= '<div class="top_info" style="background-image:none; padding-left: 0"><span>' . esc_html( $stage_label ) . '</span></div>';
		}

		$out .= '<div class="wrap">';

		$image = self::image( $post, $title );
		if ( '' !== $image ) {
			$out .= '<div class="thumbnail"><a href="' . esc_url( $url ) . '">' . $image . '</a></div>';
		}

		$title_len = function_exists( 'mb_strlen' ) ? mb_strlen( $title, 'UTF-8' ) : strlen( $title );
		$out      .= '<div class="desc">';
		$out      .= '<a class="title set_height' . ( $title_len > 60 ? ' shortened' : '' ) . '" href="' . esc_url( $url ) . '">' . esc_html( $title ) . '</a>';

		$row1 = array( self::month( $item['start'], 'short' ) );
		if ( ! $item['allday'] ) {
			$row1[] = $item['start']->format( 'H:i' );
		}
		$row1[] = self::weekday( $item['start'] );

		$out .= '<div class="datetime"><div class="wrap">';
		$out .= '<div class="cell cell_1">' . esc_html( $item['start']->format( 'd' ) ) . '</div>';
		$out .= '<div class="cell cell_2"><div class="row row_1">' . esc_html( implode( ' | ', $row1 ) ) . '</div>';
		$out .= '<div class="row row_2">' . esc_html( $item['cost'] ) . '</div></div>';
		$out .= '</div></div>';
		$out .= '</div>';

		$out .= '<div class="readmore">';
		if ( '' !== $item['tickets'] ) {
			$out .= '<div class="btn-widget"><button class="tc-background-tomato" type="button" tc-event="' . esc_attr( $item['tickets'] ) . '">Купить билет</button></div>';
			$out .= '<a class="btn-widget-buy">купить билет</a>';
		} else {
			$out .= '<a class="btn-widget-buy" href="' . esc_url( $url ) . '">подробнее</a>';
		}
		$out .= '</div>';

		$out .= '</div></div>';
		return $out;
	}

	/**
	 * Картинка карточки (размер темы category-thumb2, 450×338).
	 *
	 * @param WP_Post $post  Анонс.
	 * @param string  $title Заголовок (для alt, если у картинки его нет).
	 * @return string
	 */
	private static function image( WP_Post $post, $title ) {
		$thumb_id = (int) get_post_thumbnail_id( $post );
		if ( $thumb_id <= 0 ) {
			return '';
		}
		$size = has_image_size( 'category-thumb2' ) ? 'category-thumb2' : 'medium_large';
		$attr = array();
		$alt  = trim( (string) get_post_meta( $thumb_id, '_wp_attachment_image_alt', true ) );
		if ( '' === $alt ) {
			$attr['alt'] = $title;
		}
		$html = wp_get_attachment_image( $thumb_id, $size, false, $attr );
		return is_string( $html ) ? $html : '';
	}

	/**
	 * Название месяца.
	 *
	 * @param DateTime $date Дата.
	 * @param string   $form short (Окт) | gen (октября) | prep (октябре).
	 * @return string
	 */
	private static function month( DateTime $date, $form ) {
		$n     = (int) $date->format( 'n' ) - 1;
		$forms = array(
			'short' => array( 'Янв', 'Фев', 'Мар', 'Апр', 'Май', 'Июн', 'Июл', 'Авг', 'Сен', 'Окт', 'Ноя', 'Дек' ),
			'gen'   => array( 'января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря' ),
			'prep'  => array( 'январе', 'феврале', 'марте', 'апреле', 'мае', 'июне', 'июле', 'августе', 'сентябре', 'октябре', 'ноябре', 'декабре' ),
		);
		return isset( $forms[ $form ][ $n ] ) ? $forms[ $form ][ $n ] : '';
	}

	/**
	 * День недели с заглавной буквы.
	 *
	 * @param DateTime $date Дата.
	 * @return string
	 */
	private static function weekday( DateTime $date ) {
		$days = array(
			1 => 'Понедельник',
			'Вторник',
			'Среда',
			'Четверг',
			'Пятница',
			'Суббота',
			'Воскресенье',
		);
		$n    = (int) $date->format( 'N' );
		return isset( $days[ $n ] ) ? $days[ $n ] : '';
	}

	/**
	 * Склонение по числу.
	 *
	 * @param int    $n    Число.
	 * @param string $one  1 концерт.
	 * @param string $few  2 концерта.
	 * @param string $many 5 концертов.
	 * @return string
	 */
	public static function plural( $n, $one, $few, $many ) {
		$n    = abs( (int) $n );
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
}
