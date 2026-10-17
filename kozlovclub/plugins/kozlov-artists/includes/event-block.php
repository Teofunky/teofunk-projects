<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Блок «Об артисте» на странице концерта (ai1ec_event): ссылка на страницу артиста.
 * Артист подбирается автоматически: по тем же ключевым словам и исключениям, что и афиша артиста.
 */
function ka_artists_for_event( $event_post ) {
	$title = (string) $event_post->post_title;
	$full  = $title . ' ' . wp_strip_all_tags( (string) $event_post->post_content );
	$ids   = get_posts( array( 'post_type' => 'kozlov_artist', 'post_status' => 'publish', 'numberposts' => 100, 'fields' => 'ids' ) );
	$found = array();
	foreach ( $ids as $aid ) {
		$hay  = get_post_meta( $aid, 'ka_title_only', true ) ? $title : $full;
		$inc  = ka_lines( $aid, 'ka_keywords' );
		$exc  = ka_lines( $aid, 'ka_exclude' );
		$hit  = false;
		foreach ( $inc as $kw ) { if ( false !== mb_stripos( $hay, $kw ) ) { $hit = true; break; } }
		if ( ! $hit ) { continue; }
		foreach ( $exc as $kw ) { if ( false !== mb_stripos( $full, $kw ) ) { continue 2; } }
		$found[] = (int) $aid;
	}
	return $found;
}

/** HTML блока «Об артисте» для записи-концерта (пусто, если артист не найден) */
function ka_event_block_html( $post ) {
	$artists = ka_artists_for_event( $post );
	if ( ! $artists ) { return ''; }
	$html = '<style>.ka-eb{margin:32px 0;padding:24px;background:#f6f4f3;font-family:Roboto,sans-serif}.ka-eb__l{font-size:12px;font-weight:500;letter-spacing:2.4px;text-transform:uppercase;color:#f34841}.ka-eb__i{display:flex;gap:20px;align-items:center;margin-top:14px;flex-wrap:wrap}.ka-eb__i img{width:96px;height:96px;object-fit:cover;display:block}.ka-eb__n{font-size:20px;font-weight:500;text-transform:uppercase;color:#020817!important;text-decoration:none!important}.ka-eb__t{margin:6px 0 0;font-size:15px;line-height:1.6;color:#424242}.ka-eb__a{display:inline-block;margin-top:10px;font-size:12px;font-weight:500;letter-spacing:2px;text-transform:uppercase;color:#f34841!important;text-decoration:none!important;border-bottom:1px solid #f34841}</style>';
	foreach ( $artists as $aid ) {
		$url  = get_permalink( $aid );
		$name = get_the_title( $aid );
		$html .= '<aside class="ka-eb" aria-label="Об артисте"><span class="ka-eb__l">Об артисте</span><div class="ka-eb__i">';
		if ( has_post_thumbnail( $aid ) ) {
			$html .= '<a href="' . esc_url( $url ) . '">' . get_the_post_thumbnail( $aid, 'thumbnail', array( 'alt' => esc_attr( $name ), 'loading' => 'lazy' ) ) . '</a>';
		}
		$html .= '<div><a class="ka-eb__n" href="' . esc_url( $url ) . '">' . esc_html( $name ) . '</a>';
		$ex = wp_strip_all_tags( (string) get_post_field( 'post_excerpt', $aid ) ); // сырой отрывок: фильтры календаря подмешивают в него данные концерта
		if ( $ex ) { $html .= '<p class="ka-eb__t">' . esc_html( $ex ) . '</p>'; }
		$html .= '<a class="ka-eb__a" href="' . esc_url( $url ) . '">Афиша, видео и история артиста</a></div></div></aside>';
	}
	return $html;
}

/** Способ 1: добавляем в текст записи, когда тема выводит его через the_content (только после <head>) */
add_filter( 'the_content', function ( $content ) {
	if ( ! is_singular( 'ai1ec_event' ) || ! is_main_query() || ! did_action( 'wp_head' ) ) { return $content; }
	if ( ! empty( $GLOBALS['ka_eb_done'] ) ) { return $content; }
	$post = get_queried_object();
	if ( ! $post || ! isset( $post->ID ) || get_the_ID() !== $post->ID ) { return $content; }
	$html = ka_event_block_html( $post );
	if ( ! $html ) { return $content; }
	$GLOBALS['ka_eb_done'] = true;
	return $content . $html;
}, 30 );

/** Способ 2 (запасной): тема не вызывала the_content, переносим блок под описание концерта */
add_action( 'wp_footer', function () {
	if ( ! is_singular( 'ai1ec_event' ) || ! empty( $GLOBALS['ka_eb_done'] ) ) { return; }
	$html = ka_event_block_html( get_queried_object() );
	if ( ! $html ) { return; }
	echo '<template id="ka-eb-tpl">' . $html . '</template>';
	echo '<script>(function(){var t=document.getElementById("ka-eb-tpl");if(!t)return;var h=document.querySelector(".event_page_post_content .description")||document.querySelector(".event_page_post_content")||document.querySelector(".post_content");if(h){h.appendChild(t.content.cloneNode(true));}})();</script>';
}, 99 );
