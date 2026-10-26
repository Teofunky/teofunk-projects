<?php
/**
 * kozlov-fix-dirsize.php — одноразово удаляет служебный кеш размеров папок
 * (_transient_dirsize_cache). WordPress пересоздаёт его сам, если понадобится.
 *
 * 1. Сначала в W3TC переведи Object Cache и Database Cache теста на Redis Database ID = 1.
 * 2. Положи файл в корень сайта (рядом с wp-config.php).
 * 3. Войди в wp-admin администратором, открой https://<сайт>/kozlov-fix-dirsize.php
 * 4. Пришли вывод и УДАЛИ этот файл с сервера.
 */

define( 'DONOTCACHEPAGE', true );
define( 'DONOTCACHEDB', true );
@ini_set( 'memory_limit', '512M' );

require __DIR__ . '/wp-load.php';

if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
	@status_header( 403 );
	exit( "403: войдите в wp-admin как администратор, потом обновите страницу.\n" );
}
@header( 'Content-Type: text/plain; charset=utf-8' );

function kfd_state() {
	global $wpdb;
	$row  = (int) $wpdb->get_var( "SELECT LENGTH(option_value) FROM {$wpdb->options} WHERE option_name = '_transient_dirsize_cache'" );
	$auto = (int) $wpdb->get_var( "SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload IN ('yes','on','auto','auto-on')" );
	return sprintf( 'dirsize_cache in DB: %.2f MB, autoload total: %.2f MB', $row / 1048576, $auto / 1048576 );
}

echo 'Site:   ' . home_url() . "\n";
echo 'Before: ' . kfd_state() . "\n";

$a = delete_option( '_transient_dirsize_cache' ); // строка в wp_options
$b = delete_transient( 'dirsize_cache' );         // копия во внешнем кеше (Redis), если есть
wp_cache_delete( 'alloptions', 'options' );       // сброс закешированного набора автозагрузки

echo 'delete_option: ' . ( $a ? 'deleted' : 'not found' ) . ', delete_transient: ' . ( $b ? 'deleted' : 'not found' ) . "\n";
echo 'After:  ' . kfd_state() . "\n";
echo 'Peak memory: ' . round( memory_get_peak_usage( true ) / 1048576, 1 ) . " MB\n";
echo "DELETE THIS FILE FROM THE SERVER NOW.\n";
