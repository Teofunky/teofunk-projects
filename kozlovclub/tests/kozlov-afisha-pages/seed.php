<?php
// Тестовые концерты для Kozlov Afisha Pages. «Сейчас» в тестах: пятница 09.10.2026 18:30 по Москве.
// Запуск: wp eval-file seed.php
global $wpdb;
$table = $wpdb->prefix . 'ai1ec_events';
$wpdb->query( "DELETE FROM {$table}" );
foreach ( get_posts( array( 'post_type' => array( 'ai1ec_event', 'page' ), 'post_status' => 'any', 'numberposts' => -1 ) ) as $p ) {
	wp_delete_post( $p->ID, true );
}

function af_wall_utc( $wall ) { $d = new DateTime( $wall, new DateTimeZone( 'UTC' ) ); return $d->getTimestamp(); }
function af_real( $wall, $tz ) { $d = new DateTime( $wall, new DateTimeZone( $tz ) ); return $d->getTimestamp(); }

$hex = '6a9ad87181b34b2f0c254d35';
$cases = array(
	'T1'  => array( 'title' => 'КОНЦЕРТ ИДЁТ СЕЙЧАС', 'stage' => array( 'location_myasnitskaya' ), 'start' => '2026-10-09 18:00', 'end' => '2026-10-09 20:30', 'cost' => '[:ru]от 1500 руб.[:en]from 1500', 'tc' => $hex, 'genres' => array( 'Джаз|Jazz' ) ),
	'T2'  => array( 'title' => 'ДНЕВНОЙ УЖЕ ПРОШЁЛ', 'stage' => array( 'location_mansarda' ), 'start' => '2026-10-09 12:00', 'end' => '2026-10-09 14:00', 'cost' => 'от 500 руб.', 'tc' => $hex, 'genres' => array( 'Джаз|Jazz' ) ),
	'T3'  => array( 'title' => 'НОЧНОЙ ДЖЕМ', 'stage' => array( 'location_the_beat' ), 'start' => '2026-10-09 22:00', 'end' => '2026-10-10 00:30', 'cost' => '[:ru] ДЕПОЗИТ 2 000 РУБ.[:en] DEPOSIT', 'tc' => '', 'genres' => array( 'Джем-сэшн|Jam' ) ),
	'W1'  => array( 'title' => 'СУББОТНИЙ ДЖАЗ', 'stage' => array( 'location_main_scene' ), 'start' => '2026-10-10 20:00', 'end' => '2026-10-10 22:00', 'cost' => 'от 2000 руб.', 'tc' => $hex, 'genres' => array( 'Джаз|Jazz', 'Соул|Soul' ) ),
	'W2'  => array( 'title' => 'ВОСКРЕСНЫЙ БЕСПЛАТНЫЙ', 'stage' => array( 'location_mansarda' ), 'start' => '2026-10-11 14:00', 'end' => '2026-10-11 16:00', 'cost' => '[:ru]Вход свободный[:en]Free entry', 'tc' => '', 'genres' => array( 'Джаз-рок|Jazz-rock', 'Вход свободный|Free' ) ),
	'M1'  => array( 'title' => 'ПОНЕДЕЛЬНИК', 'stage' => array( 'location_main_scene' ), 'start' => '2026-10-12 20:00', 'end' => '2026-10-12 22:00', 'cost' => 'от 1000 руб.', 'tc' => $hex, 'genres' => array( 'Блюз|Blues' ) ),
	'FE'  => array( 'title' => 'ФЕСТИВАЛЬ ВЕСЬ ДЕНЬ', 'stage' => array(), 'start' => '2026-10-17 00:00', 'end' => '2026-10-18 00:00', 'cost' => 'Вход свободный', 'tc' => '', 'allday' => 1, 'genres' => array( 'Фестиваль|Festival', 'Джаз|Jazz' ) ),
	'N1'  => array( 'title' => 'НОЯБРЬСКИЙ БЛЮЗ', 'stage' => array( 'location_the_beat' ), 'start' => '2026-11-05 20:00', 'end' => '2026-11-05 22:00', 'cost' => 'от 1200 руб.', 'tc' => 'https://ticketscloud.com/v1/widgets/common?event=6a9ad87181b34b2f0c254d99', 'genres' => array( 'Блюз|Blues' ) ),
	'J1'  => array( 'title' => 'ЯНВАРСКИЙ ДЖАЗ', 'stage' => array( 'location_main_scene' ), 'start' => '2027-01-10 20:00', 'end' => '2027-01-10 22:00', 'cost' => 'от 3000 руб.', 'tc' => $hex, 'genres' => array( 'Джаз|Jazz' ) ),
	'RR'  => array( 'title' => 'ПОВТОРЯЮЩЕЕСЯ', 'stage' => array( 'location_the_beat' ), 'start' => '2026-10-13 20:00', 'end' => '2026-10-13 22:00', 'cost' => 'от 1000 руб.', 'tc' => $hex, 'rrule' => 'FREQ=WEEKLY;', 'genres' => array( 'Джаз|Jazz' ) ),
	'DR'  => array( 'title' => 'ЧЕРНОВИК', 'stage' => array( 'location_the_beat' ), 'start' => '2026-10-14 20:00', 'end' => '2026-10-14 22:00', 'cost' => 'от 1000 руб.', 'tc' => $hex, 'status' => 'draft', 'genres' => array( 'Джаз|Jazz' ) ),
	'PW'  => array( 'title' => 'С ПАРОЛЕМ', 'stage' => array( 'location_the_beat' ), 'start' => '2026-10-15 20:00', 'end' => '2026-10-15 22:00', 'cost' => 'от 1000 руб.', 'tc' => $hex, 'password' => 'x', 'genres' => array( 'Джаз|Jazz' ) ),
	'KD'  => array( 'title' => 'ДЕТСКИЙ', 'stage' => array( 'location_mansarda' ), 'start' => '2026-10-18 12:00', 'end' => '2026-10-18 13:00', 'cost' => 'от 500 руб.', 'tc' => $hex, 'genres' => array( 'Детям| For kids' ) ),
	'S0'  => array( 'title' => 'СЕРИАЛИЗОВАННАЯ ЦЕНА', 'stage' => array( 'location_mansarda' ), 'start' => '2026-10-20 20:00', 'end' => '2026-10-20 22:00', 'cost' => 'a:2:{s:4:"cost";s:0:"";s:7:"is_free";b:0;}', 'tc' => '', 'genres' => array( 'Классика|Classics' ) ),
	'S1'  => array( 'title' => 'СЕРИАЛИЗОВАННАЯ БЕСПЛАТНО', 'stage' => array( 'location_mansarda' ), 'start' => '2026-10-21 20:00', 'end' => '2026-10-21 22:00', 'cost' => 'a:2:{s:4:"cost";s:0:"";s:7:"is_free";b:1;}', 'tc' => '', 'genres' => array( 'Этника|Ethnics' ) ),
	'XS'  => array( 'title' => 'ОПАСНО <script>alert(1)</script> &amp; «КАВЫЧКИ» "q" \'a\'', 'stage' => array( 'location_mansarda' ), 'start' => '2026-10-22 20:00', 'end' => '2026-10-22 22:00', 'cost' => '<b>от 700 руб.</b><script>x</script>', 'tc' => '"><script>alert(2)</script>', 'genres' => array( 'Рок|Rock' ) ),
	'MS'  => array( 'title' => 'ПОЯС МОСКВА', 'stage' => array( 'location_main_scene' ), 'tz' => 'Europe/Moscow', 'start' => '2026-10-23 19:30', 'end' => '2026-10-23 21:30', 'cost' => 'от 1200 руб.', 'tc' => $hex, 'genres' => array( 'Фьюжн|Fusion' ) ),
	'YO'  => array( 'title' => 'ЁЖИК В ТУМАНЕ', 'stage' => array( 'location_the_beat' ), 'start' => '2026-10-24 20:00', 'end' => '2026-10-24 22:00', 'cost' => 'от 1000 руб.', 'tc' => $hex, 'genres' => array( 'Этно-джаз|Ethno-jazz' ) ),
	'NI'  => array( 'title' => 'БЕЗ КАРТИНКИ', 'stage' => array( 'location_the_beat' ), 'start' => '2026-10-25 20:00', 'end' => '2026-10-25 22:00', 'cost' => 'от 1000 руб.', 'tc' => $hex, 'img' => 0, 'genres_rows' => array( 'Фанк|Funk', 'Соул|Soul' ) ),
	'LT'  => array( 'title' => 'ОЧЕНЬ ДЛИННОЕ НАЗВАНИЕ КОНЦЕРТА, КОТОРОЕ НЕ ПОМЕЩАЕТСЯ В ДВЕ СТРОКИ КАРТОЧКИ НА ГЛАВНОЙ', 'stage' => array( 'location_the_beat' ), 'start' => '2026-10-26 20:00', 'end' => '2026-10-26 22:00', 'cost' => 'от 1000 руб.', 'tc' => $hex, 'genres' => array( 'Джаз|Jazz' ) ),
	'NE'  => array( 'title' => 'БЕЗ ОКОНЧАНИЯ', 'stage' => array( 'location_the_beat' ), 'start' => '2026-10-09 13:00', 'end' => '2026-10-09 13:00', 'cost' => 'от 1000 руб.', 'tc' => $hex, 'instant' => 1, 'genres' => array( 'Джаз|Jazz' ) ),
	'FAR' => array( 'title' => 'ДАЛЕКО', 'stage' => array( 'location_the_beat' ), 'start' => '2027-06-01 20:00', 'end' => '2027-06-01 22:00', 'cost' => 'от 1000 руб.', 'tc' => $hex, 'genres' => array( 'Джаз|Jazz' ) ),
);

$map = array();
foreach ( $cases as $key => $c ) {
	$args = array(
		'post_type'   => 'ai1ec_event',
		'post_title'  => $c['title'],
		'post_status' => isset( $c['status'] ) ? $c['status'] : 'publish',
		'post_name'   => 'af-' . strtolower( $key ),
	);
	if ( isset( $c['password'] ) ) {
		$args['post_password'] = $c['password'];
	}
	kses_remove_filters();
	$id = wp_insert_post( wp_slash( $args ), true );
	if ( is_wp_error( $id ) ) {
		echo "ERR $key\n";
		continue;
	}
	$meta = array( 'location_main_scene' => '0', 'location_mansarda' => '0', 'location_the_beat' => '0', 'location_myasnitskaya' => '0', 'small_hall' => '0' );
	foreach ( $c['stage'] as $s ) {
		$meta[ $s ] = '1';
	}
	$meta['ticketscloud_link'] = $c['tc'];
	foreach ( $meta as $k => $v ) {
		update_post_meta( $id, $k, wp_slash( $v ) );
	}
	$img = isset( $c['img'] ) ? $c['img'] : 4;
	if ( $img ) {
		set_post_thumbnail( $id, $img );
	}
	if ( isset( $c['genres'] ) ) {
		update_post_meta( $id, 'genre_categories', $c['genres'] );
	}
	if ( isset( $c['genres_rows'] ) ) {
		foreach ( $c['genres_rows'] as $g ) {
			add_post_meta( $id, 'genre_categories', $g );
		}
	}
	$tz = isset( $c['tz'] ) ? $c['tz'] : 'UTC';
	$st = 'UTC' === $tz ? af_wall_utc( $c['start'] ) : af_real( $c['start'], $tz );
	$en = 'UTC' === $tz ? af_wall_utc( $c['end'] ) : af_real( $c['end'], $tz );
	$wpdb->insert( $table, array( 'post_id' => $id, 'start' => $st, 'end' => $en, 'timezone_name' => $tz, 'allday' => isset( $c['allday'] ) ? 1 : 0, 'instant_event' => isset( $c['instant'] ) ? 1 : 0, 'recurrence_rules' => isset( $c['rrule'] ) ? $c['rrule'] : '', 'cost' => $c['cost'], 'ticket_url' => '' ) );
	$map[ $key ] = $id;
}

// Страница с шорткодом (для проверки через сайт).
$page = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_title'   => 'Джаз в Москве',
		'post_name'    => 'dzhaz',
		'post_status'  => 'publish',
		'post_content' => "Вступительный текст.\n\n[kozlov_afisha genre=\"jazz\" days=\"400\"]\n\nТекст после.",
	)
);
$map['PAGE'] = $page;
$map['PAGE2'] = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_title'   => 'Черновик сегодня',
		'post_name'    => 'segodnya',
		'post_status'  => 'draft',
		'post_content' => '[kozlov_afisha when="today"] и [[kozlov_afisha]]',
	)
);

file_put_contents( __DIR__ . '/ids.json', wp_json_encode( $map ) );
echo 'seeded ' . count( $map ) . "\n";
