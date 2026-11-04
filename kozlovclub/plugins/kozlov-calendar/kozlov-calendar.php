<?php
/**
 * Plugin Name: Kozlov Calendar
 * Description: Компактный календарь концертов: день или период, прошедшие даты неактивны, число концертов в кнопке. Открывается по кнопке «Календарь» темы (.calendar-toggler). Работает с All-in-One Event Calendar и с Kozlov Core.
 * Version: 1.0.0
 * Author: Kozlov Club
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Text Domain: kozlov-calendar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'KCAL_VER', '1.0.0' );
define( 'KCAL_OPT', 'kcal_settings' );

/** Настройки с умолчаниями. */
function kcal_settings() {
	$defaults = array(
		'mode'      => 'admins',               // off | admins | all
		'range'     => 1,                      // разрешить период
		'selector'  => '.calendar-toggler, [data-kozlov-calendar]',
		'base_url'  => '',                     // пусто = главная
		'param_1'   => 'date1',
		'param_2'   => 'date2',
		'months'    => 12,
		'night_h'   => 7,                      // концерты до 07:00 относятся к предыдущему дню
	);
	$saved = get_option( KCAL_OPT, array() );
	return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
}

/** Показывать ли календарь текущему посетителю. */
function kcal_active() {
	$s = kcal_settings();
	if ( 'all' === $s['mode'] ) {
		return true;
	}
	if ( 'admins' === $s['mode'] ) {
		return is_user_logged_in() && current_user_can( 'edit_posts' );
	}
	return false;
}

/** Сегодня по часам клуба (часовой пояс сайта). */
function kcal_today() {
	return wp_date( 'Y-m-d' );
}

/* ---------- данные: число концертов по дням ---------- */

/** Какой источник доступен на этом сайте. */
function kcal_source() {
	if ( function_exists( 'kozlov_concerts' ) ) {
		return 'kozlov-core';
	}
	global $wpdb;
	$t = $wpdb->prefix . 'ai1ec_event_instances';
	if ( $t === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) ) {
		return 'ai1ec';
	}
	return '';
}

/** Дата афиши по локальному времени начала: ночные концерты до night_h часов относятся к предыдущему дню. */
function kcal_day_of( DateTimeImmutable $local, $night_h ) {
	$d = $local;
	if ( (int) $d->format( 'G' ) < (int) $night_h ) {
		$d = $d->modify( '-1 day' );
	}
	return $d->format( 'Y-m-d' );
}

/** Массив 'Y-m-d' => число концертов, от сегодня на months месяцев вперёд. */
function kcal_days() {
	$s      = kcal_settings();
	$today  = kcal_today();
	$ver    = (int) get_option( 'kcal_cache_ver', 1 );
	$key    = 'kcal_days_' . $ver . '_' . $today;
	$cached = get_transient( $key );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	$days   = array();
	$source = kcal_source();
	$utc    = new DateTimeZone( 'UTC' );
	$to_day = gmdate( 'Y-m-d', strtotime( $today . ' +' . ( (int) $s['months'] + 1 ) . ' months' ) );

	try {
		if ( 'kozlov-core' === $source ) {
			$list = kozlov_concerts( array( 'from' => $today . ' 00:00:00', 'to' => $to_day . ' 23:59:59', 'past' => true, 'limit' => 5000 ) );
			foreach ( ( is_array( $list ) ? $list : array() ) as $c ) {
				if ( ! empty( $c['cancelled'] ) || empty( $c['start'] ) ) {
					continue;
				}
				$local = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', (string) $c['start'], $utc );
				if ( ! $local ) {
					continue;
				}
				$day = kcal_day_of( $local, $s['night_h'] );
				if ( $day >= $today ) {
					$days[ $day ] = ( $days[ $day ] ?? 0 ) + 1;
				}
			}
		} elseif ( 'ai1ec' === $source ) {
			global $wpdb;
			$from_ts = strtotime( $today . ' 00:00:00 UTC' ) - DAY_IN_SECONDS;
			$to_ts   = strtotime( $to_day . ' 23:59:59 UTC' ) + DAY_IN_SECONDS;
			// В календаре время клуба хранится «UTC-меткой» (пояс события пустой/UTC); для событий с поясом переводим в него.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT i.start, e.timezone_name
					 FROM {$wpdb->prefix}ai1ec_event_instances i
					 INNER JOIN {$wpdb->posts} p ON p.ID = i.post_id
					 LEFT JOIN {$wpdb->prefix}ai1ec_events e ON e.post_id = i.post_id
					 WHERE p.post_type = 'ai1ec_event' AND p.post_status = 'publish'
					   AND i.start BETWEEN %d AND %d",
					$from_ts,
					$to_ts
				)
			);
			$tzs = array();
			foreach ( (array) $rows as $r ) {
				$name = trim( (string) $r->timezone_name );
				if ( '' === $name || 'UTC' === $name ) {
					$tz = $utc;
				} else {
					if ( ! isset( $tzs[ $name ] ) ) {
						try {
							$tzs[ $name ] = new DateTimeZone( $name );
						} catch ( Exception $e ) {
							$tzs[ $name ] = $utc;
						}
					}
					$tz = $tzs[ $name ];
				}
				$local = ( new DateTimeImmutable( '@' . (int) $r->start ) )->setTimezone( $tz );
				$day   = kcal_day_of( $local, $s['night_h'] );
				if ( $day >= $today && $day <= $to_day ) {
					$days[ $day ] = ( $days[ $day ] ?? 0 ) + 1;
				}
			}
		}
	} catch ( Throwable $e ) {
		$days = array();
	}

	ksort( $days );
	$days = apply_filters( 'kozlov_calendar_days', $days, $today, $to_day );
	set_transient( $key, $days, 10 * MINUTE_IN_SECONDS );
	return $days;
}

/** Сбросить кеш при изменении концертов. */
function kcal_bump_cache( $post_id = 0 ) {
	$type = $post_id ? get_post_type( $post_id ) : '';
	if ( $post_id && ! in_array( $type, array( 'ai1ec_event', 'concert' ), true ) ) {
		return;
	}
	update_option( 'kcal_cache_ver', (int) get_option( 'kcal_cache_ver', 1 ) + 1, false );
}
add_action( 'save_post', 'kcal_bump_cache' );
add_action( 'deleted_post', 'kcal_bump_cache' );
add_action( 'trashed_post', 'kcal_bump_cache' );

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'kozlov-calendar/v1',
			'/days',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => function () {
					$res = new WP_REST_Response(
						array(
							'today'  => kcal_today(),
							'days'   => (object) kcal_days(),
							'source' => kcal_source(),
						)
					);
					$res->header( 'Cache-Control', 'public, max-age=300' );
					return $res;
				},
			)
		);
	}
);

/* ---------- фронт ---------- */

add_action(
	'wp_enqueue_scripts',
	function () {
		if ( is_admin() || ! kcal_active() ) {
			return;
		}
		$s    = kcal_settings();
		$base = trim( (string) $s['base_url'] ) ? esc_url_raw( $s['base_url'] ) : home_url( '/' );
		wp_enqueue_script( 'kozlov-calendar', plugins_url( 'assets/calendar.js', __FILE__ ), array(), KCAL_VER, array( 'in_footer' => true, 'strategy' => 'defer' ) );
		wp_add_inline_script(
			'kozlov-calendar',
			'window.KozlovCalendarConfig=' . wp_json_encode(
				array(
					'api'      => esc_url_raw( rest_url( 'kozlov-calendar/v1/days' ) ),
					'today'    => kcal_today(),
					'range'    => (bool) $s['range'],
					'selector' => (string) $s['selector'],
					'base'     => $base,
					'p1'       => sanitize_key( $s['param_1'] ) ?: 'date1',
					'p2'       => sanitize_key( $s['param_2'] ) ?: 'date2',
					'months'   => max( 1, min( 24, (int) $s['months'] ) ),
				)
			) . ';',
			'before'
		);
	}
);

/* ---------- настройки ---------- */

add_action(
	'admin_menu',
	function () {
		add_options_page( 'Kozlov Calendar', 'Kozlov Calendar', 'manage_options', 'kozlov-calendar', 'kcal_settings_page' );
	}
);

add_action(
	'admin_init',
	function () {
		register_setting(
			'kcal',
			KCAL_OPT,
			array(
				'type'              => 'array',
				'sanitize_callback' => function ( $in ) {
					$in = is_array( $in ) ? $in : array();
					kcal_bump_cache();
					return array(
						'mode'     => in_array( $in['mode'] ?? '', array( 'off', 'admins', 'all' ), true ) ? $in['mode'] : 'admins',
						'range'    => empty( $in['range'] ) ? 0 : 1,
						'selector' => sanitize_text_field( $in['selector'] ?? '' ) ?: '.calendar-toggler, [data-kozlov-calendar]',
						'base_url' => esc_url_raw( $in['base_url'] ?? '' ),
						'param_1'  => sanitize_key( $in['param_1'] ?? '' ) ?: 'date1',
						'param_2'  => sanitize_key( $in['param_2'] ?? '' ) ?: 'date2',
						'months'   => max( 1, min( 24, (int) ( $in['months'] ?? 12 ) ) ),
						'night_h'  => max( 0, min( 12, (int) ( $in['night_h'] ?? 7 ) ) ),
					);
				},
			)
		);
	}
);

function kcal_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$s      = kcal_settings();
	$n      = KCAL_OPT;
	$source = kcal_source();
	$days   = kcal_days();
	$labels = array( 'kozlov-core' => 'Kozlov Core', 'ai1ec' => 'All-in-One Event Calendar', '' => 'не найден: календарь работает, но без числа концертов' );
	?>
	<div class="wrap">
		<h1>Kozlov Calendar</h1>
		<p>Источник концертов: <strong><?php echo esc_html( $labels[ $source ] ); ?></strong>. Дней с концертами впереди: <strong><?php echo (int) count( $days ); ?></strong>, концертов: <strong><?php echo (int) array_sum( $days ); ?></strong>. Сегодня по часам сайта: <strong><?php echo esc_html( kcal_today() ); ?></strong>.</p>
		<form method="post" action="options.php">
			<?php settings_fields( 'kcal' ); ?>
			<table class="form-table" role="presentation">
				<tr><th scope="row">Кому показывать</th><td>
					<label><input type="radio" name="<?php echo esc_attr( $n ); ?>[mode]" value="off" <?php checked( $s['mode'], 'off' ); ?>> Выключен (работает старый календарь темы)</label><br>
					<label><input type="radio" name="<?php echo esc_attr( $n ); ?>[mode]" value="admins" <?php checked( $s['mode'], 'admins' ); ?>> Только редакторам и администраторам (проверка)</label><br>
					<label><input type="radio" name="<?php echo esc_attr( $n ); ?>[mode]" value="all" <?php checked( $s['mode'], 'all' ); ?>> Всем посетителям</label>
				</td></tr>
				<tr><th scope="row">Период</th><td><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[range]" value="1" <?php checked( $s['range'], 1 ); ?>> Разрешить выбор периода (второе нажатие задаёт конец)</label>
					<p class="description">Если афиша сайта не понимает второй параметр даты, выключите: останется выбор одного дня.</p></td></tr>
				<tr><th scope="row"><label for="kcal-sel">Кнопки открытия</label></th><td><input id="kcal-sel" class="regular-text code" name="<?php echo esc_attr( $n ); ?>[selector]" value="<?php echo esc_attr( $s['selector'] ); ?>">
					<p class="description">CSS-селектор кнопок «Календарь» в теме. По нажатию открывается этот календарь вместо старого.</p></td></tr>
				<tr><th scope="row"><label for="kcal-base">Страница афиши</label></th><td><input id="kcal-base" class="regular-text code" name="<?php echo esc_attr( $n ); ?>[base_url]" value="<?php echo esc_attr( $s['base_url'] ); ?>" placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>">
					<p class="description">Куда ведёт кнопка «Показать». Пусто = главная.</p></td></tr>
				<tr><th scope="row">Параметры адреса</th><td>
					<label>Дата / начало периода <input class="small-text code" name="<?php echo esc_attr( $n ); ?>[param_1]" value="<?php echo esc_attr( $s['param_1'] ); ?>"></label>&nbsp;
					<label>Конец периода <input class="small-text code" name="<?php echo esc_attr( $n ); ?>[param_2]" value="<?php echo esc_attr( $s['param_2'] ); ?>"></label>
					<p class="description">Пример: <code><?php echo esc_html( add_query_arg( array( $s['param_1'] => '2026-11-07', $s['param_2'] => '2026-11-15' ), $s['base_url'] ?: home_url( '/' ) ) ); ?></code></p></td></tr>
				<tr><th scope="row"><label for="kcal-m">Месяцев вперёд</label></th><td><input id="kcal-m" type="number" min="1" max="24" class="small-text" name="<?php echo esc_attr( $n ); ?>[months]" value="<?php echo (int) $s['months']; ?>"></td></tr>
				<tr><th scope="row"><label for="kcal-n">Ночные концерты</label></th><td>до <input id="kcal-n" type="number" min="0" max="12" class="small-text" name="<?php echo esc_attr( $n ); ?>[night_h]" value="<?php echo (int) $s['night_h']; ?>"> часов считаются концертами предыдущего дня</td></tr>
			</table>
			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}

add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	function ( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=kozlov-calendar' ) ) . '">Настройки</a>' );
		return $links;
	}
);
