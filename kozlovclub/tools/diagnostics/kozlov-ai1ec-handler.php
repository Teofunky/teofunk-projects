<?php
/**
 * kozlov-ai1ec-handler.php — показывает, как All-in-One Event Calendar перехватывает
 * ошибки PHP и пишет их в лог: где ставится обработчик, сам обработчик, константы-выключатели.
 * ТОЛЬКО ЧТЕНИЕ. Положить в корень ТЕСТОВОГО сайта, открыть под администратором,
 * прислать вывод и УДАЛИТЬ файл.
 */

define( 'DONOTCACHEPAGE', true );
@set_time_limit( 120 );

require __DIR__ . '/wp-load.php';

if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
	@status_header( 403 );
	exit( "403: войдите в wp-admin как администратор, потом обновите страницу.\n" );
}
@header( 'Content-Type: text/plain; charset=utf-8' );

function kh_h( $t ) { echo "\n==== {$t} ====\n"; }
function kh_rel( $p ) { return str_replace( ABSPATH, '', $p ); }

function kh_context( $path, $line_no, $before, $after ) {
	$lines = @file( $path );
	if ( ! $lines ) { return array( 0, 0 ); }
	$from = max( 1, $line_no - $before );
	$to   = min( count( $lines ), $line_no + $after );
	echo "\n--- " . kh_rel( $path ) . " (lines {$from}-{$to})\n";
	for ( $i = $from; $i <= $to; $i++ ) {
		$l = rtrim( $lines[ $i - 1 ], "\r\n" );
		if ( strlen( $l ) > 300 ) { $l = substr( $l, 0, 300 ) . ' …'; }
		echo str_pad( (string) $i, 5, ' ', STR_PAD_LEFT ) . ( $i === $line_no ? ' >' : ' |' ) . ' ' . $l . "\n";
	}
	return array( $from, $to );
}

$base = WP_PLUGIN_DIR . '/all-in-one-event-calendar';
if ( ! is_dir( $base ) ) { exit( "Plugin folder not found: {$base}\n" ); }

/* 1. Runtime constants */
kh_h( '1. Constants right now' );
foreach ( array( 'AI1EC_VERSION', 'AI1EC_DEBUG', 'AI1EC_PLUGIN_NAME', 'AI1EC_DISABLE_FILE_CACHE', 'AI1EC_LOG', 'AI1EC_LOG_ERRORS' ) as $c ) {
	echo str_pad( $c, 26 ) . ' ' . ( defined( $c ) ? var_export( constant( $c ), true ) : '(not defined)' ) . "\n";
}

/* 2. Collect plugin PHP files (skip bundled third-party libraries) */
$files = array();
$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $f ) {
	$p = $f->getPathname();
	if ( '.php' !== substr( $p, -4 ) ) { continue; }
	if ( false !== strpos( $p, '/vendor/' ) || false !== strpos( $p, '/iCal/' ) ) { continue; }
	$files[] = $p;
}
echo "\nScanned " . count( $files ) . " plugin PHP files (vendor/ and iCal/ skipped).\n";

$printed = array();
function kh_show_once( $p, $ln, $before, $after, &$printed ) {
	if ( isset( $printed[ $p ] ) ) {
		foreach ( $printed[ $p ] as $r ) { if ( $ln >= $r[0] && $ln <= $r[1] ) { return; } }
	}
	$printed[ $p ][] = kh_context( $p, $ln, $before, $after );
}

$searches = array(
	array( '3. Where set_error_handler / set_exception_handler is called', '/set_(error|exception)_handler\s*\(/', 10, 14 ),
	array( '4. The error handler itself (function handle_error)', '/function\s+handle_error\s*\(/', 2, 80 ),
	array( '5. Where the "All-in-One Event Calendar:" log line is built', '/All-in-One Event Calendar:/', 12, 12 ),
	array( '6. Debug / logging constants defined or checked', '/AI1EC_DEBUG|AI1EC_LOG|WP_DEBUG/', 2, 3 ),
);

foreach ( $searches as $s ) {
	list( $title, $re, $before, $after ) = $s;
	kh_h( $title );
	$count = 0;
	foreach ( $files as $p ) {
		$src = @file_get_contents( $p );
		if ( false === $src || ! preg_match( $re, $src ) ) { continue; }
		foreach ( explode( "\n", $src ) as $i => $ln ) {
			if ( ! preg_match( $re, $ln ) ) { continue; }
			$count++;
			if ( $count > 15 ) { break 2; }
			kh_show_once( $p, $i + 1, $before, $after, $printed );
		}
	}
	if ( 0 === $count ) { echo "(nothing found)\n"; }
	if ( $count > 15 ) { echo "\n... more matches not shown\n"; }
}

echo "\nDone. DELETE THIS FILE FROM THE SERVER NOW.\n";
