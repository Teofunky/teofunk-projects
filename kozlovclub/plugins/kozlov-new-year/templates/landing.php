<?php
/**
 * Разметка страницы. Повторяет утверждённый макет 1:1, тексты и картинки берутся из редактора.
 *
 * @package Kozlov_New_Year
 *
 * @var array $d   Данные страницы.
 * @var array $ctx Контекст вывода.
 */

defined( 'ABSPATH' ) || exit;

$c  = $d['common'];
$h  = $d['hero'];
$p  = $d['program'];
$n  = $d['night'];
$lo = $d['locations'];
$q  = $d['faq'];
$fi = $d['final'];
$st = kny_get_settings();

$GLOBALS['kny_phone'] = $c['phone'];

$tel      = kny_tel( $c['phone'] );
$events   = kny_visible_events( $p['events'] );
$stages   = kny_stage_list( $p['stages'], $events );
$total    = count( $events );
$logo     = kny_img( $c['logo'] );
$og_image = kny_img( $c['og_image'] );
$ym_id    = preg_replace( '/\D/', '', (string) $st['metrika_id'] );
$blank    = ' target="_blank" rel="noopener"';
$css_ver  = KNY_VERSION . '.' . (int) @filemtime( KNY_DIR . 'assets/css/landing.css' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
$js_ver   = KNY_VERSION . '.' . (int) @filemtime( KNY_DIR . 'assets/js/landing.js' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
$countdown_iso = ( $h['countdown_at'] ? $h['countdown_at'] : '2027-01-01T00:00' ) . ':00+03:00';
$icon     = function_exists( 'get_site_icon_url' ) ? get_site_icon_url( 32 ) : '';
$icon180  = function_exists( 'get_site_icon_url' ) ? get_site_icon_url( 180 ) : '';
$periods  = array(
	'day'     => $p['tab_day'],
	'evening' => $p['tab_evening'],
	'night'   => $p['tab_night'],
);
?><!doctype html>
<html lang="ru">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"><meta name="theme-color" content="#591d1f">
<title><?php echo esc_html( $c['seo_title'] ); ?></title>
<meta name="description" content="<?php echo esc_attr( $c['seo_description'] ); ?>">
<?php if ( $ctx['noindex'] ) : ?>
<meta name="robots" content="noindex, nofollow">
<?php elseif ( $ctx['url'] ) : ?>
<link rel="canonical" href="<?php echo esc_url( $ctx['url'] ); ?>">
<?php endif; ?>
<meta property="og:type" content="website"><meta property="og:locale" content="ru_RU"><meta property="og:site_name" content="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
<?php if ( $ctx['url'] ) : ?><meta property="og:url" content="<?php echo esc_url( $ctx['url'] ); ?>"><?php endif; ?>
<meta property="og:title" content="<?php echo esc_attr( $c['og_title'] ); ?>"><meta property="og:description" content="<?php echo esc_attr( $c['og_description'] ); ?>">
<?php if ( $og_image ) : ?><meta property="og:image" content="<?php echo esc_url( $og_image ); ?>"><meta name="twitter:card" content="summary_large_image"><?php endif; ?>

<?php if ( $icon ) : ?><link rel="icon" href="<?php echo esc_url( $icon ); ?>" sizes="32x32"><link rel="apple-touch-icon" href="<?php echo esc_url( $icon180 ); ?>"><?php endif; ?>
<link rel="preload" href="<?php echo esc_url( KNY_URL . 'assets/fonts/montserrat-400.woff2' ); ?>" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="<?php echo esc_url( KNY_URL . 'assets/fonts/montserrat-700.woff2' ); ?>" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?php echo esc_url( KNY_URL . 'assets/css/landing.css?ver=' . $css_ver ); ?>">
<script src="<?php echo esc_url( KNY_URL . 'assets/js/landing.js?ver=' . $js_ver ); ?>" defer></script>
<?php echo kny_analytics_head( $st ); // phpcs:ignore WordPress.Security.EscapeOutput -- код счётчиков задаёт администратор. ?>
</head>
<body<?php echo $ym_id ? ' data-kny-ym="' . esc_attr( $ym_id ) . '"' : ''; ?>>
<?php echo kny_analytics_body( $st ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
<a class="skip" href="#program">Перейти к программе</a>
<header class="header"><a class="brand" href="#top" aria-label="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>"><img src="<?php echo esc_url( $logo ); ?>" alt="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" width="845" height="479"></a><nav aria-label="Основная навигация"><a href="#program"><?php echo esc_html( $c['nav_program'] ); ?></a><a href="#night"><?php echo esc_html( $c['nav_night'] ); ?></a><a href="#questions"><?php echo esc_html( $c['nav_faq'] ); ?></a></nav><a class="header-ticket" href="#program"><?php echo esc_html( $c['header_button'] ); ?> <span>↗</span></a></header>
<main>
<section class="hero-track" id="top" aria-labelledby="hero-title"><div class="hero-sticky">
<div class="hero-topline"><span><?php echo esc_html( $h['topline_left'] ); ?></span><span><?php echo esc_html( $h['topline_right'] ); ?></span></div>
<div class="hero-countdown" aria-label="Обратный отсчёт до Нового года по московскому времени" data-target="<?php echo esc_attr( $countdown_iso ); ?>" data-title="<?php echo esc_attr( $h['countdown_title'] ); ?>" data-done="<?php echo esc_attr( $h['countdown_done'] ); ?>"><p id="countdown-title"><?php echo esc_html( $h['countdown_title'] ); ?></p><div class="countdown-digits" role="timer" aria-labelledby="countdown-title" aria-live="off"><div><strong id="count-days">—</strong><span id="label-days">дней</span></div><b aria-hidden="true">:</b><div><strong id="count-hours">—</strong><span id="label-hours">часов</span></div><b aria-hidden="true">:</b><div><strong id="count-minutes">—</strong><span id="label-minutes">минут</span></div></div><small><?php echo esc_html( $h['countdown_note'] ); ?></small></div>
<h1 class="sr-only" id="hero-title"><?php echo esc_html( $h['h1'] ); ?></h1>
<?php if ( $h['tree'] ) : ?><div class="fir-layer" aria-hidden="true"><img src="<?php echo esc_url( kny_img( $h['tree'] ) ); ?>" width="1280" height="1280" alt="" fetchpriority="high"></div><?php endif; ?>

<?php if ( $h['goat'] ) : ?><div class="ornament-wrap" aria-hidden="true"><div class="ornament-pivot"><img class="ornament-image" src="<?php echo esc_url( kny_img( $h['goat'] ) ); ?>" width="1280" height="1280" alt="" fetchpriority="high"></div></div><?php endif; ?>

<div class="hero-year" aria-hidden="true"><?php echo kny_lines( $h['year'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?><span><?php echo kny_lines( $h['year_tagline'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span></div>
<div class="hero-intro"><p><?php echo kny_lines( $h['intro'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php if ( $h['award_note'] ) : ?><sup class="award-star">*</sup><?php endif; ?></p><?php if ( $h['award_note'] ) : ?><small class="award-note"><?php echo esc_html( $h['award_note'] ); ?></small><?php endif; ?><a class="button primary" href="#program"><?php echo esc_html( $h['intro_button'] ); ?> <span>↗</span></a></div>
<div class="hero-second"><h2><?php echo esc_html( $h['second_title'] ); ?><br><em class="dj-line"><?php echo esc_html( $h['second_title_em'] ); ?></em></h2><?php echo kny_rich( $h['second_text'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php echo kny_rich( $h['second_artists'], 'hero-artists' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><a class="button light second-cta" href="#program"><?php echo esc_html( $h['second_button'] ); ?> <span>↗</span></a></div>
<div class="hero-bottom"><a href="#program" class="scroll-cue"><?php echo esc_html( $h['scroll_cue'] ); ?> <span aria-hidden="true">↓</span></a><button class="motion" id="motion" aria-pressed="false" aria-label="Остановить анимацию">Ⅱ <span>Анимация</span></button><span><?php echo esc_html( $h['bottom_right'] ); ?></span></div>
</div></section>
<section class="program section" id="program" aria-labelledby="program-title"><div class="section-heading"><div><span class="eyebrow"><?php echo esc_html( $p['eyebrow'] ); ?></span><h2 id="program-title"><?php echo esc_html( $p['title'] ); ?><br><em><?php echo esc_html( $p['title_em'] ); ?></em></h2></div><p><?php echo esc_html( $p['lead'] ); ?></p></div>
<div class="filters"><div class="filter-tabs" role="group" aria-label="Время концерта"><?php foreach ( $periods as $period => $label ) : ?><?php if ( '' !== trim( $label ) ) : ?><button data-period="<?php echo esc_attr( $period ); ?>" aria-pressed="false"><?php echo esc_html( $label ); ?></button><?php endif; ?><?php endforeach; ?><button class="active" data-period="all" aria-pressed="true"><?php echo esc_html( $p['tab_all'] ); ?></button></div><label class="stage-filter"><span class="sr-only">Сцена</span><select id="stage"><option value="all"><?php echo esc_html( $p['stage_placeholder'] ); ?></option><?php foreach ( $stages as $stage ) : ?><option value="<?php echo esc_attr( $stage ); ?>"><?php echo esc_html( $stage ); ?></option><?php endforeach; ?></select></label></div>
<p class="result-count" id="result-count" role="status" aria-live="polite" data-suffix="<?php echo esc_attr( $p['count_suffix'] ); ?>"><?php echo esc_html( $total . ' ' . kny_plural( $total, 'событие', 'события', 'событий' ) . ( $p['count_suffix'] ? ' · ' . $p['count_suffix'] : '' ) ); ?></p><div class="event-grid" id="events">
<?php
foreach ( $events as $i => $e ) :
	$has_url   = '' !== $e['url'];
	$is_book   = 'ticket' !== $e['action'];
	$btn_phone = 'book_phone' === $e['action'] || ! $has_url;
	$btn_href  = $btn_phone ? $tel : $e['url'];
	$btn_label = $is_book ? $p['btn_book'] : $p['btn_ticket'];
	$aria      = $is_book ? ( $btn_phone ? 'Позвонить для бронирования' : 'Забронировать место' ) : 'Купить билет';
	$goal      = $btn_phone ? 'ny_call' : ( $is_book ? 'ny_booking' : 'ny_ticket' );
	$img_href  = $has_url ? $e['url'] : '#night';
	$img_attr  = $has_url ? $blank : '';
	$image     = $e['image'] ? kny_img( $e['image'] ) : '';
	$start     = strtr(
		$p['start_line'],
		array(
			'{address}' => $e['address'],
			'{time}'    => $e['time'],
		)
	);
	$detail    = kny_rich_inline( $e['detail'] );
	?>
<article class="event-card" data-period="<?php echo esc_attr( $e['period'] ); ?>" data-stage="<?php echo esc_attr( trim( $e['stage'] ) ); ?>" aria-labelledby="event-<?php echo (int) $i; ?>-title"><a class="event-image" style="display:block" href="<?php echo esc_url( $img_href ); ?>"<?php echo $img_attr; // phpcs:ignore WordPress.Security.EscapeOutput ?> aria-label="<?php echo esc_attr( $e['title'] ); ?>: подробнее о программе"><?php if ( $image ) : ?><img src="<?php echo esc_url( $image ); ?>" width="800" height="600" loading="lazy" alt="<?php echo esc_attr( $e['title'] ); ?>"><?php endif; ?><span class="time"><?php echo esc_html( $e['time'] ); ?></span></a><div class="event-meta"><span><?php echo esc_html( $e['stage'] ); ?></span><span><?php echo esc_html( $e['genre'] ); ?></span></div><h3 id="event-<?php echo (int) $i; ?>-title"><?php echo esc_html( $e['title'] ); ?></h3><p class="event-subtitle"><?php echo esc_html( $e['subtitle'] ); ?></p><p class="event-desc"><?php echo esc_html( $e['description'] ); ?></p><div class="event-bottom"><div class="event-price"><?php echo esc_html( $e['price'] ); ?><small><?php echo esc_html( $e['price_note'] ); ?></small></div><a class="button" href="<?php echo esc_url( $btn_href, array( 'http', 'https', 'tel' ) ); ?>"<?php echo $btn_phone ? '' : $blank; // phpcs:ignore WordPress.Security.EscapeOutput ?> data-kny-goal="<?php echo esc_attr( $goal ); ?>" aria-label="<?php echo esc_attr( $aria . ': ' . $e['title'] ); ?>"><?php echo esc_html( $btn_label ); ?> <span>↗</span></a></div><details class="event-detail"><summary><?php echo esc_html( $is_book ? $p['summary_book'] : $p['summary_ticket'] ); ?></summary><p><?php if ( '' !== $detail ) : ?><?php echo $detail; // phpcs:ignore WordPress.Security.EscapeOutput ?><br><br><?php endif; ?><?php echo esc_html( $start ); ?><br><a href="<?php echo esc_url( $img_href ); ?>"<?php echo $img_attr; // phpcs:ignore WordPress.Security.EscapeOutput ?>><?php echo esc_html( $p['more_link'] ); ?> ↗</a></p></details></article>
<?php endforeach; ?>
</div><div class="empty" id="empty"<?php echo $total ? ' hidden' : ''; ?>><h3><?php echo esc_html( $p['empty_title'] ); ?></h3><button class="button" id="reset"><?php echo esc_html( $p['empty_button'] ); ?> ↗</button></div>
</section>
<section class="night section" id="night"><div class="night-heading"><span class="eyebrow"><?php echo esc_html( $n['eyebrow'] ); ?></span><h2><?php echo esc_html( $n['title'] ); ?><br><em><?php echo esc_html( $n['title_em'] ); ?></em></h2><?php echo kny_rich( $n['text'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div><?php if ( $n['djs'] ) : ?><div class="night-stages"><?php foreach ( $n['djs'] as $dj ) : ?><article><span class="eyebrow"><?php echo esc_html( $dj['stage'] ); ?></span><h3><?php echo esc_html( $dj['name'] ); ?></h3><?php echo kny_rich( $dj['text'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></article><?php endforeach; ?></div><?php endif; ?><div class="dinner"><div class="dinner-copy"><span class="eyebrow"><?php echo esc_html( $n['dinner_eyebrow'] ); ?></span><h3><?php echo esc_html( $n['dinner_title'] ); ?><br><em><?php echo esc_html( $n['dinner_title_em'] ); ?></em></h3><?php echo kny_rich( $n['dinner_text'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php if ( $n['dinner_more_text'] ) : ?><details><summary><?php echo esc_html( $n['dinner_more'] ); ?></summary><?php echo kny_rich( $n['dinner_more_text'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></details><?php endif; ?></div><div class="dinner-table" aria-label="Праздничная сервировка">
<?php
foreach ( array( 'fish' => 'dish-fish', 'meat' => 'dish-meat', 'olivier' => 'dish-olivier', 'assortment' => 'dish-assortment' ) as $dish_key => $dish_class ) :
	if ( '' === $n[ 'dish_' . $dish_key ] ) {
		continue;
	}
	?>
<img class="dish <?php echo esc_attr( $dish_class ); ?>" src="<?php echo esc_url( kny_img( $n[ 'dish_' . $dish_key ] ) ); ?>" alt="<?php echo esc_attr( $n[ 'dish_' . $dish_key . '_alt' ] ); ?>" loading="lazy">
<?php endforeach; ?>
<small><?php echo esc_html( $n['dishes_note'] ); ?></small></div></div><?php if ( $n['sets'] ) : ?><div class="set-gallery"><?php foreach ( $n['sets'] as $set ) : ?><?php if ( $set['image'] ) : ?><figure><div class="set-photo"><img src="<?php echo esc_url( kny_img( $set['image'] ) ); ?>" alt="<?php echo esc_attr( $set['alt'] ); ?>" loading="lazy" width="1039" height="1514"></div><figcaption><?php echo esc_html( $set['caption'] ); ?></figcaption></figure><?php endif; ?><?php endforeach; ?></div><?php endif; ?><div class="night-booking"><div><strong><?php echo esc_html( $n['booking_price'] ); ?> <small><?php echo esc_html( $n['booking_unit'] ); ?></small></strong><p><?php echo esc_html( $n['booking_text'] ); ?></p></div><a class="button light" href="<?php echo esc_attr( $tel ); ?>" data-kny-goal="ny_call"><?php echo esc_html( $c['phone'] ); ?> <span>↗</span></a></div></section><section class="locations section" id="locations"><div class="location-image"><?php if ( $lo['image'] ) : ?><img src="<?php echo esc_url( kny_img( $lo['image'] ) ); ?>" width="1067" height="1600" alt="<?php echo esc_attr( $lo['image_alt'] ); ?>" loading="lazy"><?php endif; ?><?php if ( $lo['image_caption'] ) : ?><span><?php echo esc_html( $lo['image_caption'] ); ?></span><?php endif; ?></div><div class="location-content"><span class="eyebrow"><?php echo esc_html( $lo['eyebrow'] ); ?></span><h2><?php echo esc_html( $lo['title'] ); ?><br><em><?php echo esc_html( $lo['title_em'] ); ?></em></h2><?php foreach ( $lo['places'] as $pi => $place ) : ?><article><span class="index"><?php echo esc_html( str_pad( (string) ( $pi + 1 ), 2, '0', STR_PAD_LEFT ) ); ?></span><div><h3><?php echo esc_html( $place['name'] ); ?></h3><p><?php echo kny_lines( $place['info'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p><?php if ( $place['map'] ) : ?><a class="text-link" href="<?php echo esc_url( $place['map'] ); ?>"<?php echo $blank; // phpcs:ignore WordPress.Security.EscapeOutput ?>><?php echo esc_html( $lo['map_label'] ); ?> ↗</a><?php endif; ?></div></article><?php endforeach; ?><div class="contact"><span><?php echo esc_html( $lo['contact_label'] ); ?></span><a href="<?php echo esc_attr( $tel ); ?>" data-kny-goal="ny_call"><?php echo esc_html( $c['phone'] ); ?> ↗</a></div></div></section>
<section class="faq section" id="questions"><div><span class="eyebrow"><?php echo esc_html( $q['eyebrow'] ); ?></span><h2><?php echo esc_html( $q['title'] ); ?><br><em class="faq-second-line"><?php echo esc_html( $q['title_em'] ); ?></em></h2></div><div class="faq-items"><?php foreach ( $q['items'] as $item ) : ?><details><summary><?php echo esc_html( $item['q'] ); ?><span>+</span></summary><?php echo kny_rich( $item['a'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></details><?php endforeach; ?></div></section>
<section class="final-cta"><span class="eyebrow"><?php echo esc_html( $fi['eyebrow'] ); ?></span><h2><?php echo esc_html( $fi['title'] ); ?><br><em><?php echo esc_html( $fi['title_em'] ); ?></em></h2><a class="button light" href="#program"><?php echo esc_html( $fi['button'] ); ?> <span>↗</span></a></section>
</main><footer><a class="brand" href="#top" aria-label="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>"><img src="<?php echo esc_url( $logo ); ?>" alt="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" width="845" height="479"></a><div><a href="<?php echo esc_url( home_url( '/' ) ); ?>"<?php echo $blank; // phpcs:ignore WordPress.Security.EscapeOutput ?>><?php echo esc_html( $c['footer_site'] ); ?> ↗</a><?php if ( $c['privacy_url'] ) : ?><a href="<?php echo esc_url( $c['privacy_url'] ); ?>"<?php echo $blank; // phpcs:ignore WordPress.Security.EscapeOutput ?>><?php echo esc_html( $c['footer_privacy'] ); ?></a><?php endif; ?></div><span><?php echo esc_html( $c['copyright'] ); ?></span></footer>
<div class="mobile-cta"><a class="mobile-call" href="<?php echo esc_attr( $tel ); ?>" aria-label="Позвонить в клуб" data-kny-goal="ny_call">↗ <span><?php echo esc_html( $c['mobile_call'] ); ?></span></a><a href="#program"><?php echo esc_html( $c['mobile_cta'] ); ?> <span>↗</span></a></div>
<?php if ( $ctx['preview'] ) : ?>
<div class="kny-badge">Предпросмотр: изменения ещё не сохранены</div>
<?php elseif ( is_user_logged_in() && current_user_can( 'edit_pages' ) ) : ?>
<a class="kny-edit" href="<?php echo esc_url( admin_url( 'admin.php?page=kozlov-new-year' ) ); ?>">✎ Редактировать страницу</a>
<?php endif; ?>
</body></html>
