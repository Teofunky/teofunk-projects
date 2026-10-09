<?php
require_once ABSPATH . 'wp-admin/includes/admin.php';
require_once KOZLOV_EVENT_SCHEMA_DIR . 'includes/class-kozlov-event-schema-admin.php';
wp_set_current_user( 1 ); global $wpdb;
$q0 = $wpdb->num_queries; $t0 = microtime( true );
ob_start(); Kozlov_Event_Schema_Admin::render(); $h = ob_get_clean();
printf( "admin render: %.2fs, queries %d, html %d KB\n", microtime( true ) - $t0, $wpdb->num_queries - $q0, strlen( $h ) / 1024 );
preg_match( '/Проверено событий[^<]*<strong>(\d+)<\/strong>[^<]*<strong>(\d+)/u', $h, $m ); echo 'checked/ready: ' . ( $m[1] ?? '?' ) . '/' . ( $m[2] ?? '?' ) . "\n";
