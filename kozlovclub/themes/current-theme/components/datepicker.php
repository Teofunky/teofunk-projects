<?php

$month = $_GET['month'];
$year = $_GET['year'];
$language = $_GET['language'];


$result = dates_month($month, $year, $language);
echo json_encode($result);


function dates_month ($month, $year, $language) {

	if ($language == 'ru') {
		$month_array = [ "Январь", "Февраль", "Март", "Апрель", "Май", "Июнь", "Июль", "Август", "Сентябрь", "Октябрь", "Ноябрь", "Декабрь" ];
	} else if ($language == 'en') {
		$month_array = [ "January", "February", "March", "April", "May", "June", "july", "August", "September", "October", "November", "December" ];
	}

	$prev_month = $month - 1;
	if ($prev_month == 0) {
		$prev_month = 12;
		$prev_year = $year - 1;
	} else {
		$prev_year = $year;
	}

	$next_month = $month + 1;
	if ($next_month == 13) {
		$next_month = 1;
		$next_year = $year + 1;
	} else {
		$next_year = $year;
	}

	$result = '';

	$result .= '<div class="datepicker_nav">';
	$result .= '<div class="datepicker_nav-item prev" data-set-month="' . $prev_month . '" data-set-year="' . $prev_year . '"><span>&#10140;</span></div>';
	$result .= '<div class="current">' . $month_array[$month - 1] . ' ' . $year . '</div>';
	$result .= '<div class="datepicker_nav-item next" data-set-month="' . $next_month . '" data-set-year="' . $next_year . '"><span>&#10140;</span></div>';
	$result .= '</div>';

	$result .= '<div class="datepicker_calendar"><table>';
	if ($language == 'ru') {
		$result .= '<tr><td>Пн</td><td>Вт</td><td>Ср</td><td>Чт</td><td>Пт</td><td>Сб</td><td>Вс</td></tr>';
	} else if ($language == 'en') {
		$result .= '<tr><td>Mon</td><td>Tue</td><td>Wed</td><td>Thu</td><td>Fri</td><td>Sat</td><td>Sun</td></tr>';
	}

	// $num = cal_days_in_month(CAL_GREGORIAN, $month, $year);

	function days_in_month($month, $year) {
		// calculate number of days in a month
		return $month == 2 ? ($year % 4 ? 28 : ($year % 100 ? 29 : ($year % 400 ? 28 : 29))) : (($month - 1) % 7 % 2 ? 30 : 31);
	}

	$num = days_in_month($month, $year);

	// echo $num1;

	$dates_month = array();

	for ($i = 1; $i <= $num; $i++) {
		$mktime = mktime(0, 0, 0, $month, $i, $year);
		$date = date("j", $mktime);
		$dates_month[$i] = $date;
	}

	$deltaDay = 2 - date("N", mktime(0, 0, 0, $month, 1, $year));

	$j = 1;

	for ($i = $deltaDay; $i <= $num; $i++) {

		$mktime = mktime(0, 0, 0, $month, $i, $year);
		$date = date("d-M-Y l, N", $mktime);

		if ($j == 1) $result .= '<tr>';

		$result .= '<td>';
		if ($dates_month[$i]) {
			$active = '';
			if (date('d-m-Y', $mktime) == date('d-m-Y')) {
				$active = 'active';
			}

			if ($language == 'ru') {
				$_link = '/?date1=' . date('Y-m-d', $mktime);
			} else if ($language == 'en') {
				$_link = '/en/?date1=' . date('Y-m-d', $mktime);
			}

			$result .= '<a href="' . $_link . '" data-val="' . date('d.m.Y', $mktime) . '" class="datepicker_calendar-item ' . $active . '">' . $dates_month[$i] . '</a>';
		}
		$result .= '</td>';

		if ($j == 7) {
			$result .= '</tr>';
			$j = 1;
		} else {
			$j++;
		}

	}

	$result .= '</table></div>';

	return $result;

}
