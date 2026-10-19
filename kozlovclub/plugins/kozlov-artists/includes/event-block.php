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

/**
 * Артисты концерта как данные: url, name, photo, text, more.
 * Этим пользуется и Kozlov Event Page (новая страница концерта рисует свой блок).
 */
function ka_event_artists( $post ) {
	if ( ! $post || ! isset( $post->ID ) ) { return array(); }
	$out = array();
	foreach ( ka_artists_for_event( $post ) as $aid ) {
		$out[] = array(
			'url'   => (string) get_permalink( $aid ),
			'name'  => (string) get_the_title( $aid ),
			'photo' => has_post_thumbnail( $aid ) ? (string) get_the_post_thumbnail_url( $aid, 'medium' ) : '',
			'text'  => trim( wp_strip_all_tags( (string) get_post_field( 'post_excerpt', $aid ) ) ), // сырой отрывок: фильтры календаря подмешивают в него данные концерта
			'more'  => 'Афиша, видео и история артиста',
		);
	}
	return $out;
}

/** Инициалы для артиста без фото */
function ka_initials( $name ) {
	$words = preg_split( '/[\s«»"\-]+/u', trim( (string) $name ), -1, PREG_SPLIT_NO_EMPTY );
	$out   = '';
	foreach ( array_slice( $words, 0, 2 ) as $w ) { $out .= mb_strtoupper( mb_substr( $w, 0, 1, 'UTF-8' ), 'UTF-8' ); }
	return $out;
}

function ka_plural( $n, $one, $few, $many ) {
	$n10 = $n % 10; $n100 = $n % 100;
	if ( 1 === $n10 && 11 !== $n100 ) { return $one; }
	if ( $n10 >= 2 && $n10 <= 4 && ( $n100 < 12 || $n100 > 14 ) ) { return $few; }
	return $many;
}

/** Строки компактного списка артистов */
function ka_event_rows_html( $list ) {
	$h = '<ul class="ka-eb__ul">';
	foreach ( $list as $a ) {
		$h .= '<li class="ka-eb__i ka-eb__i--row">';
		if ( '' !== $a['photo'] ) {
			$h .= '<span class="ka-eb__p" role="img" aria-label="' . esc_attr( $a['name'] ) . '" style="background-image:url(' . esc_url( $a['photo'] ) . ')"></span>';
		} else {
			$h .= '<span class="ka-eb__p ka-eb__p--none" aria-hidden="true">' . esc_html( ka_initials( $a['name'] ) ) . '</span>';
		}
		$h .= '<div class="ka-eb__b"><a class="ka-eb__n" href="' . esc_url( $a['url'] ) . '">' . esc_html( $a['name'] ) . '</a>';
		if ( '' !== $a['text'] ) { $h .= '<p class="ka-eb__t">' . esc_html( $a['text'] ) . '</p>'; }
		$h .= '</div></li>';
	}
	return $h . '</ul>';
}

/**
 * HTML блока на странице концерта (пусто, если артист не найден).
 * Один артист: карточка с фото, описанием и ссылкой. Несколько: компактный список,
 * первые три сразу, остальные под «Ещё N артистов», чтобы 7 артистов не растягивали страницу.
 */
function ka_event_block_html( $post ) {
	$list = ka_event_artists( $post );
	if ( ! $list ) { return ''; }
	$css = '.ka-eb{margin:32px 0;padding:24px;background:#f6f4f3;font-family:Roboto,sans-serif}'
		. '.ka-eb .ka-eb__l{display:block;font-size:12px;font-weight:500;letter-spacing:2.4px;text-transform:uppercase;color:#f34841}'
		. '.ka-eb .ka-eb__i{display:flex;gap:20px;align-items:center;margin-top:14px;flex-wrap:wrap}'
		. '.ka-eb .ka-eb__p{display:block;flex:0 0 96px;width:96px;height:96px;background-size:cover;background-position:center;background-color:#e6e6e6}'
		. '.ka-eb .ka-eb__n{font-size:20px;font-weight:500;text-transform:uppercase;color:#020817!important;text-decoration:none!important}'
		. '.ka-eb .ka-eb__t{margin:6px 0 0;font-size:15px;line-height:1.6;color:#424242;text-align:left}'
		. '.ka-eb .ka-eb__a{display:inline-block;margin-top:10px;font-size:12px;font-weight:500;letter-spacing:2px;text-transform:uppercase;color:#f34841!important;text-decoration:none!important;border-bottom:1px solid #f34841}'
		// список из нескольких артистов
		. '.ka-eb .ka-eb__ul{list-style:none!important;margin:10px 0 0!important;padding:0!important}'
		. '.ka-eb .ka-eb__ul li::before{content:none!important}'
		. '.ka-eb .ka-eb__i--row{position:relative;flex-wrap:nowrap;gap:14px;margin:0!important;padding:10px 0;border-top:1px solid #e4e0de}'
		. '.ka-eb .ka-eb__ul .ka-eb__i--row:first-child{border-top:0}'
		. '.ka-eb .ka-eb__i--row::after{content:"";flex:none;width:8px;height:8px;margin-right:4px;border-top:1.5px solid #9a9a9a;border-right:1.5px solid #9a9a9a;transform:rotate(45deg)}'
		. '.ka-eb .ka-eb__i--row .ka-eb__p{flex:0 0 56px;width:56px;height:56px}'
		. '.ka-eb .ka-eb__p--none{display:flex;align-items:center;justify-content:center;font-size:16px;font-weight:500;color:#6b6b6b}'
		. '.ka-eb .ka-eb__b{flex:1;min-width:0}'
		. '.ka-eb .ka-eb__i--row .ka-eb__n{font-size:15px;line-height:1.3}'
		. '.ka-eb .ka-eb__i--row .ka-eb__n::after{content:"";position:absolute;inset:0}'
		. '.ka-eb .ka-eb__i--row .ka-eb__t{margin:2px 0 0;font-size:14px;line-height:1.4;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'
		. '.ka-eb .ka-eb__i--row:hover .ka-eb__n{text-decoration:underline!important;text-underline-offset:3px}'
		. '.ka-eb .ka-eb__more{border-top:1px solid #e4e0de}'
		. '.ka-eb .ka-eb__more summary{display:block;cursor:pointer;list-style:none;padding:14px 0 0;font-size:12px;font-weight:500;letter-spacing:2px;text-transform:uppercase;color:#f34841}'
		. '.ka-eb .ka-eb__more summary::-webkit-details-marker{display:none}'
		. '.ka-eb .ka-eb__more[open] summary{display:none}'
		. '.ka-eb .ka-eb__more .ka-eb__ul{margin-top:0!important}'
		. '@media (max-width:600px){.ka-eb{padding:20px 16px}.ka-eb .ka-eb__n{font-size:17px}}';
	$html = '<style>' . $css . '</style>';

	if ( 1 === count( $list ) ) {
		$a     = $list[0];
		$html .= '<aside class="ka-eb" aria-label="Об артисте"><span class="ka-eb__l">Об артисте</span><div class="ka-eb__i">';
		if ( '' !== $a['photo'] ) {
			// фоном: плагин ленивой загрузки не подменяет такие картинки заглушкой
			$html .= '<a class="ka-eb__p" href="' . esc_url( $a['url'] ) . '" role="img" aria-label="' . esc_attr( $a['name'] ) . '" style="background-image:url(' . esc_url( $a['photo'] ) . ')"></a>';
		}
		$html .= '<div><a class="ka-eb__n" href="' . esc_url( $a['url'] ) . '">' . esc_html( $a['name'] ) . '</a>';
		if ( '' !== $a['text'] ) { $html .= '<p class="ka-eb__t">' . esc_html( $a['text'] ) . '</p>'; }
		$html .= '<a class="ka-eb__a" href="' . esc_url( $a['url'] ) . '">Афиша, видео и история артиста</a></div></div></aside>';
		return $html;
	}

	$first = array_slice( $list, 0, 3 );
	$rest  = array_slice( $list, 3 );
	$html .= '<aside class="ka-eb ka-eb--list" aria-label="Артисты вечера"><span class="ka-eb__l">Артисты вечера</span>' . ka_event_rows_html( $first );
	if ( $rest ) {
		$n     = count( $rest );
		$html .= '<details class="ka-eb__more"><summary>Ещё ' . (int) $n . ' ' . ka_plural( $n, 'артист', 'артиста', 'артистов' ) . '</summary>' . ka_event_rows_html( $rest ) . '</details>';
	}
	return $html . '</aside>';
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
	echo '<script>(function(){var t=document.getElementById("ka-eb-tpl");if(!t||document.getElementById("kep"))return;var h=document.querySelector(".event_page_post_content .description")||document.querySelector(".event_page_post_content")||document.querySelector(".post_content");if(h){var n=t.content.cloneNode(true);var first=h.querySelector(":scope > .textblock, :scope > .artist_desc");if(first&&h.classList.contains("description")){h.insertBefore(n,h.firstChild);}else{h.appendChild(n);}}})();</script>';
}, 99 );
