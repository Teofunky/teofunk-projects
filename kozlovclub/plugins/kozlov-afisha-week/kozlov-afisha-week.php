<?php
/**
 * Plugin Name:       Kozlov Afisha Week
 * Description:       Афиша главной по неделям: сразу видны ближайшие 7 дней, остальное открывает кнопка «Показать концерты …». Карточки дальше недели остаются на странице в отложенном виде, поэтому фильтры и поиск (Kozlov Afisha Filters 1.0.10+) ищут по всей афише. Тему, базу и события не меняет.
 * Version:           1.0.4
 * Requires at least: 5.3
 * Requires PHP:      7.2
 * Author:            Клуб Алексея Козлова
 * License:           GPL-2.0-or-later
 * Text Domain:       kozlov-afisha-week
 *
 * Как устроен плагин:
 *  - только главная страница без параметров темы (date1, genre, sort) и без фильтров в адресе (kaf_*);
 *  - готовый HTML главной правится одним проходом: карточки дальше первых N дней заворачиваются в <template>,
 *    браузер их не раскладывает и не грузит их картинки (на телефоне это основная нагрузка главной);
 *  - скрипт ставит под афишей кнопку и раскрывает карточки по нажатию, по фильтру или при возврате «Назад»;
 *  - любая ошибка оставляет страницу как была: PHP возвращает исходный HTML, скрипт раскрывает все карточки;
 *  - если скрипт не загрузился или JavaScript выключен, под афишей остаётся обычная ссылка «Показать всю афишу»
 *    на главную с ?kaw=off, где плагин ничего не прячет.
 *
 * Аварийное отключение без входа в админку: в wp-config.php строка
 *     define( 'KAW_DISABLE', true );
 * или удалите папку плагина, затем сбросьте кэш страниц (Performance → Purge All Caches).
 * Отключить на один заход: добавьте к адресу ?kaw=off
 * Проверить без входа в админку (телефон, другой браузер): ?kaw=test, работает в режимах «Только редакторы» и «Все»
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( defined( 'KAW_VERSION' ) ) {
	return;
}

define( 'KAW_VERSION', '1.0.4' );
define( 'KAW_FILE', __FILE__ );
define( 'KAW_URL', plugin_dir_url( __FILE__ ) );

require_once dirname( __FILE__ ) . '/includes/transform.php';

/* ---------- настройки ---------- */

function kaw_defaults() {
	return array( 'mode' => 'editors', 'first' => 7, 'step' => 7 );
}

function kaw_settings() {
	$s = get_option( 'kaw_settings', array() );
	$s = is_array( $s ) ? array_merge( kaw_defaults(), $s ) : kaw_defaults();
	if ( ! in_array( $s['mode'], array( 'off', 'editors', 'all' ), true ) ) {
		$s['mode'] = 'editors';
	}
	$s['first'] = max( 1, min( 62, (int) $s['first'] ) );
	$s['step']  = max( 1, min( 62, (int) $s['step'] ) );
	return $s;
}

register_activation_hook( __FILE__, 'kaw_activate' );
// выключение: главная из кэша не должна остаться с отложенными карточками без скрипта плагина
register_deactivation_hook( __FILE__, 'kaw_purge_home' );
function kaw_activate() {
	// после установки афишу по неделям видят только редакторы: посетители видят главную как раньше
	if ( false === get_option( 'kaw_settings', false ) ) {
		add_option( 'kaw_settings', kaw_defaults(), '', true );
	}
}

/* ---------- главная ---------- */

function kaw_wanted() {
	if ( defined( 'KAW_DISABLE' ) && KAW_DISABLE ) {
		return false;
	}
	if ( is_admin() || is_feed() || is_preview() || is_customize_preview() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return false;
	}
	if ( ! ( is_front_page() || is_home() ) || is_paged() ) {
		return false;
	}
	$test = false;
	foreach ( $_GET as $k => $v ) { // phpcs:ignore WordPress.Security.NonceVerification
		$k = (string) $k;
		if ( 'kaw' === $k && 'test' === $v ) {
			$test = true; // проверка на любом устройстве без входа в админку: адрес с ?kaw=test
			continue;
		}
		if ( in_array( $k, array( 'date1', 'genre', 'sort', 'kaw', 's' ), true ) || 0 === strpos( $k, 'kaf_' ) ) {
			return false; // афиша уже сокращена темой, выбраны фильтры или плагин выключен на этот заход
		}
	}
	$s = kaw_settings();
	if ( $test && 'off' !== $s['mode'] ) {
		return true;
	}
	if ( 'all' === $s['mode'] ) {
		return true;
	}
	return 'editors' === $s['mode'] && is_user_logged_in() && current_user_can( 'edit_posts' );
}

// 9998: раньше буфера Kozlov Perf (9999), то есть снаружи него: сюда приходит уже обработанный им HTML
add_action( 'template_redirect', 'kaw_maybe_buffer', 9998 );
function kaw_maybe_buffer() {
	try {
		if ( ! kaw_wanted() ) {
			return;
		}
		ob_start( 'kaw_process' );
		add_action( 'wp_enqueue_scripts', 'kaw_enqueue', 20 );
	} catch ( Throwable $e ) {
		kaw_log( $e );
	}
}

function kaw_today() {
	try {
		$now = new DateTimeImmutable( 'now', new DateTimeZone( 'Europe/Moscow' ) );
		return $now->format( 'Y-m-d' );
	} catch ( Throwable $e ) {
		return gmdate( 'Y-m-d', time() + 3 * 3600 );
	}
}

function kaw_process( $html ) {
	try {
		if ( ! is_string( $html ) || strlen( $html ) < 1000 ) {
			return $html;
		}
		$s = kaw_settings();
		$r = kaw_transform( $html, kaw_today(), $s['first'], $s['step'], add_query_arg( 'kaw', 'off', home_url( '/' ) ) . '#events' );
		if ( $r['later'] > 0 && $r['html'] !== $html ) {
			return $r['html'] . "\n<!-- Kozlov Afisha Week " . KAW_VERSION . ': ' . (int) $r['kept'] . ' + ' . (int) $r['later'] . " -->\n";
		}
		return $html;
	} catch ( Throwable $e ) {
		kaw_log( $e );
		return $html;
	}
}

function kaw_enqueue() {
	wp_enqueue_style( 'kaw', KAW_URL . 'assets/kaw.css', array(), KAW_VERSION );
	// в подвале и без defer: скрипт должен успеть до панели фильтров (она подключена с defer)
	wp_enqueue_script( 'kaw', KAW_URL . 'assets/kaw.js', array(), KAW_VERSION, true );
}

function kaw_log( $e ) {
	if ( function_exists( 'error_log' ) ) {
		error_log( '[Kozlov Afisha Week] ' . ( $e instanceof Throwable ? $e->getMessage() : (string) $e ) ); // phpcs:ignore
	}
}

/**
 * Сброс кэша главной во всех найденных системах кэша. Возвращает названия тех, что были сброшены.
 * На kozlovclub.ru стоит W3 Total Cache; остальные ветки срабатывают, только если такой плагин есть.
 * Внешний кэш (CDN, прокси хостинга) сбрасывается через хук kaw_purge_home, если его кто-то обрабатывает.
 */
function kaw_purge_home() {
	$url  = home_url( '/' );
	$done = array();
	try {
		if ( function_exists( 'w3tc_flush_url' ) ) {
			w3tc_flush_url( $url );
			$done[] = 'W3 Total Cache';
		} elseif ( function_exists( 'w3tc_pgcache_flush' ) ) {
			w3tc_pgcache_flush();
			$done[] = 'W3 Total Cache';
		}
		if ( function_exists( 'wpsc_delete_url_cache' ) ) {
			wpsc_delete_url_cache( $url );
			$done[] = 'WP Super Cache';
		}
		if ( function_exists( 'rocket_clean_home' ) ) {
			rocket_clean_home();
			$done[] = 'WP Rocket';
		}
		if ( defined( 'LSCWP_V' ) ) {
			do_action( 'litespeed_purge_url', $url );
			$done[] = 'LiteSpeed Cache';
		}
		do_action( 'kaw_purge_home', $url );
	} catch ( Throwable $e ) {
		kaw_log( $e );
	}
	return $done;
}

/** Какие системы кэша страниц найдены: для подсказки в настройках. */
function kaw_cache_systems() {
	$f = array();
	if ( function_exists( 'w3tc_flush_url' ) || function_exists( 'w3tc_pgcache_flush' ) ) {
		$f[] = 'W3 Total Cache';
	}
	if ( function_exists( 'wpsc_delete_url_cache' ) ) {
		$f[] = 'WP Super Cache';
	}
	if ( function_exists( 'rocket_clean_home' ) ) {
		$f[] = 'WP Rocket';
	}
	if ( defined( 'LSCWP_V' ) ) {
		$f[] = 'LiteSpeed Cache';
	}
	return $f;
}

/* ---------- админка: Настройки → Афиша по неделям ---------- */

add_action( 'admin_init', 'kaw_register' );
function kaw_register() {
	register_setting(
		'kaw',
		'kaw_settings',
		array(
			'type'              => 'array',
			'sanitize_callback' => 'kaw_sanitize',
			'default'           => kaw_defaults(),
		)
	);
}

function kaw_sanitize( $in ) {
	$in  = is_array( $in ) ? $in : array();
	$out = kaw_defaults();
	if ( isset( $in['mode'] ) && in_array( $in['mode'], array( 'off', 'editors', 'all' ), true ) ) {
		$out['mode'] = $in['mode'];
	}
	if ( isset( $in['first'] ) ) {
		$out['first'] = max( 1, min( 62, (int) $in['first'] ) );
	}
	if ( isset( $in['step'] ) ) {
		$out['step'] = max( 1, min( 62, (int) $in['step'] ) );
	}
	return $out;
}

add_action( 'update_option_kaw_settings', 'kaw_purge_home' );
add_action( 'add_option_kaw_settings', 'kaw_purge_home' );

add_action( 'admin_menu', 'kaw_menu' );
function kaw_menu() {
	add_options_page( 'Афиша по неделям', 'Афиша по неделям', 'manage_options', 'kozlov-afisha-week', 'kaw_page' );
}

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'kaw_links' );
function kaw_links( $links ) {
	array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=kozlov-afisha-week' ) ) . '">Настройки</a>' );
	return $links;
}

function kaw_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$s     = kaw_settings();
	$modes = array(
		'off'     => 'Выключено: главная как раньше, вся афиша сразу',
		'editors' => 'Только редакторы (вошедшие в админку): для проверки',
		'all'     => 'Все посетители',
	);
	?>
	<div class="wrap">
		<h1>Афиша по неделям <span style="font-size:13px;color:#646970;font-weight:400">версия <?php echo esc_html( KAW_VERSION ); ?></span></h1>
		<p style="max-width:720px">На главной сразу видны ближайшие дни, остальные концерты открывает кнопка под афишей. Карточки дальше этих дней остаются на странице, но браузер их не раскладывает и не грузит их картинки, поэтому главная открывается быстрее, особенно на телефоне. Фильтры и поиск над афишей ищут по всем концертам (нужен Kozlov Afisha Filters 1.0.10 или новее).</p>
		<form method="post" action="options.php">
			<?php settings_fields( 'kaw' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Кто видит</th>
					<td>
						<?php foreach ( $modes as $k => $text ) : ?>
							<label style="display:block;margin:0 0 6px"><input type="radio" name="kaw_settings[mode]" value="<?php echo esc_attr( $k ); ?>" <?php checked( $s['mode'], $k ); ?>> <?php echo esc_html( $text ); ?></label>
						<?php endforeach; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="kaw-first">Видно сразу, дней</label></th>
					<td><input id="kaw-first" type="number" min="1" max="62" name="kaw_settings[first]" value="<?php echo esc_attr( $s['first'] ); ?>" class="small-text"> <span class="description">сегодня и следующие дни, по умолчанию 7</span></td>
				</tr>
				<tr>
					<th scope="row"><label for="kaw-step">Кнопка открывает, дней</label></th>
					<td><input id="kaw-step" type="number" min="1" max="62" name="kaw_settings[step]" value="<?php echo esc_attr( $s['step'] ); ?>" class="small-text"> <span class="description">по умолчанию 7</span></td>
				</tr>
			</table>
			<?php submit_button( 'Сохранить' ); ?>
		</form>
		<?php $kaw_caches = kaw_cache_systems(); ?>
		<p class="description">
			<?php if ( $kaw_caches ) : ?>
				Кэш главной сбрасывается сам при сохранении и при выключении плагина: <?php echo esc_html( implode( ', ', $kaw_caches ) ); ?>.
			<?php else : ?>
				Плагин кэша страниц не найден: после сохранения или выключения при необходимости сбросьте кэш вручную.
			<?php endif; ?>
			Браузеры посетителей могут держать старую главную до часа (заголовок Cache-Control сервера); в обе стороны это безопасно.
			Выключить на один заход: <code><?php echo esc_html( home_url( '/?kaw=off' ) ); ?></code>.
			Проверить на телефоне без входа: <code><?php echo esc_html( home_url( '/?kaw=test' ) ); ?></code>
		</p>
	</div>
	<?php
}
