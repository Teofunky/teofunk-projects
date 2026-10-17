<?php
// Проверка PHP-части Kozlov Afisha Filters на заглушках WordPress.
// php harness.php <папка плагина>
error_reporting( E_ALL );
ini_set( 'display_errors', '1' );
set_error_handler( function ( $no, $str, $file, $line ) { throw new ErrorException( $str, 0, $no, $file, $line ); } );

define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['T'] = array( 'opt' => array(), 'admin' => false, 'logged' => false, 'front' => true, 'actions' => array(), 'scripts' => array(), 'is_admin' => false, 'log' => array(), 'flushed' => 0, 'errors' => array(), 'deact' => array(), 'opt_throw' => false );
function add_action( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS['T']['actions'][ $h ][] = $cb; }
function add_filter( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS['T']['actions'][ $h ][] = $cb; }
function register_deactivation_hook( $f, $cb ) { $GLOBALS['T']['deact'][] = $cb; }
function get_option( $k, $d = false ) { if ( $GLOBALS['T']['opt_throw'] ) { throw new RuntimeException( 'db down' ); } return array_key_exists( $k, $GLOBALS['T']['opt'] ) ? $GLOBALS['T']['opt'][ $k ] : $d; }
function current_user_can( $c ) { return $GLOBALS['T']['admin']; }
function is_user_logged_in() { return $GLOBALS['T']['logged']; }
function is_admin() { return $GLOBALS['T']['is_admin']; }
function is_feed() { return false; }
function is_embed() { return false; }
function is_customize_preview() { return false; }
function is_front_page() { return $GLOBALS['T']['front']; }
function wp_enqueue_script( $h, $src, $deps, $ver, $args ) { $GLOBALS['T']['scripts'][] = array( $h, $src, $ver, $args ); }
function plugins_url( $p, $f ) { return 'https://kozlovclub.ru/wp-content/plugins/kozlov-afisha-filters/' . $p; }
function rest_url( $p ) { return 'https://kozlovclub.ru/wp-json/' . $p; }
function wp_make_link_relative( $u ) { return preg_replace( '#^https?://[^/]+#', '', $u ); }
function wp_create_nonce( $a ) { return 'n0nce'; }
function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); }
function plugin_basename( $f ) { return 'kozlov-afisha-filters/kozlov-afisha-filters.php'; }
function admin_url( $p ) { return 'https://kozlovclub.ru/wp-admin/' . $p; }
function home_url( $p ) { return 'https://kozlovclub.ru' . $p; }
function esc_url( $u ) { return htmlspecialchars( $u, ENT_QUOTES ); }
function esc_html( $s ) { return htmlspecialchars( $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( $s, ENT_QUOTES ); }
function register_setting( $g, $n, $a ) { $GLOBALS['T']['setting'] = array( $g, $n, $a ); }
function add_options_page() { $GLOBALS['T']['page'] = true; }
function settings_fields( $g ) { echo '<input type="hidden" name="option_page" value="' . $g . '">'; }
function submit_button( $t ) { echo '<button>' . $t . '</button>'; }
function checked( $a, $b ) { if ( $a === $b ) { echo ' checked'; } }
function add_settings_error( $s, $c, $m, $t ) { $GLOBALS['T']['errors'][] = array( $c, $m, $t ); }
$LOGF = sys_get_temp_dir() . '/kaf-harness.log'; if ( is_file( $LOGF ) ) { unlink( $LOGF ); } ini_set( 'error_log', $LOGF );

$dir = rtrim( $argv[1], '/' );
$pass = 0; $fail = 0;
function ok( $c, $name ) { global $pass, $fail; if ( $c ) { $pass++; } else { $fail++; echo "FAIL $name\n"; } }
function run( $hook ) { foreach ( isset( $GLOBALS['T']['actions'][ $hook ] ) ? $GLOBALS['T']['actions'][ $hook ] : array() as $cb ) { call_user_func( $cb ); } }
function reset_front() { $r = new ReflectionProperty( 'KAF_Front', 'on' ); $r->setAccessible( true ); $r->setValue( null, null ); $GLOBALS['T']['scripts'] = array(); }
function front_html() { reset_front(); run( 'wp_enqueue_scripts' ); ob_start(); run( 'wp_footer' ); return ob_get_clean(); }

// Kozlov Smart Search как заглушка
class KSS_Settings { public static $m = 'on'; public static function mode() { return self::$m; } }
define( 'KSS_VERSION', '1.0.4' );

require $dir . '/kozlov-afisha-filters.php';
ok( defined( 'KAF_VERSION' ) && '1.0.0' === KAF_VERSION, 'версия' );
ok( isset( $GLOBALS['T']['actions']['plugins_loaded'] ), 'загрузка на plugins_loaded' );
ok( array( 'kaf_purge_page_cache' ) === $GLOBALS['T']['deact'], 'при выключении плагина сбрасывается кэш страниц' );
run( 'plugins_loaded' );
ok( class_exists( 'KAF_Front' ) && class_exists( 'KAF_Admin' ) && class_exists( 'KAF_Settings' ), 'классы загружены' );

// режимы и видимость
$matrix = array(
	// mode, kss mode, admin, ожидание
	array( 'off', 'on', true, false ),
	array( 'preview', 'on', false, false ),
	array( 'preview', 'on', true, true ),
	array( 'on', 'on', false, true ),
	array( 'on', 'admin', false, false ),
	array( 'on', 'admin', true, true ),
	array( 'on', 'off', true, false ),
	array( 'preview', 'off', true, false ),
	array( 'bogus', 'on', true, false ),
);
foreach ( $matrix as $m ) {
	$GLOBALS['T']['opt']['kaf_settings'] = array( 'mode' => $m[0] );
	KSS_Settings::$m = $m[1];
	$GLOBALS['T']['admin'] = $m[2];
	ok( KAF_Settings::visible_for_current_user() === $m[3], 'видимость ' . implode( '/', array( $m[0], $m[1], $m[2] ? 'admin' : 'guest' ) ) );
}
ok( array( 'mode' => 'off' ) === KAF_Settings::sanitize( array( 'mode' => '<script>' ) ), 'неизвестный режим становится off' );
ok( array( 'mode' => 'on' ) === KAF_Settings::sanitize( array( 'mode' => 'on', 'x' => 1 ) ), 'лишние поля отбрасываются' );
ok( array( 'mode' => 'off' ) === KAF_Settings::sanitize( 'on' ), 'не массив становится off' );
$GLOBALS['T']['opt_throw'] = true;
ok( 'off' === KAF_Settings::mode(), 'ошибка базы при чтении настроек: off' );
$GLOBALS['T']['opt_throw'] = false;

// вывод на главной
$GLOBALS['T']['opt']['kaf_settings'] = array( 'mode' => 'on' );
KSS_Settings::$m = 'on';
$GLOBALS['T']['admin'] = false; $GLOBALS['T']['logged'] = false;
$html = front_html();
ok( 1 === count( $GLOBALS['T']['scripts'] ), 'скрипт подключён' );
$s = $GLOBALS['T']['scripts'][0];
ok( 'defer' === $s[3]['strategy'] && true === $s[3]['in_footer'] && '1.0.0' === $s[2], 'скрипт с defer, в подвале, с версией' );
ok( preg_match( '#<script type="application/json" id="kaf-config">(.*)</script>#', $html, $mm ) === 1, 'конфигурация выведена' );
$cfg = json_decode( $mm[1], true );
ok( '/wp-json/kozlov-search/v1/index' === $cfg['index'], 'адрес индекса относительный' );
ok( '/wp-content/plugins/kozlov-afisha-filters/assets/fonts/' === $cfg['fonts'], 'адрес шрифтов' );
ok( ! isset( $cfg['nonce'] ) && 0 === $cfg['preview'], 'гостю без nonce и не в режиме проверки' );
$GLOBALS['T']['logged'] = true; $GLOBALS['T']['admin'] = true;
$GLOBALS['T']['opt']['kaf_settings'] = array( 'mode' => 'preview' );
$html = front_html();
preg_match( '#id="kaf-config">(.*)</script>#', $html, $mm );
$cfg = json_decode( $mm[1], true );
ok( 'n0nce' === $cfg['nonce'] && 1 === $cfg['preview'], 'администратору nonce и режим проверки' );
$GLOBALS['T']['admin'] = false; $GLOBALS['T']['logged'] = true;
$html = front_html();
ok( '' === $html && 0 === count( $GLOBALS['T']['scripts'] ), 'режим проверки: вошедшему не администратору ничего' );
$GLOBALS['T']['opt']['kaf_settings'] = array( 'mode' => 'on' );
$GLOBALS['T']['front'] = false;
ok( '' === front_html() && 0 === count( $GLOBALS['T']['scripts'] ), 'не главная: ничего' );
$GLOBALS['T']['front'] = true;
$GLOBALS['T']['is_admin'] = true;
ok( '' === front_html(), 'в админке: ничего' );
$GLOBALS['T']['is_admin'] = false;
$GLOBALS['T']['opt_throw'] = true;
ok( '' === front_html(), 'ошибка чтения настроек: ничего и без падения' );
$GLOBALS['T']['opt_throw'] = false;

// страница настроек
$GLOBALS['T']['admin'] = true;
run( 'admin_init' );
ok( 'kaf_settings' === $GLOBALS['T']['setting'][1], 'настройка зарегистрирована' );
ob_start(); KAF_Admin::render(); $page = ob_get_clean();
ok( false !== strpos( $page, 'Kozlov Afisha Filters' ) && 1 === preg_match( '/value="on"\s+checked/', $page ) && 0 === preg_match( '/value="off"\s+checked/', $page ), 'страница настроек с текущим режимом' );
ok( false !== strpos( $page, 'все посетители главной страницы' ), 'кто видит панель: все' );
KSS_Settings::$m = 'off';
ob_start(); KAF_Admin::render(); $page = ob_get_clean();
ok( false !== strpos( $page, 'Kozlov Smart Search выключен' ) && false !== strpos( $page, 'никто' ), 'предупреждение о выключенном поиске' );
KSS_Settings::$m = 'on';
$links = KAF_Admin::links( array( 'deactivate' => 'x' ) );
ok( false !== strpos( $links[0], 'options-general.php?page=kozlov-afisha-filters' ), 'ссылка «Настройки» в списке плагинов' );
$GLOBALS['T']['admin'] = false;
ob_start(); KAF_Admin::render(); $page = ob_get_clean();
ok( '' === $page, 'страница настроек закрыта не администратору' );

// сброс кэша при смене режима
function cb( $hook ) { return $GLOBALS['T']['actions'][ $hook ][0]; }
ok( is_callable( cb( 'update_option_kaf_settings' ) ) && is_callable( cb( 'add_option_kaf_settings' ) ), 'обработчики сохранения подключены' );
call_user_func( cb( 'update_option_kaf_settings' ), array( 'mode' => 'off' ), array( 'mode' => 'preview' ) );
ok( array() === $GLOBALS['T']['errors'], 'off → проверка: кэш не трогаем' );
call_user_func( cb( 'update_option_kaf_settings' ), array( 'mode' => 'preview' ), array( 'mode' => 'on' ) );
ok( 1 === count( $GLOBALS['T']['errors'] ) && 'warning' === $GLOBALS['T']['errors'][0][2], 'без W3 Total Cache: подсказка сбросить кэш вручную' );
if ( ! function_exists( 'w3tc_pgcache_flush' ) ) { function w3tc_pgcache_flush() { $GLOBALS['T']['flushed']++; } } // объявляется только здесь, а не при разборе файла
$GLOBALS['T']['errors'] = array();
call_user_func( cb( 'update_option_kaf_settings' ), array( 'mode' => 'on' ), array( 'mode' => 'off' ) );
ok( 1 === $GLOBALS['T']['flushed'] && 'updated' === $GLOBALS['T']['errors'][0][2], 'включено → выключено: кэш W3 Total Cache сброшен' );
call_user_func( cb( 'add_option_kaf_settings' ), 'kaf_settings', array( 'mode' => 'on' ) );
ok( 2 === $GLOBALS['T']['flushed'], 'первое сохранение сразу «для всех»: кэш сброшен' );
call_user_func( cb( 'update_option_kaf_settings' ), array( 'mode' => 'on' ), array( 'mode' => 'on' ) );
ok( 2 === $GLOBALS['T']['flushed'], 'режим не менялся: кэш не трогаем' );
ok( true === kaf_purge_page_cache() && 3 === $GLOBALS['T']['flushed'], 'сброс при выключении плагина' );

// аварийное отключение
$out = shell_exec( 'php -r ' . escapeshellarg( 'define("ABSPATH","/");define("KAF_DISABLE",true);$h=array();function add_action($a,$b,$c=10){global $h;$h[$a]=$b;}function register_deactivation_hook(){}require "' . $dir . '/kozlov-afisha-filters.php";call_user_func($h["plugins_loaded"]);echo class_exists("KAF_Front")?"loaded":"skipped";' ) );
ok( 'skipped' === trim( $out ), 'KAF_DISABLE: плагин не загружается' );
// старая версия поиска
$out = shell_exec( 'php -r ' . escapeshellarg( 'define("ABSPATH","/");define("KSS_VERSION","1.0.2");class KSS_Settings{static function mode(){return "on";}}function get_option($k,$d=false){return array("mode"=>"on");}function current_user_can(){return true;}require "' . $dir . '/includes/class-kaf-settings.php";var_export(KAF_Settings::visible_for_current_user());' ) );
ok( 'false' === trim( $out ), 'Kozlov Smart Search 1.0.2: панели нет' );
$log = is_file( $LOGF ) ? file_get_contents( $LOGF ) : '';
ok( 2 === substr_count( $log, 'db down' ) && 2 === substr_count( $log, '[kozlov-afisha-filters]' ), 'в журнале только две намеренные ошибки базы' );

echo "\nphp: $pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
