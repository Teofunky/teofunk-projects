<?php
/**
 * Страница меню: шорткод [kozlov_menu]. На странице /menu/ (или с шаблоном «Меню ресторана») меню добавляется само.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KZM_Front {

	private static $used = false;

	public static function init() {
		add_shortcode( 'kozlov_menu', array( __CLASS__, 'shortcode' ) );
		add_filter( 'the_content', array( __CLASS__, 'auto' ), 20 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_footer', array( __CLASS__, 'schema' ) );
	}

	public static function is_menu_page() {
		if ( ! is_page() ) {
			return false;
		}
		$id = get_queried_object_id();
		return 'page-menu.php' === get_page_template_slug( $id ) || 'menu' === get_post_field( 'post_name', $id );
	}

	public static function auto( $content ) {
		if ( ! in_the_loop() || ! is_main_query() || ! self::is_menu_page() || has_shortcode( $content, 'kozlov_menu' ) ) {
			return $content;
		}
		return $content . self::render();
	}

	public static function shortcode() {
		return self::render();
	}

	public static function assets() {
		if ( ! self::is_menu_page() && ! ( is_singular() && has_shortcode( (string) get_post_field( 'post_content', get_queried_object_id() ), 'kozlov_menu' ) ) ) {
			return;
		}
		wp_enqueue_style( 'kozlov-menu', KZM_URL . 'assets/menu.css', array(), KZM_VER );
		wp_enqueue_script( 'kozlov-menu', KZM_URL . 'assets/menu.js', array(), KZM_VER, array( 'in_footer' => true, 'strategy' => 'defer' ) );
	}

	public static function render() {
		$sections = KZM_Data::sections();
		if ( ! $sections ) {
			return '';
		}
		self::$used = true;
		$groups = array();
		foreach ( $sections as $s ) {
			$groups[ $s['group'] ][] = $s;
		}
		$h  = '<div class="kzm" id="kzm">';
		$n  = KZM_Data::notice();
		if ( '' !== $n ) {
			$h .= '<p class="kzm-notice"><svg viewBox="0 0 32 32" width="22" height="22" aria-hidden="true"><path fill="currentColor" d="M29 21.5C29 14.7 23.7 9.1 17 8.5V5h-2v3.5C8.3 9.1 3 14.7 3 21.5v2.1h26v-2.1zm-2 .1H5v-.1C5 15.4 9.9 10.5 16 10.5s11 4.9 11 11v.1zM1 26h30v2H1z"/></svg><span>' . esc_html( $n ) . '</span></p>';
		}
		// Поиск и навигация.
		$h .= '<div class="kzm-bar" role="navigation" aria-label="Разделы меню">';
		$h .= '<label class="kzm-search"><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><circle cx="11" cy="11" r="7" fill="none" stroke="currentColor" stroke-width="2"/><path d="M20 20l-4-4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg><input type="search" placeholder="Найти блюдо или напиток" aria-label="Поиск по меню" data-kzm-q></label>';
		$h .= '<div class="kzm-groups">';
		foreach ( KZM_Data::GROUPS as $g => $label ) {
			if ( ! empty( $groups[ $g ] ) ) {
				$h .= '<a class="kzm-group" href="#kzm-' . esc_attr( $g ) . '" data-kzm-group="' . esc_attr( $g ) . '">' . esc_html( $label ) . '</a>';
			}
		}
		$h .= '</div><div class="kzm-chips" data-kzm-chips>';
		foreach ( $sections as $s ) {
			$h .= '<a class="kzm-chip" href="#' . esc_attr( $s['slug'] ) . '" data-kzm-chip="' . esc_attr( $s['slug'] ) . '" data-group="' . esc_attr( $s['group'] ) . '">' . esc_html( $s['title'] ) . '</a>';
		}
		$h .= '</div></div>';
		$h .= '<p class="kzm-empty" hidden data-kzm-empty>Ничего не нашлось. Попробуйте другое слово.</p>';
		foreach ( KZM_Data::GROUPS as $g => $label ) {
			if ( empty( $groups[ $g ] ) ) {
				continue;
			}
			$h .= '<div class="kzm-g" id="kzm-' . esc_attr( $g ) . '"><h2 class="kzm-gt">' . esc_html( $label ) . '</h2>';
			foreach ( $groups[ $g ] as $s ) {
				$with_photo = count( array_filter( $s['items'], fn( $i ) => $i['image'] ) ) >= max( 1, (int) ceil( count( $s['items'] ) / 2 ) );
				$h .= '<section class="kzm-s' . ( $with_photo ? ' kzm-s--cards' : ' kzm-s--list' ) . '" id="' . esc_attr( $s['slug'] ) . '" data-kzm-section>';
				$h .= '<h3 class="kzm-st">' . esc_html( $s['title'] ) . ( '' !== $s['note'] ? '<span class="kzm-note">' . esc_html( $s['note'] ) . '</span>' : '' ) . '</h3><ul class="kzm-items">';
				foreach ( $s['items'] as $it ) {
					$h .= self::item( $it, $with_photo );
				}
				$h .= '</ul></section>';
			}
			$h .= '</div>';
		}
		$h .= '<dialog class="kzm-dlg" data-kzm-dlg><button type="button" class="kzm-x" data-kzm-close aria-label="Закрыть">×</button><img alt=""><p></p></dialog>';
		return $h . '</div>';
	}

	private static function item( $it, $cards ) {
		$search = mb_strtolower( $it['title'] . ' ' . $it['desc'], 'UTF-8' );
		$h      = '<li class="kzm-i" data-kzm-text="' . esc_attr( $search ) . '">';
		if ( $cards ) {
			if ( $it['image'] ) {
				$full = wp_get_attachment_image_url( $it['image'], 'large' );
				$h   .= '<button type="button" class="kzm-ph" data-kzm-zoom="' . esc_url( $full ) . '" aria-label="' . esc_attr( 'Фото: ' . $it['title'] ) . '">'
					. wp_get_attachment_image( $it['image'], 'medium_large', false, array( 'loading' => 'lazy', 'decoding' => 'async', 'alt' => $it['title'], 'sizes' => '(min-width: 1000px) 300px, (min-width: 600px) 45vw, 92vw' ) ) . '</button>';
			} else {
				$h .= '<span class="kzm-ph kzm-ph--none" aria-hidden="true"></span>';
			}
		}
		$h .= '<div class="kzm-b"><span class="kzm-n">' . esc_html( $it['title'] ) . '</span>';
		if ( '' !== $it['desc'] ) {
			$h .= '<span class="kzm-d">' . nl2br( esc_html( $it['desc'] ) ) . '</span>';
		}
		if ( '' !== $it['kcal'] ) {
			$h .= '<span class="kzm-k">' . esc_html( $it['kcal'] ) . '</span>';
		}
		$h .= '</div><div class="kzm-p">';
		foreach ( $it['variants'] as $v ) {
			$h .= '<span class="kzm-v">' . ( '' !== $v['size'] ? '<span class="kzm-sz">' . esc_html( $v['size'] ) . '</span>' : '' ) . ( '' !== $v['price'] ? '<b>' . esc_html( KZM_Data::price( $v['price'] ) ) . '</b>' : '' ) . '</span>';
		}
		return $h . '</div></li>';
	}

	/** Меню для поисковиков: schema.org Menu. */
	public static function schema() {
		if ( ! self::$used ) {
			return;
		}
		$secs = array();
		foreach ( KZM_Data::sections() as $s ) {
			$items = array();
			foreach ( $s['items'] as $it ) {
				$i = array( '@type' => 'MenuItem', 'name' => $it['title'] );
				if ( '' !== $it['desc'] ) {
					$i['description'] = $it['desc'];
				}
				$offers = array();
				foreach ( $it['variants'] as $v ) {
					if ( '' !== $v['price'] ) {
						$offers[] = array( '@type' => 'Offer', 'price' => $v['price'], 'priceCurrency' => 'RUB', 'name' => $v['size'] );
					}
				}
				if ( $offers ) {
					$i['offers'] = 1 === count( $offers ) ? $offers[0] : $offers;
				}
				$items[] = $i;
			}
			$secs[] = array( '@type' => 'MenuSection', 'name' => $s['title'], 'hasMenuItem' => $items );
		}
		$data = array(
			'@context'       => 'https://schema.org',
			'@type'          => 'Menu',
			'name'           => 'Меню ресторана Клуба Алексея Козлова',
			'inLanguage'     => 'ru',
			'hasMenuSection' => $secs,
		);
		echo '<script type="application/ld+json">' . wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>' . "\n";
	}
}
