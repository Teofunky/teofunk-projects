<?php
/**
 * Календарь афиши: окно в шапке с выбором дня или диапазона дат.
 * Данные: число концертов по дням (JSON), рисует js/calendar.js. Выбор ведёт на /?date=…&date2=….
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_ajax_kz_calendar_days', 'kz_calendar_days_ajax' );
add_action( 'wp_ajax_nopriv_kz_calendar_days', 'kz_calendar_days_ajax' );
add_action( 'save_post_concert', fn() => delete_transient( 'kz_cal_days' ) );
add_action( 'deleted_post', fn() => delete_transient( 'kz_cal_days' ) );

/** Число опубликованных концертов по датам: [ 'ГГГГ-ММ-ДД' => n ]. Кэш 10 минут. */
function kz_calendar_days() {
	$days = get_transient( 'kz_cal_days' );
	if ( false !== $days ) {
		return $days;
	}
	global $wpdb;
	$rows = $wpdb->get_results( "SELECT SUBSTR(m.meta_value, 1, 10) AS d, COUNT(*) AS n FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = 'kc_start' AND p.post_type = 'concert' AND p.post_status = 'publish' AND p.post_password = '' GROUP BY d" ); // phpcs:ignore
	$days = array();
	foreach ( (array) $rows as $r ) {
		if ( preg_match( '/^\d{4}-\d\d-\d\d$/', (string) $r->d ) ) {
			$days[ $r->d ] = (int) $r->n;
		}
	}
	ksort( $days );
	set_transient( 'kz_cal_days', $days, 10 * MINUTE_IN_SECONDS );
	return $days;
}

function kz_calendar_days_ajax() {
	$days = kz_calendar_days();
	nocache_headers();
	header( 'Cache-Control: public, max-age=300' );
	wp_send_json( array(
		'days'  => $days,
		'today' => wp_date( 'Y-m-d' ),
		'home'  => home_url( '/' ),
	) );
}

/** Разметка окна календаря (скелет; месяцы рисует скрипт). */
function kz_calendar_modal() {
	?>
	<div class="kz-cal" id="kz-cal" hidden aria-hidden="true">
		<div class="kz-cal__scrim" data-cal="close"></div>
		<div class="kz-cal__panel" role="dialog" aria-modal="true" aria-label="Календарь концертов">
			<div class="kz-cal__head">
				<div class="kz-cal__title">Календарь концертов</div>
				<button type="button" class="kz-cal__x" data-cal="close" aria-label="Закрыть">&times;</button>
			</div>
			<div class="kz-cal__quick">
				<button type="button" class="kz-cal__chip" data-quick="today">Сегодня</button>
				<button type="button" class="kz-cal__chip" data-quick="tomorrow">Завтра</button>
				<button type="button" class="kz-cal__chip" data-quick="weekend">Выходные</button>
				<button type="button" class="kz-cal__chip" data-quick="week">7 дней</button>
				<button type="button" class="kz-cal__chip" data-quick="month">Месяц</button>
			</div>
			<div class="kz-cal__nav">
				<button type="button" class="kz-cal__arrow" data-cal="prev" aria-label="Предыдущий месяц"></button>
				<div class="kz-cal__months"></div>
				<button type="button" class="kz-cal__arrow kz-cal__arrow--next" data-cal="next" aria-label="Следующий месяц"></button>
			</div>
			<div class="kz-cal__hint">Нажмите на день или выберите две даты, чтобы задать период</div>
			<div class="kz-cal__foot">
				<div class="kz-cal__sel"><span class="kz-cal__sel-text">Дата не выбрана</span><button type="button" class="kz-cal__reset" data-cal="reset" hidden>Сбросить</button></div>
				<a class="kz-cal__go is-disabled" href="#" data-cal="go">Выберите дату</a>
			</div>
		</div>
	</div>
	<?php
}
