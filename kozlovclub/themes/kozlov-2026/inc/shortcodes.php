<?php
/**
 * Шорткоды из старых плагинов, которые остались в текстах страниц:
 * [embedyt] (YouTube Embed Plus), [su_button], [su_box], [su_document] (Shortcodes Ultimate).
 * Старые плагины на новом сайте не нужны: шорткоды выводятся средствами темы.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Ссылка YouTube из [embedyt] в адрес для iframe: трансляция канала, плейлист или видео. */
function kz_yt_embed_src( $url ) {
	$url = html_entity_decode( trim( wp_strip_all_tags( (string) $url ) ), ENT_QUOTES );
	$q   = array();
	parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
	if ( ! empty( $q['live'] ) && ! empty( $q['channel'] ) ) {
		return 'https://www.youtube.com/embed/live_stream?channel=' . rawurlencode( $q['channel'] );
	}
	if ( ! empty( $q['list'] ) ) {
		return 'https://www.youtube.com/embed/videoseries?list=' . rawurlencode( $q['list'] );
	}
	if ( ! empty( $q['v'] ) ) {
		return 'https://www.youtube.com/embed/' . rawurlencode( $q['v'] );
	}
	if ( preg_match( '#(?:youtu\.be/|/embed/|/shorts/)([\w-]{11})#', $url, $m ) ) {
		return 'https://www.youtube.com/embed/' . $m[1];
	}
	return '';
}

add_shortcode( 'embedyt', function ( $atts, $content = '' ) {
	$src = kz_yt_embed_src( $content );
	if ( ! $src ) {
		return '';
	}
	return '<div class="kz-embed"><iframe src="' . esc_url( $src ) . '" title="Видео Клуба Алексея Козлова" loading="lazy" allow="autoplay; encrypted-media; fullscreen; picture-in-picture" allowfullscreen></iframe></div>';
} );

/** Ссылки на файлы старого домена ведут на текущий сайт (после переезда это тот же адрес). */
function kz_local_url( $url ) {
	$url = preg_replace( '#^https?://(www\.)?kozlovclub\.ru#i', untrailingslashit( home_url() ), trim( (string) $url ) );
	return $url;
}

add_shortcode( 'su_button', function ( $atts, $content = '' ) {
	$a = shortcode_atts( array( 'url' => '', 'target' => '' ), $atts );
	if ( '' === $a['url'] ) {
		return do_shortcode( $content );
	}
	$url  = kz_local_url( $a['url'] );
	$ext  = preg_match( '#^https?://#', $url ) && 0 !== strpos( $url, home_url() );
	$file = (bool) preg_match( '/\.pdf($|\?)/i', $url );
	return '<a class="kz-btn" href="' . esc_url( $url ) . '"' . ( $ext || $file || 'blank' === $a['target'] ? ' target="_blank" rel="noopener"' : '' ) . '>' . wp_kses_post( do_shortcode( $content ) ) . '</a>';
} );

add_shortcode( 'su_box', function ( $atts, $content = '' ) {
	$a = shortcode_atts( array( 'title' => '' ), $atts );
	return '<div class="kz-box">' . ( '' !== $a['title'] ? '<div class="kz-box-t">' . esc_html( $a['title'] ) . '</div>' : '' ) . '<div class="kz-box-b">' . wp_kses_post( do_shortcode( $content ) ) . '</div></div>';
} );

add_shortcode( 'su_document', function ( $atts ) {
	$a = shortcode_atts( array( 'url' => '', 'height' => '900' ), $atts );
	if ( '' === $a['url'] ) {
		return '';
	}
	$url = kz_local_url( $a['url'] );
	$h   = max( 400, min( 2000, (int) $a['height'] ) );
	// PDF показывается браузером прямо на странице; на телефонах, где встроенного просмотра нет, остаётся кнопка.
	return '<div class="kz-doc"><object data="' . esc_url( $url ) . '#view=FitH" type="application/pdf" style="height:' . (int) $h . 'px"><p><a class="kz-btn" href="' . esc_url( $url ) . '" target="_blank" rel="noopener">Открыть PDF</a></p></object></div>';
} );

/** Страница «Календарь событий» (/calendar/) была видом плагина календаря: показываем афишу на 30 дней и выбор даты. */
add_filter( 'the_content', function ( $content ) {
	if ( ! is_page( 'calendar' ) || ! in_the_loop() || '' !== trim( wp_strip_all_tags( $content ) ) || ! function_exists( 'kozlov_concerts' ) ) {
		return $content;
	}
	$list = kozlov_concerts( array( 'from' => wp_date( 'Y-m-d 00:00:00' ), 'to' => wp_date( 'Y-m-d 23:59:59', strtotime( '+30 days' ) ), 'limit' => 300 ) );
	return '<p><a href="#" class="kz-btn calendar-toggler">Выбрать дату в календаре</a></p>' . kz_afisha( $list, 'line' );
}, 20 );
