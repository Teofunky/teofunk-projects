<?php
// Заглушки WordPress в объёме, нужном плагину, + сценарии.
define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['hooks'] = array();
$GLOBALS['opts']  = array();
$GLOBALS['tr']    = array();
$GLOBALS['admin'] = false;

function add_action( $h, $cb, $p = 10, $n = 1 ) { $GLOBALS['hooks'][ $h ][] = array( $cb, $n ); }
function add_filter( $h, $cb, $p = 10, $n = 1 ) { $GLOBALS['hooks'][ $h ][] = array( $cb, $n ); }
function apply_filters( $h, $v, ...$a ) {
	foreach ( $GLOBALS['hooks'][ $h ] ?? array() as $x ) { $v = call_user_func_array( $x[0], array_slice( array_merge( array( $v ), $a ), 0, $x[1] ) ); }
	return $v;
}
function do_action( $h, ...$a ) { foreach ( $GLOBALS['hooks'][ $h ] ?? array() as $x ) { call_user_func_array( $x[0], array_slice( $a, 0, $x[1] ) ); } }
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['opts'][ $k ] ); }
// как в WP: фильтры pre_transient_ и pre_set_transient_
function get_transient( $k ) {
	$pre = apply_filters( "pre_transient_{$k}", false, $k );
	if ( false !== $pre ) { return $pre; }
	$t = $GLOBALS['tr'][ $k ] ?? null;
	if ( ! $t || $t[1] < time() ) { return false; }
	return $t[0];
}
function set_transient( $k, $v, $e = 0 ) { $v = apply_filters( "pre_set_transient_{$k}", $v, $e, $k ); $GLOBALS['tr'][ $k ] = array( $v, time() + ( $e ?: 999999 ) ); return true; }
function delete_transient( $k ) { unset( $GLOBALS['tr'][ $k ] ); }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function is_admin() { return $GLOBALS['admin']; }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function plugin_basename( $f ) { return 'kozlov-streamvi-guard/kozlov-streamvi-guard.php'; }
function register_uninstall_hook( $f, $cb ) {}
class WP_Error { private $m; public function __construct( $c = '', $m = '' ) { $this->m = $m; } public function get_error_message() { return $this->m; } }

// Kozlov TV (заглушки афиши)
$GLOBALS['around'] = array( 'current' => null, 'next' => null );
class KZV_Util { public static function clean_title( $t ) { return trim( $t ); } }
class KZV_Concerts { public static function around( $now ) { return $GLOBALS['around']; } }

require dirname( __DIR__, 2 ) . '/plugins/kozlov-streamvi-guard/kozlov-streamvi-guard.php';

// упрощённая копия KZV_Live::state(): кэш 45 с, при ошибке StreamVi known=false
$GLOBALS['status_calls'] = 0;
function kzv_state() {
	$c = get_transient( 'kzv_live_state' );
	if ( is_array( $c ) ) { return $c; }
	$state = array( 'live' => false, 'vk_oid' => 0, 'vk_id' => 0, 'yt_id' => '', 'title' => '', 'url' => '', 'started' => 0, 'next' => null, 'checked' => time(), 'known' => false, 'signal' => '' );
	$GLOBALS['status_calls']++;
	$r = fake_http( 'https://api-v2.streamvi.io/method/broadcast/status?v=1' );
	if ( ! is_wp_error( $r ) ) { $state['known'] = true; }
	set_transient( 'kzv_live_state', $state, 45 );
	return $state;
}
// HTTP как в WP_Http::request: pre_http_request, затем «сеть», затем http_api_debug
$GLOBALS['net'] = 'down';
function fake_http( $url ) {
	$pre = apply_filters( 'pre_http_request', false, array(), $url );
	if ( false !== $pre ) { return $pre; }
	$GLOBALS['net_calls'] = ( $GLOBALS['net_calls'] ?? 0 ) + 1;
	$res = 'down' === $GLOBALS['net'] ? new WP_Error( 'http_request_failed', 'cURL error 28: Failed to connect to api-v2.streamvi.io port 443 after 3001 ms: Timeout was reached' )
		: ( 'ssl' === $GLOBALS['net'] ? new WP_Error( 'http_request_failed', 'cURL error 60: SSL certificate problem' ) : array( 'response' => array( 'code' => 200 ) ) );
	do_action( 'http_api_debug', $res, 'response', 'Requests', array(), $url );
	return $res;
}

$fails = 0;
function ok( $cond, $name ) { global $fails; echo ( $cond ? 'OK   ' : 'FAIL ' ) . $name . "\n"; if ( ! $cond ) { $fails++; } }
function reset_all() { $GLOBALS['opts'] = array(); $GLOBALS['tr'] = array(); $GLOBALS['net_calls'] = 0; $GLOBALS['status_calls'] = 0; $GLOBALS['admin'] = false; unset( $GLOBALS['pagenow'] ); $r = new ReflectionProperty( 'KZSG', 'state' ); $r->setAccessible( true ); $r->setValue( null, null ); }

$now = time();

// 1. Связь есть: ничего не меняется
reset_all(); $GLOBALS['net'] = 'up';
$r = fake_http( 'https://api-v2.streamvi.io/x' );
ok( ! is_wp_error( $r ) && 1 === $GLOBALS['net_calls'], 'связь есть: запрос идёт в сеть' );
ok( 0 === KZSG::state()['since'], 'связь есть: паузы нет' );

// 2. Сбой сети: пауза 5 мин, следующий запрос мгновенно получает ошибку
reset_all(); $GLOBALS['net'] = 'down';
fake_http( 'https://api-v2.streamvi.io/x' );
$s = KZSG::state();
ok( $s['until'] >= $now + 299 && $s['until'] <= time() + 300 && 1 === $s['fails'], 'сбой: пауза 5 минут' );
$r = fake_http( 'https://api-v2.streamvi.io/y' );
ok( is_wp_error( $r ) && 1 === $GLOBALS['net_calls'] && false !== strpos( $r->get_error_message(), 'пауза' ), 'пауза: запрос не идёт в сеть, ошибка сразу' );

// 3. Другие сайты пауза не трогает
$r = fake_http( 'https://wordpress.org/' );
ok( 2 === $GLOBALS['net_calls'], 'пауза только для api-v2.streamvi.io' );

// 4. Кнопки админки (admin-post.php) идут мимо паузы
$GLOBALS['admin'] = true; $GLOBALS['pagenow'] = 'admin-post.php';
$before = $GLOBALS['net_calls'];
fake_http( 'https://api-v2.streamvi.io/z' );
ok( $GLOBALS['net_calls'] === $before + 1, 'admin-post.php: запрос идёт в сеть мимо паузы' );
ok( 2 === KZSG::state()['fails'] && KZSG::state()['since'] <= $now + 1, 'повторный сбой: счётчик растёт, «с какого времени» не меняется' );
$GLOBALS['admin'] = false; unset( $GLOBALS['pagenow'] );

// 5. Пауза кончилась, связь вернулась: пауза снимается
$st = KZSG::state(); $st['until'] = time() - 1; update_option( KZSG::STATE, $st );
$r = new ReflectionProperty( 'KZSG', 'state' ); $r->setAccessible( true ); $r->setValue( null, null );
$GLOBALS['net'] = 'up';
$x = fake_http( 'https://api-v2.streamvi.io/w' );
$s = KZSG::state();
ok( ! is_wp_error( $x ) && 0 === $s['since'] && 0 === $s['until'] && $s['recovered'] > 0, 'восстановление: пауза снята' );

// 6. Ошибка не сети (SSL) паузу не включает
reset_all(); $GLOBALS['net'] = 'ssl';
fake_http( 'https://api-v2.streamvi.io/x' );
ok( 0 === KZSG::state()['until'], 'ошибка SSL: паузы нет' );

// 7. Выключено: всё как без плагина
reset_all(); $GLOBALS['net'] = 'down';
update_option( KZSG::OPT, array( 'enabled' => 0, 'tv' => 1 ) );
fake_http( 'https://api-v2.streamvi.io/x' );
fake_http( 'https://api-v2.streamvi.io/x' );
ok( 2 === $GLOBALS['net_calls'], 'выключено: пауза не мешает запросам' );

// ---------- Kozlov TV
$cur  = array( 'start' => $now - 40 * 60, 'end' => $now + 80 * 60, 'title' => 'JOINTRIP (КУБА/РОССИЯ)', 'url' => 'https://kozlovclub.ru/event/jointrip/' );
$next = array( 'start' => $now + 18 * 3600, 'end' => 0, 'title' => 'MARIMBA PLUS', 'url' => 'https://kozlovclub.ru/event/marimba/' );

// 8. Концерт идёт 40 мин, связи нет: первая загрузка ждёт сеть 1 раз, дальше эфир по афише без запросов
reset_all(); $GLOBALS['net'] = 'down'; $GLOBALS['around'] = array( 'current' => $cur, 'next' => $next );
$a = kzv_state();
ok( 1 === $GLOBALS['status_calls'], 'TV: первый запрос статуса один раз' );
$b = kzv_state();
ok( true === $b['live'] && 'JOINTRIP (КУБА/РОССИЯ)' === $b['title'] && 0 === $b['vk_id'], 'TV: дальше «В эфире: JOINTRIP» по афише' );
unset( $GLOBALS['tr']['kzv_live_state'], $GLOBALS['tr'][ KZSG::FB ] );
$c = kzv_state();
ok( 1 === $GLOBALS['status_calls'] && 1 === $GLOBALS['net_calls'] && true === $c['live'], 'TV: во время паузы StreamVi не спрашивается вовсе' );

// 9. Концерт начался 10 мин назад: эфира по афише ещё нет, видна следующая
reset_all(); $GLOBALS['net'] = 'down';
$GLOBALS['around'] = array( 'current' => array_merge( $cur, array( 'start' => $now - 600 ) ), 'next' => $next );
fake_http( 'https://api-v2.streamvi.io/x' );
$d = kzv_state();
ok( false === $d['live'] && 0 === $GLOBALS['status_calls'], 'TV: в первые 20 минут концерта «В эфире» не ставится' );
$GLOBALS['around'] = array( 'current' => null, 'next' => $next );
unset( $GLOBALS['tr'][ KZSG::FB ] );
$e = KZSG::guess( null );
ok( false === $e['live'] && 'MARIMBA PLUS' === $e['next']['title'], 'TV: без концерта показывается следующий эфир' );

// 10. Концерт закончился по афише: эфира нет
$GLOBALS['around'] = array( 'current' => array_merge( $cur, array( 'start' => $now - 4 * 3600, 'end' => $now - 60 ) ), 'next' => $next );
ok( false === KZSG::guess( null )['live'], 'TV: после конца концерта «В эфире» снимается' );

// 11. Связь есть и StreamVi сказал «эфира нет»: ничего не подменяем
reset_all(); $GLOBALS['net'] = 'up'; $GLOBALS['around'] = array( 'current' => $cur, 'next' => $next );
$f = kzv_state();
ok( false === $f['live'] && true === $f['known'], 'TV: при живой связи решает StreamVi' );

// 12. Галочка Kozlov TV выключена: подмены нет
reset_all(); $GLOBALS['net'] = 'down'; update_option( KZSG::OPT, array( 'enabled' => 1, 'tv' => 0 ) );
kzv_state(); unset( $GLOBALS['tr']['kzv_live_state'] );
$g = kzv_state();
ok( false === $g['live'] && 2 === $GLOBALS['status_calls'] && 1 === $GLOBALS['net_calls'], 'TV выкл: эфир не подменяется, но пауза работает' );

// Ограничение подключения 3 с (CURLOPT_CONNECTTIMEOUT_MS) здесь не проверить: нужна сеть, где
// недоступный адрес не отвечает. Проверяется на сервере: «Связь со StreamVi → Проверить связь сейчас».

echo $fails ? "\nПРОВАЛОВ: {$fails}\n" : "\nВсе проверки пройдены\n";
exit( $fails ? 1 : 0 );
