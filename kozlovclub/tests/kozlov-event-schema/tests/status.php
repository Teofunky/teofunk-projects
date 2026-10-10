<?php
require_once ABSPATH . 'wp-admin/includes/admin.php';
require_once KOZLOV_EVENT_SCHEMA_DIR . 'includes/class-kozlov-event-schema-status.php';
$ids  = json_decode( file_get_contents( __DIR__ . '/ids.json' ), true );
$id   = $ids['F'];
$post = get_post( $id );
$key  = '_kozlov_event_status';
$fail = 0;
$t = function ( $cond, $msg ) use ( &$fail ) { if ( ! $cond ) { $fail++; echo "FAIL: $msg\n"; } };
$sub = wp_insert_user( array( 'user_login' => 'sub' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );

wp_set_current_user( 1 );
delete_post_meta( $id, $key );
ob_start(); Kozlov_Event_Schema_Status::render( $post ); $html = ob_get_clean();
$t( false !== strpos( $html, 'name="kozlov_event_schema_status"' ) && 4 === substr_count( $html, '<option' ) && false !== strpos( $html, 'kozlov_event_schema_status_nonce' ), 'render' );
$t( false !== strpos( $html, 'name="kozlov_event_schema_performers"' ) && false !== strpos( $html, 'В тексте анонса состав не найден' ), 'render performers box, nothing found for F' );
$t( false !== strpos( $html, 'type="datetime-local"' ) && false !== strpos( $html, 'value="2026-10-18T14:00"' ), 'render prefill previous from calendar' );

$_POST = array( 'kozlov_event_schema_status_nonce' => wp_create_nonce( 'kozlov_event_schema_status' ), 'kozlov_event_schema_status' => 'cancelled' );
Kozlov_Event_Schema_Status::save( $id, $post );
$t( 'cancelled' === get_post_meta( $id, $key, true ), 'valid save cancelled' );

$_POST = array( 'kozlov_event_schema_status_nonce' => 'bad', 'kozlov_event_schema_status' => 'postponed' );
Kozlov_Event_Schema_Status::save( $id, $post );
$t( 'cancelled' === get_post_meta( $id, $key, true ), 'bad nonce ignored' );

$_POST = array( 'kozlov_event_schema_status_nonce' => wp_create_nonce( 'kozlov_event_schema_status' ), 'kozlov_event_schema_status' => 'evil<script>' );
Kozlov_Event_Schema_Status::save( $id, $post );
$t( 'cancelled' === get_post_meta( $id, $key, true ), 'unknown value ignored' );

wp_set_current_user( $sub );
$_POST = array( 'kozlov_event_schema_status_nonce' => wp_create_nonce( 'kozlov_event_schema_status' ), 'kozlov_event_schema_status' => 'postponed' );
Kozlov_Event_Schema_Status::save( $id, $post );
$t( 'cancelled' === get_post_meta( $id, $key, true ), 'no capability ignored' );

wp_set_current_user( 1 );
$_POST = array( 'kozlov_event_schema_status_nonce' => wp_create_nonce( 'kozlov_event_schema_status' ), 'kozlov_event_schema_status' => '' );
Kozlov_Event_Schema_Status::save( $id, $post );
$t( '' === get_post_meta( $id, $key, true ) && ! metadata_exists( 'post', $id, $key ), 'reset deletes meta' );

$_POST = array();
Kozlov_Event_Schema_Status::save( $id, $post );
$t( ! metadata_exists( 'post', $id, $key ), 'no POST no write' );

$pk = '_kozlov_event_previous_start';
$_POST = array( 'kozlov_event_schema_status_nonce' => wp_create_nonce( 'kozlov_event_schema_status' ), 'kozlov_event_schema_status' => 'rescheduled', 'kozlov_event_schema_previous' => '2026-10-11T19:30' );
Kozlov_Event_Schema_Status::save( $id, $post );
$t( 'rescheduled' === get_post_meta( $id, $key, true ) && '2026-10-11 19:30' === get_post_meta( $id, $pk, true ), 'rescheduled + previous saved' );
ob_start(); Kozlov_Event_Schema_Status::render( get_post( $id ) ); $h2 = ob_get_clean();
$t( false !== strpos( $h2, 'value="2026-10-11T19:30"' ), 'render shows saved previous' );

$_POST['kozlov_event_schema_previous'] = '2026-13-45T25:00';
Kozlov_Event_Schema_Status::save( $id, $post );
$t( 'rescheduled' === get_post_meta( $id, $key, true ) && ! metadata_exists( 'post', $id, $pk ), 'invalid previous dropped' );

$_POST = array( 'kozlov_event_schema_status_nonce' => wp_create_nonce( 'kozlov_event_schema_status' ), 'kozlov_event_schema_status' => 'cancelled', 'kozlov_event_schema_previous' => '2026-10-11T19:30' );
Kozlov_Event_Schema_Status::save( $id, $post );
$t( 'cancelled' === get_post_meta( $id, $key, true ) && ! metadata_exists( 'post', $id, $pk ), 'previous only for rescheduled' );

$_POST = array( 'kozlov_event_schema_status_nonce' => wp_create_nonce( 'kozlov_event_schema_status' ), 'kozlov_event_schema_status' => '', 'kozlov_event_schema_previous' => '2026-10-11T19:30' );
Kozlov_Event_Schema_Status::save( $id, $post );
$t( ! metadata_exists( 'post', $id, $key ) && ! metadata_exists( 'post', $id, $pk ), 'back to scheduled clears both' );


// ---- Исполнители ----
$pk2 = '_kozlov_event_performers';
$lp  = $ids['LP'];
$lpp = get_post( $lp );
delete_post_meta( $lp, $pk2 );
ob_start(); Kozlov_Event_Schema_Status::render( $lpp ); $h3 = ob_get_clean();
$t( false !== strpos( $h3, 'Найдено в тексте: Дмитрий Илугдин, Михаил Дмитриев, Пётр Ившин.' ), 'render shows auto-detected lineup' );

$nonce = wp_create_nonce( 'kozlov_event_schema_status' );
$_POST = array( 'kozlov_event_schema_status_nonce' => $nonce, 'kozlov_event_schema_status' => '', 'kozlov_event_schema_performers' => "  Пётр  Ившин (ударные) \r\n\r\n<b>Трио</b> Илугдина\n" );
Kozlov_Event_Schema_Status::save( $lp, $lpp );
$t( "Пётр Ившин (ударные)\nТрио Илугдина" === get_post_meta( $lp, $pk2, true ), 'manual performers saved clean: ' . json_encode( get_post_meta( $lp, $pk2, true ), JSON_UNESCAPED_UNICODE ) );
$perf = Kozlov_Event_Schema_Builder::performers( $lp );
$t( 'manual' === $perf['source'] && 2 === count( $perf['list'] ) && 'Person' === $perf['list'][0]['@type'] && 'Пётр Ившин' === $perf['list'][0]['name'] && 'PerformingGroup' === $perf['list'][1]['@type'], 'manual performers used, roles stripped, group typed' );
ob_start(); Kozlov_Event_Schema_Status::render( get_post( $lp ) ); $h4 = ob_get_clean();
$t( false !== strpos( $h4, 'Трио Илугдина</textarea>' ) && false === strpos( $h4, 'Найдено в тексте' ), 'render shows saved manual value' );

$_POST = array( 'kozlov_event_schema_status_nonce' => $nonce, 'kozlov_event_schema_status' => '' );
Kozlov_Event_Schema_Status::save( $lp, $lpp );
$t( metadata_exists( 'post', $lp, $pk2 ), 'field absent in POST: manual value kept' );

$_POST = array( 'kozlov_event_schema_status_nonce' => $nonce, 'kozlov_event_schema_status' => '', 'kozlov_event_schema_performers' => '-' );
Kozlov_Event_Schema_Status::save( $lp, $lpp );
$perf = Kozlov_Event_Schema_Builder::performers( $lp );
$t( '-' === get_post_meta( $lp, $pk2, true ) && 'off' === $perf['source'] && empty( $perf['list'] ), 'dash disables performers' );

$_POST = array( 'kozlov_event_schema_status_nonce' => $nonce, 'kozlov_event_schema_status' => '', 'kozlov_event_schema_performers' => str_repeat( 'Очень Длинное Имя ', 400 ) );
Kozlov_Event_Schema_Status::save( $lp, $lpp );
$t( mb_strlen( get_post_meta( $lp, $pk2, true ) ) <= 2000, 'length capped' );

$_POST = array( 'kozlov_event_schema_status_nonce' => $nonce, 'kozlov_event_schema_status' => '', 'kozlov_event_schema_performers' => wp_slash( 'Back\\slash Name' ) ); // как в реальном запросе: WordPress добавляет слэши в $_POST
Kozlov_Event_Schema_Status::save( $lp, $lpp );
$t( 'Back\\slash Name' === get_post_meta( $lp, $pk2, true ), 'backslash survives: ' . get_post_meta( $lp, $pk2, true ) );

wp_set_current_user( $sub );
$_POST = array( 'kozlov_event_schema_status_nonce' => wp_create_nonce( 'kozlov_event_schema_status' ), 'kozlov_event_schema_status' => '', 'kozlov_event_schema_performers' => 'Хакер' );
Kozlov_Event_Schema_Status::save( $lp, $lpp );
$t( 'Back\\slash Name' === get_post_meta( $lp, $pk2, true ), 'no capability: performers untouched' );
wp_set_current_user( 1 );

$_POST = array( 'kozlov_event_schema_status_nonce' => 'bad', 'kozlov_event_schema_status' => '', 'kozlov_event_schema_performers' => 'Хакер' );
Kozlov_Event_Schema_Status::save( $lp, $lpp );
$t( 'Back\\slash Name' === get_post_meta( $lp, $pk2, true ), 'bad nonce: performers untouched' );

$_POST = array( 'kozlov_event_schema_status_nonce' => $nonce, 'kozlov_event_schema_status' => '', 'kozlov_event_schema_performers' => "  \n \n" );
Kozlov_Event_Schema_Status::save( $lp, $lpp );
$perf = Kozlov_Event_Schema_Builder::performers( $lp );
$t( ! metadata_exists( 'post', $lp, $pk2 ) && 'text' === $perf['source'] && 3 === count( $perf['list'] ), 'empty clears and falls back to text' );

// valid_from: дата публикации после начала -> не выводится; черновик без даты -> не выводится.
$fake = new WP_Post( (object) array( 'ID' => 0, 'post_date_gmt' => '2026-11-01 10:00:00' ) );
$t( null === Kozlov_Event_Schema_Builder::valid_from( $fake, strtotime( '2026-10-31 20:00:00 UTC' ) ), 'validFrom absent when published after start' );
$t( '2026-11-01T13:00:00+03:00' === Kozlov_Event_Schema_Builder::valid_from( $fake, strtotime( '2026-11-30 20:00:00 UTC' ) ), 'validFrom in Moscow time' );
$fake2 = new WP_Post( (object) array( 'ID' => 0, 'post_date_gmt' => '0000-00-00 00:00:00' ) );
$t( null === Kozlov_Event_Schema_Builder::valid_from( $fake2, time() + 86400 ), 'validFrom absent for zero date' );

// трим кавычек не портит кириллицу на «л»
$t( 'Павел Тимофеев' === Kozlov_Event_Schema_Builder::trailing_name( '«Павел Тимофеев»' ) && 'Михаил' === Kozlov_Event_Schema_Builder::trailing_name( 'и Михаил' ), 'trailing_name multibyte' );
$t( '' === Kozlov_Event_Schema_Builder::trailing_name( 'Один Два Три Четыре Пять' ) && '' === Kozlov_Event_Schema_Builder::trailing_name( 'барабанщик' ), 'trailing_name limits' );
$t( array() === Kozlov_Event_Schema_Builder::parse_performers( 'Внимание! Состав выступающих может быть изменён (по решению артиста).' ), 'no colon, no lineup' );
$t( array() === Kozlov_Event_Schema_Builder::parse_performers( 'Играл с: <script>x</script>' ), 'no pairs, no lineup' );
$t( array( 'Анна Королева', 'Юрий Погиба', 'Вартан Бабаян' ) === Kozlov_Event_Schema_Builder::parse_performers( '<p>В концерте примут участие: Анна Королева (саксофон), Юрий Погиба (рояль, клавишные), Эдуард Петрухин или Аркадий Баклагин (барабаны), Вартан Бабаян(перкуссия).</p>' ), 'or-variants skipped, no-space paren' );
$t( array( 'Ivan Ivanov' ) === Kozlov_Event_Schema_Builder::parse_performers( '[:ru]На сцену выйдет: Ivan Ivanov (piano).[:en]On stage: John Doe (bass).' ), 'WPGlobus ru part only' );

// other post type untouched
$page = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'p', 'post_status' => 'publish' ) );
$_POST = array( 'kozlov_event_schema_status_nonce' => wp_create_nonce( 'kozlov_event_schema_status' ), 'kozlov_event_schema_status' => 'cancelled' );
Kozlov_Event_Schema_Status::save( $page, get_post( $page ) );
$t( ! metadata_exists( 'post', $page, $key ), 'other post type ignored' );
wp_delete_post( $page, true ); wp_delete_user( $sub );
echo "status tests fails: $fail\n";
