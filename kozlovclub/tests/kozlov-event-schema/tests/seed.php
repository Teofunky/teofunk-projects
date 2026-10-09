<?php
// Создаёт тестовые события, повторяющие реальные варианты с сайта. Запуск: wp eval-file seed.php
global $wpdb;
$table = $wpdb->prefix . 'ai1ec_events';
$wpdb->query( "DELETE FROM {$table}" );
foreach ( get_posts( array( 'post_type' => 'ai1ec_event', 'post_status' => 'any', 'numberposts' => -1 ) ) as $p ) { wp_delete_post( $p->ID, true ); }

function ts_wall_utc( $wall ) { $d = new DateTime( $wall, new DateTimeZone( 'UTC' ) ); return $d->getTimestamp(); }
function ts_real( $wall, $tz ) { $d = new DateTime( $wall, new DateTimeZone( $tz ) ); return $d->getTimestamp(); }

$stages0 = array( 'location_main_scene' => '0', 'location_mansarda' => '0', 'location_the_beat' => '0', 'location_myasnitskaya' => '0', 'small_hall' => '0' );
$cases = array(
 'A' => array( 'title' => 'ДАНИИЛ КРАМЕР И РОМАН МИРОШНИЧЕНКО: «ДИАЛОГ ВИРТУОЗОВ»', 'stage' => array( 'location_main_scene' ), 'tz' => 'UTC', 'start' => '2026-11-30 20:00', 'end' => '2026-11-30 22:30', 'cost' => '[:ru]от 1500 руб.[:en] 1500 RUR and above', 'tc' => '6abff807772d40451f8ea22a', 'desc' => '<p>Народный артист России&nbsp;Даниил Крамер<br />и «Диалог»</p>', 'img' => 4, 'genres' => array( 'Джаз|Jazz', 'Соул|Soul' ) ),
 'B' => array( 'title' => '«УРАГАННЫЙ ДЖЕМ» ПАВЛА ТИМОФЕЕВА', 'stage' => array( 'location_the_beat' ), 'tz' => 'UTC', 'start' => '2026-10-09 22:00', 'end' => '2026-10-10 00:30', 'cost' => '[:ru] ДЕПОЗИТ 2 000 РУБ.[:en] DEPOSIT 2 000 RUR', 'tc' => '', 'desc' => 'Би-боп, кул-джаз', 'img' => 4 ),
 'C' => array( 'title' => 'TRANE ZEN ART', 'stage' => array( 'location_mansarda' ), 'tz' => 'UTC', 'start' => '2026-10-12 20:00', 'end' => '2026-10-12 22:45', 'cost' => '[:ru]Вход свободный[:en]Free entry', 'tc' => '', 'desc' => 'Описание', 'img' => 4, 'genres' => array( 'Джаз|Jazz', 'Вход свободный|Free' ) ),
 'D' => array( 'title' => 'JAZZ BIRDS 2026: 3-Й ДЕНЬ', 'stage' => array(), 'tz' => 'UTC', 'start' => '2026-10-29 10:00', 'end' => '2026-10-29 16:00', 'cost' => '[:ru]Вход свободный', 'tc' => '', 'desc' => 'Конкурс', 'img' => 4 ),
 'E' => array( 'title' => 'RESONANT HANDS', 'stage' => array( 'location_myasnitskaya' ), 'tz' => 'UTC', 'start' => '2026-11-16 20:00', 'end' => '2026-11-16 22:00', 'cost' => '[:ru]от 1000 руб.', 'tc' => '', 'desc' => 'Текст', 'img' => 4 ),
 'F' => array( 'title' => 'ОЛЬГА СИНЯЕВА', 'stage' => array( 'location_mansarda' ), 'tz' => 'UTC', 'start' => '2026-10-18 14:00', 'end' => '2026-10-18 16:00', 'cost' => '1000 руб.', 'tc' => 'abc', 'desc' => 'Текст', 'img' => 4 ),
 'G' => array( 'title' => 'ЗАКРЫТОЕ СОБЫТИЕ', 'stage' => array( 'location_main_scene' ), 'tz' => 'UTC', 'start' => '2026-11-01 20:00', 'end' => '2026-11-01 22:00', 'cost' => 'от 1000 руб.', 'tc' => 'x', 'desc' => 'Текст', 'img' => 4, 'password' => 'secret' ),
 'H' => array( 'title' => 'НАНИ ЕВА: «СКАЗОЧНЫЙ НОВЫЙ ГОД»', 'stage' => array(), 'tz' => 'UTC', 'start' => '2026-12-31 20:00', 'end' => '2026-12-31 22:30', 'cost' => 'от 3000 руб.', 'tc' => '', 'desc' => 'Текст', 'img' => 4, 'status' => 'draft' ),
 'I' => array( 'title' => 'ПРОШЕДШИЙ КОНЦЕРТ', 'stage' => array( 'location_main_scene' ), 'tz' => 'UTC', 'start' => '2026-10-01 20:00', 'end' => '2026-10-01 22:00', 'cost' => 'от 1000 руб.', 'tc' => 'x', 'desc' => 'Текст', 'img' => 4 ),
 'J' => array( 'title' => 'ПОЯС МОСКВА', 'stage' => array( 'location_main_scene' ), 'tz' => 'Europe/Moscow', 'start' => '2026-11-20 19:30', 'end' => '2026-11-20 21:30', 'cost' => 'от 1200 руб.', 'tc' => 'x', 'desc' => 'Текст', 'img' => 4 ),
 'K' => array( 'title' => 'ПРОВЕРКА ЭКРАНИРОВАНИЯ', 'stage' => array( 'location_main_scene' ), 'tz' => 'UTC', 'start' => '2026-11-21 20:00', 'end' => '2026-11-21 22:00', 'cost' => 'от 900 руб.', 'tc' => 'x', 'desc' => 'Опасно: </script><script>alert(1)</script> &lt;/script&gt;&lt;b&gt; & "кавычки" \'апостроф\'', 'img' => 4 ),
 'L' => array( 'title' => 'ВЕСЬ ДЕНЬ', 'stage' => array( 'location_main_scene' ), 'tz' => 'UTC', 'start' => '2026-12-31 00:00', 'end' => '2027-01-01 00:00', 'cost' => 'Вход свободный', 'tc' => '', 'desc' => 'Текст', 'img' => 4, 'allday' => 1 ),
 'M' => array( 'title' => 'БЕЗ ОКОНЧАНИЯ', 'stage' => array( 'location_the_beat' ), 'tz' => 'UTC', 'start' => '2026-11-22 20:00', 'end' => '2026-11-22 20:00', 'cost' => 'от 1000 руб.', 'tc' => 'x', 'desc' => 'Текст', 'img' => 4, 'instant' => 1 ),
 'N' => array( 'title' => 'ПОВТОРЯЮЩЕЕСЯ', 'stage' => array( 'location_the_beat' ), 'tz' => 'UTC', 'start' => '2026-11-23 20:00', 'end' => '2026-11-23 22:00', 'cost' => 'от 1000 руб.', 'tc' => 'x', 'desc' => 'Текст', 'img' => 4, 'rrule' => 'FREQ=WEEKLY;' ),
 'O' => array( 'title' => 'БЕЗ СТРОКИ КАЛЕНДАРЯ', 'stage' => array( 'location_the_beat' ), 'tz' => 'UTC', 'start' => null, 'end' => null, 'cost' => '', 'tc' => '', 'desc' => 'Текст', 'img' => 4 ),
 'P' => array( 'title' => 'ДВЕ СЦЕНЫ', 'stage' => array( 'location_main_scene', 'location_myasnitskaya' ), 'tz' => 'UTC', 'start' => '2026-11-24 20:00', 'end' => '2026-11-24 22:00', 'cost' => 'от 1000 руб.', 'tc' => 'x', 'desc' => 'Текст', 'img' => 4 ),
 'Q' => array( 'title' => 'ДВЕ ЦЕНЫ', 'stage' => array( 'location_the_beat' ), 'tz' => 'UTC', 'start' => '2026-11-25 20:00', 'end' => '2026-11-25 22:00', 'cost' => '1000/1500 руб.', 'tc' => 'x', 'desc' => 'Текст', 'img' => 4 ),
 'R' => array( 'title' => 'ПУСТОЙ ПОЯС', 'stage' => array( 'location_mansarda' ), 'tz' => '', 'start' => '2026-11-26 20:00', 'end' => '2026-11-26 22:00', 'cost' => 'от 1000 руб.', 'tc' => 'x', 'desc' => 'Текст', 'img' => 4 ),
 'S' => array( 'title' => 'МАЛЕНЬКАЯ КАРТИНКА', 'stage' => array( 'location_mansarda' ), 'tz' => 'UTC', 'start' => '2026-11-27 20:00', 'end' => '2026-11-27 22:00', 'cost' => 'от 1000 руб.', 'tc' => 'x', 'desc' => 'Текст', 'img' => 5 ),
 'T' => array( 'title' => 'ФИГУРНЫЕ МЕТКИ', 'stage' => array( 'location_mansarda' ), 'tz' => 'UTC', 'start' => '2026-11-28 20:00', 'end' => '2026-11-28 22:00', 'cost' => '{:ru}от 2 500 руб.{:}{:en}from 2500{:}', 'tc' => 'x', 'desc' => '{:ru}Русский текст{:}{:en}English{:}', 'img' => 4 ),
 'U' => array( 'title' => 'ЖАННА &amp; ДРУЗЬЯ «ДЖАЗ»', 'stage' => array( 'location_mansarda' ), 'tz' => 'UTC', 'start' => '2026-11-29 20:00', 'end' => '2026-11-29 22:00', 'cost' => 'от 1000 руб.', 'tc' => 'x', 'desc' => '', 'img' => 0 ),
 'V' => array( 'title' => 'КОНЕЦ РАНЬШЕ НАЧАЛА', 'stage' => array( 'location_mansarda' ), 'tz' => 'UTC', 'start' => '2026-11-29 21:00', 'end' => '2026-11-29 19:00', 'cost' => 'от 1000 руб.', 'tc' => 'x', 'desc' => 'Текст', 'img' => 4 ),
 'W' => array( 'title' => 'ЛЕКЦИЯ О ДЖАЗЕ', 'stage' => array( 'location_mansarda' ), 'tz' => 'UTC', 'start' => '2026-11-30 19:00', 'end' => '2026-11-30 21:00', 'cost' => 'от 700 руб.', 'tc' => 'x', 'desc' => 'Текст', 'img' => 4, 'genres' => array( 'Лекция|Lecture', 'Джаз|Jazz' ) ),
 'X' => array( 'title' => 'ОТМЕНЁННЫЙ КОНЦЕРТ', 'stage' => array( 'location_main_scene' ), 'tz' => 'UTC', 'start' => '2026-11-12 20:00', 'end' => '2026-11-12 22:00', 'cost' => 'от 1500 руб.', 'tc' => 'x', 'desc' => 'Текст', 'img' => 4, 'sched' => 'cancelled' ),
 'Y' => array( 'title' => 'ПЕРЕНЕСЁННЫЙ КОНЦЕРТ', 'stage' => array( 'location_the_beat' ), 'tz' => 'UTC', 'start' => '2026-11-13 20:00', 'end' => '2026-11-13 22:00', 'cost' => 'Вход свободный', 'tc' => '', 'desc' => 'Текст', 'img' => 4, 'sched' => 'postponed' ),
 'Z' => array( 'title' => 'ЖАНРЫ СТРОКАМИ', 'stage' => array( 'location_the_beat' ), 'tz' => 'UTC', 'start' => '2026-11-14 20:00', 'end' => '2026-11-14 22:00', 'cost' => 'от 1000 руб.', 'tc' => 'x', 'desc' => 'Текст', 'img' => 4, 'genres_rows' => array( 'Фанк|Funk', 'Соул|Soul' ) ),
 'RS' => array( 'title' => 'ПЕРЕНЕСЁН НА НОВУЮ ДАТУ', 'stage' => array( 'location_main_scene' ), 'tz' => 'UTC', 'start' => '2026-11-17 20:00', 'end' => '2026-11-17 22:00', 'cost' => 'от 2000 руб.', 'tc' => 'x', 'desc' => 'Текст', 'img' => 4, 'sched' => 'rescheduled', 'prev' => '2026-11-10 20:00' ),
 'RT' => array( 'title' => 'ПЕРЕНЕСЁН БЕЗ ПРЕЖНЕЙ ДАТЫ', 'stage' => array( 'location_main_scene' ), 'tz' => 'UTC', 'start' => '2026-11-18 20:00', 'end' => '2026-11-18 22:00', 'cost' => 'от 2000 руб.', 'tc' => 'x', 'desc' => 'Текст', 'img' => 4, 'sched' => 'rescheduled' ),
 'PP' => array( 'title' => 'ОТЛОЖЕН В ПРОШЛОМ', 'stage' => array( 'location_mansarda' ), 'tz' => 'UTC', 'start' => '2026-10-01 20:00', 'end' => '2026-10-01 22:00', 'cost' => 'от 1200 руб.', 'tc' => 'x', 'desc' => 'Текст', 'img' => 4, 'sched' => 'postponed' ),
 'CC' => array( 'title' => 'ОТМЕНЁН В ПРОШЛОМ', 'stage' => array( 'location_the_beat' ), 'tz' => 'UTC', 'start' => '2026-10-02 20:00', 'end' => '2026-10-02 22:00', 'cost' => 'от 1300 руб.', 'tc' => 'x', 'desc' => 'Текст', 'img' => 4, 'sched' => 'cancelled' ),
 'RX' => array( 'title' => 'ПЕРЕНЕСЁН И ПРОШЁЛ', 'stage' => array( 'location_the_beat' ), 'tz' => 'UTC', 'start' => '2026-10-03 20:00', 'end' => '2026-10-03 22:00', 'cost' => 'от 1300 руб.', 'tc' => 'x', 'desc' => 'Текст', 'img' => 4, 'sched' => 'rescheduled', 'prev' => '2026-09-20 20:00' ),
);
$map = array();
foreach ( $cases as $key => $c ) {
	$args = array( 'post_type' => 'ai1ec_event', 'post_title' => $c['title'], 'post_status' => isset( $c['status'] ) ? $c['status'] : 'publish', 'post_content' => '', 'post_name' => 'case-' . strtolower( $key ) );
	if ( isset( $c['password'] ) ) { $args['post_password'] = $c['password']; }
	$id = wp_insert_post( wp_slash( $args ), true );
	if ( is_wp_error( $id ) ) { echo "ERR $key\n"; continue; }
	$meta = $stages0;
	foreach ( $c['stage'] as $s ) { $meta[ $s ] = '1'; }
	$meta['textblock_1_description'] = $c['desc'];
	$meta['ticketscloud_link'] = $c['tc'];
	foreach ( $meta as $k => $v ) { update_post_meta( $id, $k, wp_slash( $v ) ); }
	if ( $c['img'] ) { set_post_thumbnail( $id, $c['img'] ); }
	if ( isset( $c['genres'] ) ) { update_post_meta( $id, 'genre_categories', $c['genres'] ); }
	if ( isset( $c['genres_rows'] ) ) { foreach ( $c['genres_rows'] as $g ) { add_post_meta( $id, 'genre_categories', $g ); } }
	if ( isset( $c['sched'] ) ) { update_post_meta( $id, '_kozlov_event_status', $c['sched'] ); }
	if ( isset( $c['prev'] ) ) { update_post_meta( $id, '_kozlov_event_previous_start', $c['prev'] ); }
	if ( null !== $c['start'] ) {
		$st = 'Europe/Moscow' === $c['tz'] || '' === $c['tz'] ? ts_real( $c['start'], 'Europe/Moscow' ) : ts_wall_utc( $c['start'] );
		$en = 'Europe/Moscow' === $c['tz'] || '' === $c['tz'] ? ts_real( $c['end'], 'Europe/Moscow' ) : ts_wall_utc( $c['end'] );
		$wpdb->insert( $table, array( 'post_id' => $id, 'start' => $st, 'end' => $en, 'timezone_name' => $c['tz'], 'allday' => isset( $c['allday'] ) ? 1 : 0, 'instant_event' => isset( $c['instant'] ) ? 1 : 0, 'recurrence_rules' => isset( $c['rrule'] ) ? $c['rrule'] : '', 'cost' => $c['cost'], 'ticket_url' => '' ) );
	}
	$map[ $key ] = $id;
}
file_put_contents( __DIR__ . '/ids.json', wp_json_encode( $map ) );
echo 'seeded ' . count( $map ) . "\n";
