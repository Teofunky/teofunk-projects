<?php
/**
 * kozlov-find-menu-error.php — ищет код окна «Мне есть 18 лет» и сообщения
 * «Ошибка в выполнении запроса», его AJAX-обработчик и свежие записи лога.
 * ТОЛЬКО ЧТЕНИЕ. Положить в корень сайта, открыть под администратором,
 * прислать вывод и УДАЛИТЬ файл.
 */

define( 'DONOTCACHEPAGE', true );
@set_time_limit( 120 );
@ini_set( 'memory_limit', '512M' );

require __DIR__ . '/wp-load.php';

if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
	@status_header( 403 );
	exit( "403: войдите в wp-admin как администратор, потом обновите страницу.\n" );
}
@header( 'Content-Type: text/plain; charset=utf-8' );

function kf_h( $t ) { echo "\n==== {$t} ====\n"; }
function kf_rel( $p ) { return str_replace( ABSPATH, '', $p ); }

function kf_files() {
	static $list = null;
	if ( null !== $list ) { return $list; }
	$list = array();
	$dirs = array_unique( array_filter( array( get_stylesheet_directory(), get_template_directory(), WPMU_PLUGIN_DIR, WP_PLUGIN_DIR ), 'is_dir' ) );
	foreach ( $dirs as $dir ) {
		try {
			$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $f ) {
				$p   = $f->getPathname();
				$ext = strtolower( pathinfo( $p, PATHINFO_EXTENSION ) );
				if ( 'php' !== $ext && 'js' !== $ext ) { continue; }
				if ( $f->getSize() > 3 * 1048576 || false !== strpos( $p, '/node_modules/' ) ) { continue; }
				$list[] = $p;
			}
		} catch ( Exception $e ) {
			echo "Scan error in {$dir}: " . $e->getMessage() . "\n";
		}
	}
	return $list;
}

function kf_context( $path, $line_no, $before, $after, $needle = '' ) {
	$lines = @file( $path );
	if ( ! $lines ) { return array( 0, 0 ); }
	$from = max( 1, $line_no - $before );
	$to   = min( count( $lines ), $line_no + $after );
	echo "\n--- " . kf_rel( $path ) . " (lines {$from}-{$to})\n";
	for ( $i = $from; $i <= $to; $i++ ) {
		$l = rtrim( $lines[ $i - 1 ], "\r\n" );
		if ( strlen( $l ) > 400 ) {
			$pos = ( $i === $line_no && '' !== $needle ) ? strpos( $l, $needle ) : false;
			$l   = ( false !== $pos ) ? '…' . substr( $l, max( 0, $pos - 1500 ), 3000 ) . '…' : substr( $l, 0, 400 ) . ' …';
		}
		echo str_pad( (string) $i, 5, ' ', STR_PAD_LEFT ) . ( $i === $line_no ? ' >' : ' |' ) . ' ' . $l . "\n";
	}
	return array( $from, $to );
}

/* 1. URLs */
kf_h( '1. Addresses' );
echo 'home:        ' . get_option( 'home' ) . "\n";
echo 'siteurl:     ' . get_option( 'siteurl' ) . "\n";
echo 'admin-ajax:  ' . admin_url( 'admin-ajax.php' ) . "\n";
echo 'rest_url:    ' . rest_url() . "\n";
echo 'is_ssl():    ' . ( is_ssl() ? 'yes' : 'no' ) . "\n";
echo 'WP_HTTP_BLOCK_EXTERNAL: ' . ( defined( 'WP_HTTP_BLOCK_EXTERNAL' ) ? var_export( WP_HTTP_BLOCK_EXTERNAL, true ) : '(not set)' ) . "\n";

/* 2. Find the age gate / error message code */
kf_h( '2. Code of the 18+ window and the error message' );
$needles = array( 'Ошибка в выполнении запроса', 'Мне есть 18' );
$regex   = '/(?<![a-z])age[_\-]?(gate|confirm|verif|check)|agegate|ageVerif/i';
$hits    = array();
foreach ( kf_files() as $p ) {
	$src = @file_get_contents( $p );
	if ( false === $src ) { continue; }
	$own = ( 0 === strpos( $p, get_template_directory() ) || 0 === strpos( $p, get_stylesheet_directory() ) || 0 === strpos( $p, WPMU_PLUGIN_DIR ) || false !== strpos( $p, '/plugins/kozlov' ) );
	$any = false;
	foreach ( $needles as $n ) { if ( false !== strpos( $src, $n ) ) { $any = true; break; } }
	if ( ! $any && $own && preg_match( $regex, $src ) ) { $any = true; }
	if ( ! $any ) { continue; }
	foreach ( explode( "\n", $src ) as $i => $ln ) {
		$hit = '';
		foreach ( $needles as $n ) { if ( false !== strpos( $ln, $n ) ) { $hit = $n; break; } }
		if ( '' === $hit && $own && preg_match( $regex, $ln, $mm ) ) { $hit = $mm[0]; }
		if ( '' !== $hit ) { $hits[] = array( $p, $i + 1, $hit ); }
	}
}
echo 'Hits: ' . count( $hits ) . "\n";
foreach ( array_slice( $hits, 0, 60 ) as $h ) { echo '  ' . kf_rel( $h[0] ) . ':' . $h[1] . '  [' . $h[2] . "]\n"; }

$printed = array();
$shown   = 0;
foreach ( $hits as $h ) {
	if ( $shown >= 20 ) { break; }
	list( $p, $ln, $needle ) = $h;
	if ( isset( $printed[ $p ] ) && $ln >= $printed[ $p ][0] && $ln <= $printed[ $p ][1] ) { continue; }
	$printed[ $p ] = kf_context( $p, $ln, 15, 25, $needle );
	$shown++;
}

/* Extract AJAX action names / REST routes near the hits */
$actions = array();
$routes  = array();
$cache   = array();
foreach ( $hits as $h ) {
	list( $p, $ln, $needle ) = $h;
	if ( ! isset( $cache[ $p ] ) ) { $cache[ $p ] = @file( $p ); }
	$lines = $cache[ $p ];
	if ( ! $lines ) { continue; }
	$chunk = '';
	for ( $i = max( 0, $ln - 41 ); $i <= min( count( $lines ) - 1, $ln + 39 ); $i++ ) {
		$l = $lines[ $i ];
		if ( strlen( $l ) > 4000 ) {
			$pos = strpos( $l, $needle );
			$l   = ( false !== $pos ) ? substr( $l, max( 0, $pos - 3000 ), 6000 ) : '';
		}
		$chunk .= $l . "\n";
	}
	if ( preg_match_all( '/action[\'"]?\s*[:=,]\s*[\'"]([A-Za-z0-9_\-]+)[\'"]/', $chunk, $m ) ) { foreach ( $m[1] as $a ) { $actions[ $a ] = true; } }
	if ( preg_match_all( '/[?&]action=([A-Za-z0-9_\-]+)/', $chunk, $m ) ) { foreach ( $m[1] as $a ) { $actions[ $a ] = true; } }
	if ( preg_match_all( '#wp-json/([A-Za-z0-9_\-/]+)#', $chunk, $m ) ) { foreach ( $m[1] as $r ) { $routes[ $r ] = true; } }
}

/* 3. Server-side handlers */
kf_h( '3. Server handlers' );
echo 'AJAX actions found near the code: ' . ( $actions ? implode( ', ', array_keys( $actions ) ) : '(none)' ) . "\n";
echo 'REST routes found near the code:  ' . ( $routes ? implode( ', ', array_keys( $routes ) ) : '(none)' ) . "\n";
$callbacks = array();
foreach ( array_keys( $actions ) as $a ) {
	$found = 0;
	foreach ( kf_files() as $p ) {
		if ( '.php' !== substr( $p, -4 ) ) { continue; }
		$src = @file_get_contents( $p );
		if ( false === $src || false === strpos( $src, 'wp_ajax_' ) || false === strpos( $src, $a ) ) { continue; }
		foreach ( explode( "\n", $src ) as $i => $ln ) {
			if ( ! preg_match( '/wp_ajax_(nopriv_)?' . preg_quote( $a, '/' ) . '(?![A-Za-z0-9_])/', $ln ) ) { continue; }
			kf_context( $p, $i + 1, 3, 3 );
			if ( preg_match_all( '/[\'"]([A-Za-z_][A-Za-z0-9_]*)[\'"]/', $ln, $cm ) ) {
				$cb = end( $cm[1] );
				if ( $cb && 0 !== strpos( $cb, 'wp_ajax_' ) ) { $callbacks[ $cb ] = true; }
			}
			$found++;
		}
	}
	if ( ! $found ) { echo "\nHandler wp_ajax_{$a} not found in theme/plugins.\n"; }
}
foreach ( array_keys( $callbacks ) as $cb ) {
	$re = '/function\s+' . preg_quote( $cb, '/' ) . '\s*\(/';
	foreach ( kf_files() as $p ) {
		if ( '.php' !== substr( $p, -4 ) ) { continue; }
		$src = @file_get_contents( $p );
		if ( false === $src || ! preg_match( $re, $src ) ) { continue; }
		foreach ( explode( "\n", $src ) as $i => $ln ) {
			if ( preg_match( $re, $ln ) ) { kf_context( $p, $i + 1, 2, 80 ); }
		}
	}
}

/* 4. Recent log lines without the known noise */
kf_h( '4. Last debug.log lines (known noise filtered out)' );
$log = WP_CONTENT_DIR . '/debug.log';
if ( defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG ) && ! in_array( strtolower( WP_DEBUG_LOG ), array( '', '1', 'true' ), true ) ) { $log = WP_DEBUG_LOG; }
if ( is_readable( $log ) && is_file( $log ) ) {
	$fh = fopen( $log, 'r' );
	if ( filesize( $log ) > 3 * 1048576 ) { fseek( $fh, -3 * 1048576, SEEK_END ); fgets( $fh ); }
	$noise = array( 'class-wpglobus-yoastseo', 'Undefined array key "sort"', '#8192', '_load_textdomain_just_in_time', 'exclude_from_search' );
	$keep  = array();
	while ( false !== ( $line = fgets( $fh ) ) ) {
		$skip = false;
		foreach ( $noise as $n ) { if ( false !== strpos( $line, $n ) ) { $skip = true; break; } }
		if ( $skip ) { continue; }
		$keep[] = rtrim( str_replace( ABSPATH, '', $line ) );
		if ( count( $keep ) > 60 ) { array_shift( $keep ); }
	}
	fclose( $fh );
	echo ( $keep ? implode( "\n", $keep ) : '(nothing besides known noise)' ) . "\n";
	echo 'Log last modified: ' . gmdate( 'Y-m-d H:i:s', filemtime( $log ) ) . " UTC\n";
} else {
	echo "Log not found: {$log}\n";
}

echo "\nDone. DELETE THIS FILE FROM THE SERVER NOW.\n";
