<?php
// CLI integration checks inside a real WordPress 7.1.5 (SQLite). Usage: php wptest.php
$_SERVER['HTTP_HOST'] = '127.0.0.1:8080'; $_SERVER['REQUEST_URI'] = '/';
require '/home/claude/wp/site/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$ok = 0; $bad = 0;
function t( $cond, $msg ) { global $ok, $bad; if ( $cond ) { $ok++; echo "  ok   $msg\n"; } else { $bad++; echo "  FAIL $msg\n"; } }
@unlink( '/home/claude/wp/w3tc.log' );
deactivate_plugins( 'kozlov-slider-stats/kozlov-slider-stats.php' );
delete_option( 'kzsl_settings' );
$r = activate_plugin( 'kozlov-slider-stats/kozlov-slider-stats.php' );
t( ! is_wp_error( $r ), 'plugin activates without errors' );
$s = get_option( 'kzsl_settings' );
t( is_array( $s ) && 1 === $s['enabled'] && '44419291' === $s['counter'] && 1 === $s['skip_editors'] && 1 === $s['next'], 'default settings written on activation' );
t( file_exists( '/home/claude/wp/w3tc.log' ), 'page cache flushed on activation' );
global $wpdb;
t( 'no' === $wpdb->get_var( "SELECT autoload FROM {$wpdb->options} WHERE option_name='kzsl_settings'" ) || 'off' === $wpdb->get_var( "SELECT autoload FROM {$wpdb->options} WHERE option_name='kzsl_settings'" ), 'option is not autoloaded' );
update_option( 'kzsl_settings', array( 'counter' => '12a34' ) );
t( '1234' === kzsl_settings()['counter'] && 1 === kzsl_settings()['enabled'], 'settings: digits only, missing keys fall back to defaults' );
update_option( 'kzsl_settings', 'garbage' );
t( '44419291' === kzsl_settings()['counter'], 'settings: broken option falls back to defaults' );
deactivate_plugins( 'kozlov-slider-stats/kozlov-slider-stats.php' );
t( ! is_plugin_active( 'kozlov-slider-stats/kozlov-slider-stats.php' ), 'plugin deactivates' );
activate_plugin( 'kozlov-slider-stats/kozlov-slider-stats.php' );
update_option( 'kzsl_settings', kzsl_default_settings() );
echo "\n$ok ok, $bad failed\n";
exit( $bad ? 1 : 0 );
