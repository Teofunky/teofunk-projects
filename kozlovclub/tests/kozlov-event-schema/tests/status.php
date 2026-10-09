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

// other post type untouched
$page = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'p', 'post_status' => 'publish' ) );
$_POST = array( 'kozlov_event_schema_status_nonce' => wp_create_nonce( 'kozlov_event_schema_status' ), 'kozlov_event_schema_status' => 'cancelled' );
Kozlov_Event_Schema_Status::save( $page, get_post( $page ) );
$t( ! metadata_exists( 'post', $page, $key ), 'other post type ignored' );
wp_delete_post( $page, true ); wp_delete_user( $sub );
echo "status tests fails: $fail\n";
