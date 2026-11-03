<?php
/** Календарь афиши: модальное окно с месяцем, дни с концертами ведут на /?date=YYYY-MM-DD. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_ajax_kz_calendar', 'kz_calendar_ajax' );
add_action( 'wp_ajax_nopriv_kz_calendar', 'kz_calendar_ajax' );

function kz_calendar_ajax() {
	$month = max( 1, min( 12, (int) ( $_GET['month'] ?? wp_date( 'n' ) ) ) );
	$year  = max( 2015, min( 2035, (int) ( $_GET['year'] ?? wp_date( 'Y' ) ) ) );
	$html  = kz_calendar_html( $month, $year );
	wp_send_json( $html );
}

function kz_calendar_html( $month, $year ) {
	$key  = 'kz_cal_' . $year . '_' . $month;
	$days = get_transient( $key );
	if ( false === $days ) {
		$from = sprintf( '%04d-%02d-01', $year, $month );
		$to   = wp_date( 'Y-m-t', strtotime( $from ) );
		$days = array();
		foreach ( kozlov_concerts( array( 'from' => $from, 'to' => $to, 'past' => true, 'limit' => 1000, 'ids_only' => false ) ) as $c ) {
			$days[ (int) substr( $c['date'], 8, 2 ) ] = ( $days[ (int) substr( $c['date'], 8, 2 ) ] ?? 0 ) + 1;
		}
		set_transient( $key, $days, 10 * MINUTE_IN_SECONDS );
	}
	$prev = $month === 1 ? array( 12, $year - 1 ) : array( $month - 1, $year );
	$next = $month === 12 ? array( 1, $year + 1 ) : array( $month + 1, $year );
	$names = array( 'Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь' );
	$out  = '<div class="datepicker_nav"><span class="datepicker_nav-item" data-set-month="' . $prev[0] . '" data-set-year="' . $prev[1] . '">&larr;</span><span>' . $names[ $month - 1 ] . ' ' . $year . '</span><span class="datepicker_nav-item" data-set-month="' . $next[0] . '" data-set-year="' . $next[1] . '">&rarr;</span></div>';
	$out .= '<div class="datepicker_calendar">';
	foreach ( array( 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс' ) as $d ) {
		$out .= '<div class="datepicker_calendar-item is-head">' . $d . '</div>';
	}
	$first_dow = (int) wp_date( 'N', strtotime( sprintf( '%04d-%02d-01', $year, $month ) ) );
	for ( $i = 1; $i < $first_dow; $i++ ) {
		$out .= '<div class="datepicker_calendar-item"></div>';
	}
	$dim   = (int) wp_date( 't', strtotime( sprintf( '%04d-%02d-01', $year, $month ) ) );
	$today = wp_date( 'Y-m-d' );
	for ( $d = 1; $d <= $dim; $d++ ) {
		$date = sprintf( '%04d-%02d-%02d', $year, $month, $d );
		$cls  = 'datepicker_calendar-item' . ( isset( $days[ $d ] ) ? ' has-event' : '' ) . ( $date === $today ? ' is-today' : '' );
		$out .= '<div class="' . $cls . '">' . ( isset( $days[ $d ] ) ? '<a href="' . esc_url( home_url( '/?date=' . $date ) ) . '">' . $d . '</a>' : $d ) . '</div>';
	}
	return $out . '</div>';
}
