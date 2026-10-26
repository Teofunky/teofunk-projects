<?php
/**
 * kozlov-diag.php — диагностика WordPress. ТОЛЬКО ЧТЕНИЕ, ничего не меняет.
 *
 * 1. Положить в корень сайта (рядом с wp-config.php).
 * 2. Войти в wp-admin администратором, открыть https://<сайт>/kozlov-diag.php
 * 3. Скопировать весь текст страницы и прислать.
 * 4. УДАЛИТЬ этот файл с сервера.
 */

define( 'DONOTCACHEPAGE', true );
define( 'DONOTCACHEDB', true );
define( 'DONOTCACHEOBJECT', true );
define( 'DONOTMINIFY', true );
define( 'DONOTCDN', true );

@ini_set( 'memory_limit', '512M' );
@set_time_limit( 180 );

register_shutdown_function( function () {
	$e = error_get_last();
	if ( $e && in_array( $e['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
		echo "\n\n!!! SCRIPT STOPPED: {$e['message']} in {$e['file']}:{$e['line']}\n";
	}
} );

require __DIR__ . '/wp-load.php';

if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
	@status_header( 403 );
	@header( 'Content-Type: text/plain; charset=utf-8' );
	exit( "403: сначала войдите в wp-admin как администратор, потом обновите эту страницу.\n" );
}

@nocache_headers();
@header( 'Content-Type: text/plain; charset=utf-8' );

function kd_h( $t ) { echo "\n==== {$t} ====\n"; @flush(); }
function kd_mb( $b ) { return number_format( (float) $b / 1048576, 2 ) . ' MB'; }
function kd_cut( $s, $n ) { return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $n, 'UTF-8' ) : substr( $s, 0, $n ); }
function kd_v( $c ) { return defined( $c ) ? var_export( constant( $c ), true ) : '(not set)'; }
function kd_pad( $s, $n ) { return str_pad( (string) $s, $n, ' ', STR_PAD_LEFT ); }

global $wpdb;
$kd_t0 = microtime( true );
echo 'kozlov-diag  ' . gmdate( 'Y-m-d H:i:s' ) . " UTC\n";

/* 1. Environment */
kd_h( '1. Environment' );
$theme = wp_get_theme();
$env   = array(
	'Site'                   => home_url(),
	'WordPress'              => get_bloginfo( 'version' ),
	'PHP'                    => PHP_VERSION,
	'memory_limit'           => ini_get( 'memory_limit' ),
	'WP_MEMORY_LIMIT'        => kd_v( 'WP_MEMORY_LIMIT' ),
	'WP_MAX_MEMORY_LIMIT'    => kd_v( 'WP_MAX_MEMORY_LIMIT' ),
	'WP_DEBUG'               => kd_v( 'WP_DEBUG' ),
	'WP_DEBUG_LOG'           => kd_v( 'WP_DEBUG_LOG' ),
	'WP_DEBUG_DISPLAY'       => kd_v( 'WP_DEBUG_DISPLAY' ),
	'WP_CACHE'               => kd_v( 'WP_CACHE' ),
	'WP_HTTP_BLOCK_EXTERNAL' => kd_v( 'WP_HTTP_BLOCK_EXTERNAL' ),
	'WP_ACCESSIBLE_HOSTS'    => kd_v( 'WP_ACCESSIBLE_HOSTS' ),
	'php error_log'          => ini_get( 'error_log' ) ? ini_get( 'error_log' ) : '-',
	'MySQL'                  => $wpdb->db_version(),
	'Table prefix'           => $wpdb->prefix,
	'Theme'                  => $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ) . ' [' . get_stylesheet() . ']',
	'object-cache.php'       => file_exists( WP_CONTENT_DIR . '/object-cache.php' ) ? 'yes' : 'no',
	'db.php'                 => file_exists( WP_CONTENT_DIR . '/db.php' ) ? 'yes' : 'no',
	'advanced-cache.php'     => file_exists( WP_CONTENT_DIR . '/advanced-cache.php' ) ? 'yes' : 'no',
	'Peak memory (WP boot)'  => kd_mb( memory_get_peak_usage( true ) ),
);
foreach ( $env as $k => $v ) { echo str_pad( $k, 24 ) . ' ' . $v . "\n"; }

/* 2. Plugins */
kd_h( '2. Plugins' );
if ( ! function_exists( 'get_plugins' ) ) { require_once ABSPATH . 'wp-admin/includes/plugin.php'; }
$active = (array) get_option( 'active_plugins', array() );
foreach ( get_plugins() as $file => $p ) {
	echo ( in_array( $file, $active, true ) ? '[ON ] ' : '[off] ' ) . $p['Name'] . ' ' . $p['Version'] . '  (' . $file . ")\n";
}
foreach ( get_mu_plugins() as $file => $p ) {
	echo '[MU ] ' . $p['Name'] . ' ' . $p['Version'] . '  (' . $file . ")\n";
}

/* 3. Options */
kd_h( '3. Largest options' );
$rows = $wpdb->get_results( "SELECT option_name, LENGTH(option_value) AS len, autoload FROM {$wpdb->options} ORDER BY len DESC LIMIT 20" );
foreach ( (array) $rows as $r ) { echo kd_pad( kd_mb( $r->len ), 12 ) . '  ' . str_pad( $r->autoload, 9 ) . ' ' . $r->option_name . "\n"; }
$auto = $wpdb->get_var( "SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload IN ('yes','on','auto','auto-on')" );
echo 'Autoload total: ' . kd_mb( $auto ) . "\n";
$tr = $wpdb->get_row( "SELECT COUNT(*) AS c, SUM(LENGTH(option_value)) AS s FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%' OR option_name LIKE '\\_site\\_transient\\_%'" );
if ( $tr ) { echo 'Transients: ' . (int) $tr->c . ' rows, ' . kd_mb( $tr->s ) . "\n"; }

/* 4. Cron */
kd_h( '4. Cron' );
$cron_len = (int) $wpdb->get_var( $wpdb->prepare( "SELECT LENGTH(option_value) FROM {$wpdb->options} WHERE option_name = %s", 'cron' ) );
echo 'cron option: ' . kd_mb( $cron_len ) . "\n";
if ( $cron_len > 0 && $cron_len < 8 * 1048576 && function_exists( '_get_cron_array' ) ) {
	$hooks = array();
	$total = 0;
	foreach ( (array) _get_cron_array() as $ts => $events ) {
		if ( ! is_array( $events ) ) { continue; }
		foreach ( $events as $hook => $inst ) {
			$n              = is_array( $inst ) ? count( $inst ) : 0;
			$hooks[ $hook ] = ( isset( $hooks[ $hook ] ) ? $hooks[ $hook ] : 0 ) + $n;
			$total         += $n;
		}
	}
	arsort( $hooks );
	echo "Events: {$total}, distinct hooks: " . count( $hooks ) . "\n";
	foreach ( array_slice( $hooks, 0, 15, true ) as $h => $n ) { echo kd_pad( $n, 8 ) . '  ' . $h . "\n"; }
} else {
	echo "Not parsed (empty or larger than 8 MB).\n";
}

/* 5. Meta */
kd_h( '5. Largest postmeta / usermeta' );
foreach ( (array) $wpdb->get_results( "SELECT post_id, meta_key, LENGTH(meta_value) AS len FROM {$wpdb->postmeta} ORDER BY len DESC LIMIT 10" ) as $r ) {
	echo kd_pad( kd_mb( $r->len ), 12 ) . '  post ' . $r->post_id . '  ' . $r->meta_key . "\n";
}
foreach ( (array) $wpdb->get_results( "SELECT user_id, meta_key, LENGTH(meta_value) AS len FROM {$wpdb->usermeta} ORDER BY len DESC LIMIT 5" ) as $r ) {
	echo kd_pad( kd_mb( $r->len ), 12 ) . '  user ' . $r->user_id . '  ' . $r->meta_key . "\n";
}

/* 6. Tables */
kd_h( '6. Largest tables' );
$rows = $wpdb->get_results( $wpdb->prepare( "SELECT table_name AS t, (data_length + index_length) AS sz, table_rows AS r FROM information_schema.TABLES WHERE table_schema = %s ORDER BY sz DESC LIMIT 15", DB_NAME ) );
foreach ( (array) $rows as $r ) { echo kd_pad( kd_mb( $r->sz ), 12 ) . '  ' . kd_pad( $r->r, 10 ) . ' rows  ' . $r->t . "\n"; }

/* 7. W3 Total Cache */
kd_h( '7. W3 Total Cache (cache engines, Redis)' );
$cfg_file = WP_CONTENT_DIR . '/w3tc-config/master.php';
if ( is_readable( $cfg_file ) ) {
	$raw = (string) file_get_contents( $cfg_file );
	$pos = strpos( $raw, '{' );
	$cfg = json_decode( false === $pos ? $raw : substr( $raw, $pos ), true );
	if ( is_array( $cfg ) ) {
		foreach ( $cfg as $k => $v ) {
			if ( preg_match( '/^(objectcache|dbcache|pgcache)\.(enabled|engine)$/', $k ) || false !== stripos( $k, 'redis' ) ) {
				if ( false !== stripos( $k, 'password' ) ) { $v = $v ? '***' : ''; }
				echo str_pad( $k, 40 ) . ' ' . ( is_scalar( $v ) ? var_export( $v, true ) : wp_json_encode( $v ) ) . "\n";
			}
		}
	} else {
		echo "Could not parse {$cfg_file}\n";
	}
} else {
	echo "Not found: {$cfg_file}\n";
}

/* 8. Logs */
kd_h( '8. Error logs' );
$logs = array( WP_CONTENT_DIR . '/debug.log' );
if ( defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG ) && ! in_array( strtolower( WP_DEBUG_LOG ), array( '', '1', 'true' ), true ) ) { $logs[] = WP_DEBUG_LOG; }
if ( ini_get( 'error_log' ) ) { $logs[] = ini_get( 'error_log' ); }
foreach ( array_unique( $logs ) as $log ) {
	if ( ! is_readable( $log ) || ! is_file( $log ) ) { echo "{$log}: not found\n"; continue; }
	$size = filesize( $log );
	echo "{$log}: " . kd_mb( $size ) . ', modified ' . gmdate( 'Y-m-d H:i', filemtime( $log ) ) . " UTC\n";
	$fh = fopen( $log, 'r' );
	if ( ! $fh ) { continue; }
	if ( $size > 300 * 1048576 ) { fseek( $fh, -50 * 1048576, SEEK_END ); fgets( $fh ); echo "(file is huge: only the last 50 MB analysed)\n"; }
	$sev    = array();
	$msgs   = array();
	$fatals = array();
	$first  = '';
	$last   = '';
	$lines  = 0;
	while ( false !== ( $line = fgets( $fh ) ) ) {
		if ( ! preg_match( '/^\[([^\]]+)\] (.*)$/s', rtrim( $line ), $m ) ) { continue; }
		$lines++;
		if ( '' === $first ) { $first = $m[1]; }
		$last = $m[1];
		$msg  = str_replace( ABSPATH, '', $m[2] );
		if ( preg_match( '/^(PHP [A-Za-z ]+?):/', $msg, $s ) ) { $k = $s[1]; }
		elseif ( preg_match( '/^([^:]{1,60}):/', $msg, $s ) ) { $k = $s[1]; }
		else { $k = 'other'; }
		$sev[ $k ] = ( isset( $sev[ $k ] ) ? $sev[ $k ] : 0 ) + 1;
		$key       = kd_cut( $msg, 250 );
		if ( isset( $msgs[ $key ] ) ) { $msgs[ $key ]++; } elseif ( count( $msgs ) < 5000 ) { $msgs[ $key ] = 1; }
		if ( false !== strpos( $msg, 'Fatal error' ) ) {
			$fatals[] = '[' . $m[1] . '] ' . kd_cut( $msg, 400 );
			if ( count( $fatals ) > 15 ) { array_shift( $fatals ); }
		}
	}
	fclose( $fh );
	echo "Entries: {$lines}, from {$first} to {$last}\n";
	arsort( $sev );
	foreach ( $sev as $k => $n ) { echo kd_pad( $n, 8 ) . "  {$k}\n"; }
	echo "-- last fatal errors --\n" . ( $fatals ? implode( "\n", $fatals ) : '(none)' ) . "\n";
	echo "-- top 25 messages --\n";
	arsort( $msgs );
	foreach ( array_slice( $msgs, 0, 25, true ) as $mm => $n ) { echo kd_pad( $n, 8 ) . '  ' . $mm . "\n"; }
}

/* 9. Is debug.log public? */
kd_h( '9. Is wp-content/debug.log reachable from the web?' );
if ( file_exists( WP_CONTENT_DIR . '/debug.log' ) ) {
	$r = wp_remote_head( content_url( 'debug.log' ), array( 'timeout' => 8, 'sslverify' => false, 'redirection' => 0 ) );
	if ( is_wp_error( $r ) ) {
		echo 'Check failed: ' . $r->get_error_message() . "\n";
	} else {
		$c = (int) wp_remote_retrieve_response_code( $r );
		echo "HTTP {$c}" . ( 200 === $c ? "  <-- PUBLIC, close access!\n" : "  (not public)\n" );
	}
} else {
	echo "No debug.log in wp-content.\n";
}

/* 10. Code that depends on WPGlobus */
kd_h( '10. Code outside WPGlobus that references it' );
$dirs  = array_unique( array_filter( array( get_stylesheet_directory(), get_template_directory(), WPMU_PLUGIN_DIR, WP_PLUGIN_DIR ), 'is_dir' ) );
$hits  = 0;
$files = array();
foreach ( $dirs as $dir ) {
	try {
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			$path = $f->getPathname();
			if ( '.php' !== substr( $path, -4 ) || false !== strpos( $path, '/plugins/wpglobus/' ) ) { continue; }
			$src = @file_get_contents( $path );
			if ( false === $src || false === stripos( $src, 'wpglobus' ) ) { continue; }
			$files[ $path ] = true;
			foreach ( explode( "\n", $src ) as $i => $ln ) {
				if ( false === stripos( $ln, 'wpglobus' ) ) { continue; }
				$hits++;
				if ( $hits <= 150 ) { echo str_replace( ABSPATH, '', $path ) . ':' . ( $i + 1 ) . '  ' . kd_cut( trim( $ln ), 160 ) . "\n"; }
			}
		}
	} catch ( Exception $e ) {
		echo "Scan error in {$dir}: " . $e->getMessage() . "\n";
	}
}
echo "Total: {$hits} lines in " . count( $files ) . " files\n";

echo "\nDone in " . round( microtime( true ) - $kd_t0, 1 ) . ' s. Peak memory ' . kd_mb( memory_get_peak_usage( true ) ) . ".\n";
echo "DELETE THIS FILE FROM THE SERVER NOW.\n";
