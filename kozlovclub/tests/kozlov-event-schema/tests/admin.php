<?php
require_once ABSPATH . 'wp-admin/includes/admin.php';
require_once KOZLOV_EVENT_SCHEMA_DIR . 'includes/class-kozlov-event-schema-admin.php';
wp_set_current_user( 1 );
set_current_screen( 'settings_page_kozlov-event-schema' );
$ids = json_decode( file_get_contents( __DIR__ . '/ids.json' ), true );
$_GET['kes_preview'] = $ids['A'];
ob_start();
Kozlov_Event_Schema_Admin::render();
$html = ob_get_clean();
file_put_contents( __DIR__ . '/admin.html', $html );
echo 'bytes ' . strlen( $html ) . "\n";
// non-admin must see nothing
wp_set_current_user( 0 );
ob_start(); Kozlov_Event_Schema_Admin::render(); echo 'anon bytes ' . strlen( ob_get_clean() ) . "\n";
// sanitize
var_export( Kozlov_Event_Schema_Admin::sanitize( array( 'enabled' => '1', 'evil' => '<x>' ) ) ); echo "\n";
var_export( Kozlov_Event_Schema_Admin::sanitize( 'garbage' ) ); echo "\n";
