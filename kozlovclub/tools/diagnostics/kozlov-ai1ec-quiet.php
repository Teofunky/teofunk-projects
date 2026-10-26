<?php
/**
 * kozlov-ai1ec-quiet.php — одноразовая правка All-in-One Event Calendar 2.6.8:
 * его обработчик ошибок начинает уважать error_reporting() сайта.
 * Основной сайт (только фатальные ошибки): шум календаря перестаёт писаться в лог.
 * Тестовый сайт (WP_DEBUG, все ошибки): продолжает писаться, как раньше.
 *
 * Открыть под администратором:
 *   /kozlov-ai1ec-quiet.php            — применить правку (делает резервную копию)
 *   /kozlov-ai1ec-quiet.php?revert=1   — вернуть оригинал из резервной копии
 * После этого УДАЛИТЬ файл с сервера.
 */

define( 'DONOTCACHEPAGE', true );

require __DIR__ . '/wp-load.php';

if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
	@status_header( 403 );
	exit( "403: войдите в wp-admin как администратор, потом обновите страницу.\n" );
}
@header( 'Content-Type: text/plain; charset=utf-8' );

$target = WP_PLUGIN_DIR . '/all-in-one-event-calendar/lib/exception/handler.php';
$backup = $target . '.kozlov-backup';
$needle = 'return error_log( $message, 0 );';
$marker = '// kozlov: respect error_reporting';
$insert = 'if ( ! ( error_reporting() & $errno ) ) { return true; } ' . $marker . "\n            " . $needle;

function kq_done( $msg ) {
	echo $msg . "\n\nDELETE THIS FILE FROM THE SERVER NOW.\n";
	exit;
}

function kq_reset_opcache( $file ) {
	if ( function_exists( 'opcache_invalidate' ) ) {
		@opcache_invalidate( $file, true );
	}
}

echo 'Site:            ' . home_url() . "\n";
echo 'File:            ' . $target . "\n";
echo 'AI1EC version:   ' . ( defined( 'AI1EC_VERSION' ) ? AI1EC_VERSION : '?' ) . "\n";
echo 'error_reporting: ' . error_reporting() . "\n\n";

if ( ! is_file( $target ) || ! is_readable( $target ) ) {
	kq_done( 'ERROR: file not found or not readable. Nothing changed.' );
}
$src = file_get_contents( $target );

if ( isset( $_GET['revert'] ) ) {
	if ( ! is_file( $backup ) ) {
		kq_done( 'Nothing to revert: backup file not found.' );
	}
	if ( false === @copy( $backup, $target ) ) {
		kq_done( 'ERROR: could not restore the backup.' );
	}
	kq_reset_opcache( $target );
	kq_done( 'REVERTED: original handler.php restored from backup.' );
}

if ( false !== strpos( $src, $marker ) ) {
	kq_done( 'ALREADY PATCHED: nothing to do.' );
}

$count = substr_count( $src, $needle );
if ( 1 !== $count ) {
	kq_done( "ERROR: expected exactly 1 occurrence of the target line, found {$count}. Nothing changed." );
}

if ( ! is_file( $backup ) && false === @copy( $target, $backup ) ) {
	kq_done( 'ERROR: could not create the backup. Nothing changed.' );
}

$new = str_replace( $needle, $insert, $src );
if ( false === @file_put_contents( $target, $new, LOCK_EX ) ) {
	kq_done( 'ERROR: could not write the file. Nothing changed.' );
}
kq_reset_opcache( $target );

$check = file_get_contents( $target );
if ( false === strpos( $check, $marker ) || 1 !== substr_count( $check, $needle ) ) {
	@copy( $backup, $target );
	kq_reset_opcache( $target );
	kq_done( 'ERROR: verification failed, original restored from backup.' );
}

$lines = explode( "\n", $check );
foreach ( $lines as $i => $l ) {
	if ( false === strpos( $l, $marker ) ) {
		continue;
	}
	for ( $j = max( 0, $i - 10 ); $j <= min( count( $lines ) - 1, $i + 2 ); $j++ ) {
		echo str_pad( (string) ( $j + 1 ), 5, ' ', STR_PAD_LEFT ) . ( $j === $i ? ' >' : ' |' ) . ' ' . rtrim( $lines[ $j ] ) . "\n";
	}
	break;
}

kq_done( "\nPATCHED. Backup: {$backup}" );
