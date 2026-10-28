<?php
/**
 * Админка: «Джаз-навигатор» в меню, две страницы: статистика и настройки.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KNAV_Admin {
	private static $labels = array(
		'moods'       => array( 'drive' => 'Драйв и танцы', 'romance' => 'Романтика', 'calm' => 'Уютно и спокойно', 'hits' => 'Знакомые хиты', 'deep' => 'Глубоко и необычно', 'surprise' => 'Удивите меня' ),
		'when'        => array( 'today' => 'Сегодня', 'tomorrow' => 'Завтра', 'weekend' => 'Выходные', 'week' => 'На неделе', 'any' => 'Любой день', 'date' => 'Конкретная дата' ),
		'times'       => array( 'day' => 'Днём', 'evening' => 'Вечером', 'night' => 'Поздно' ),
		'formats'     => array( 'bigband' => 'Большой оркестр', 'jam' => 'Джем-сейшн', 'vocalnight' => 'Вокальный вечер' ),
		'instruments' => array( 'vocal' => 'Вокал', 'sax' => 'Саксофон', 'piano' => 'Фортепиано', 'guitar' => 'Гитара', 'trumpet' => 'Труба', 'bass' => 'Контрабас', 'drums' => 'Ударные', 'violin' => 'Скрипка', 'trombone' => 'Тромбон', 'clarinet' => 'Кларнет', 'flute' => 'Флейта', 'percussion' => 'Перкуссия', 'accordion' => 'Аккордеон', 'cello' => 'Виолончель', 'mallets' => 'Маримба, вибрафон', 'organ' => 'Орган Хаммонд' ),
		'experience'  => array( 'new' => 'Впервые', 'regular' => 'Иногда', 'expert' => 'Часто' ),
		'company'     => array( 'solo' => 'Один', 'pair' => 'Вдвоём', 'group' => 'Компанией' ),
		'dismissed'   => array( 'strip' => 'Скрыли строку у фильтров', 'launcher' => 'Скрыли плавающую кнопку', 'strip_undo' => 'Вернули строку у фильтров', 'launcher_undo' => 'Вернули кнопку' ),
		'sources'     => array( 'chip' => 'Кнопка в строке фильтров', 'strip' => 'Строка под фильтрами (телефон)', 'launcher_home' => 'Кнопка на главной', 'launcher_other' => 'Кнопка на остальных страницах', 'link' => 'Ссылка #navigator', 'auto' => 'Открылся сам' ),
	);

	public static function label( $group, $key ) {
		return isset( self::$labels[ $group ][ $key ] ) ? self::$labels[ $group ][ $key ] : $key;
	}

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'update_option_' . KNAV_Settings::OPTION, array( __CLASS__, 'flush_cache' ) );
	}

	public static function menu() {
		add_menu_page( 'Джаз-навигатор', 'Джаз-навигатор', 'manage_options', 'kozlov-navigator', array( __CLASS__, 'stats_page' ), 'dashicons-format-audio', 58 );
		add_submenu_page( 'kozlov-navigator', 'Статистика навигатора', 'Статистика', 'manage_options', 'kozlov-navigator', array( __CLASS__, 'stats_page' ) );
		add_submenu_page( 'kozlov-navigator', 'Настройки навигатора', 'Настройки', 'manage_options', 'kozlov-navigator-settings', array( __CLASS__, 'settings_page' ) );
	}

	public static function register() {
		register_setting( 'knav', KNAV_Settings::OPTION, array( 'sanitize_callback' => array( 'KNAV_Settings', 'sanitize' ) ) );
	}

	/** После сохранения настроек сбрасываем кэш страниц, иначе посетители увидят старую версию. */
	public static function flush_cache() {
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
		}
	}

	private static function kss_ok() {
		$routes = rest_get_server()->get_routes();
		return isset( $routes['/kozlov-search/v1/index'] );
	}

	private static function css() {
		echo '<style>
		.knav-wrap{max-width:1100px}
		.knav-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin:16px 0}
		.knav-card{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:14px 16px}
		.knav-card b{display:block;font-size:26px;line-height:1.1;font-variant-numeric:tabular-nums}
		.knav-card span{color:#646970;font-size:12px}
		.knav-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px}
		.knav-box{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:14px 16px}
		.knav-box h3{margin:0 0 10px;font-size:14px}
		.knav-row{display:grid;grid-template-columns:minmax(0,1fr) 46px;gap:8px;align-items:center;margin:5px 0;font-size:13px}
		.knav-bar{position:relative;background:#f0f0f1;border-radius:4px;overflow:hidden;min-height:22px;display:flex;align-items:center}
		.knav-bar i{position:absolute;inset:0 auto 0 0;background:#fde0de}
		.knav-bar em{position:relative;font-style:normal;padding:2px 8px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
		.knav-row b{text-align:right;font-variant-numeric:tabular-nums}
		.knav-note{background:#fff8e5;border-left:4px solid #dba617;padding:8px 12px;margin:12px 0}
		.knav-ok{background:#edfaef;border-left:4px solid #00a32a;padding:8px 12px;margin:12px 0}
		.knav-days{display:flex;align-items:flex-end;gap:2px;height:80px;margin-top:8px}
		.knav-days i{flex:1;background:#f34841;border-radius:2px 2px 0 0;min-height:1px}
		</style>';
	}

	private static function bars( $title, $items, $group = '', $limit = 10 ) {
		echo '<div class="knav-box"><h3>' . esc_html( $title ) . '</h3>';
		if ( empty( $items ) ) {
			echo '<p style="color:#646970">Пока нет данных.</p></div>';
			return;
		}
		$max = 1;
		foreach ( $items as $v ) {
			$max = max( $max, $v['n'] );
		}
		$i = 0;
		foreach ( $items as $key => $v ) {
			if ( ++$i > $limit ) {
				break;
			}
			$name = $v['t'] ? $v['t'] : ( $group ? self::label( $group, $key ) : $key );
			echo '<div class="knav-row"><div class="knav-bar"><i style="width:' . esc_attr( round( 100 * $v['n'] / $max ) ) . '%"></i><em title="' . esc_attr( $name ) . '">' . esc_html( $name ) . '</em></div><b>' . (int) $v['n'] . '</b></div>';
		}
		echo '</div>';
	}

	public static function stats_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$days = isset( $_GET['days'] ) ? (int) $_GET['days'] : 30; // phpcs:ignore
		$days = in_array( $days, array( 7, 30, 90, 365 ), true ) ? $days : 30;
		$r    = KNAV_Stats::report( $days );
		$f    = $r['funnel'];
		$s    = KNAV_Settings::get();
		self::css();
		echo '<div class="wrap knav-wrap"><h1>Джаз-навигатор: статистика</h1>';
		if ( empty( $s['enabled'] ) ) {
			echo '<div class="knav-note">Навигатор выключен в настройках.</div>';
		} elseif ( 'all' !== $s['audience'] ) {
			echo '<div class="knav-note">Режим проверки: навигатор видят только администраторы. Когда всё проверено, включите «Все посетители» в настройках.</div>';
		}
		if ( ! self::kss_ok() ) {
			echo '<div class="knav-note">Не найден индекс Kozlov Smart Search (/wp-json/kozlov-search/v1/index). Без него навигатор не сможет загрузить афишу.</div>';
		}
		echo '<p>';
		foreach ( array( 7 => '7 дней', 30 => '30 дней', 90 => '90 дней', 365 => 'Год' ) as $d => $label ) {
			$url = admin_url( 'admin.php?page=kozlov-navigator&days=' . $d );
			echo $d === $days ? '<strong>' . esc_html( $label ) . '</strong> &nbsp; ' : '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a> &nbsp; ';
		}
		echo '</p>';

		$get   = function ( $k ) use ( $f ) { return isset( $f[ $k ] ) ? (int) $f[ $k ] : 0; };
		$open  = $get( 'open' );
		$res   = $get( 'results' );
		$buy   = $get( 'buy_click' ) + $get( 'table_click' );
		$pct   = function ( $a, $b ) { return $b ? round( 100 * $a / $b ) . '%' : '—'; };
		echo '<div class="knav-cards">';
		echo '<div class="knav-card"><b>' . $open . '</b><span>открыли навигатор</span></div>';
		echo '<div class="knav-card"><b>' . $res . '</b><span>получили подборку · ' . esc_html( $pct( $res, $open ) ) . '</span></div>';
		echo '<div class="knav-card"><b>' . $get( 'buy_click' ) . '</b><span>нажали «Купить» · ' . esc_html( $pct( $get( 'buy_click' ), $res ) ) . ' от подборок</span></div>';
		echo '<div class="knav-card"><b>' . $get( 'table_click' ) . '</b><span>хотели стол</span></div>';
		echo '<div class="knav-card"><b>' . $get( 'more' ) . '</b><span>смотрели ещё варианты</span></div>';
		echo '</div>';

		echo '<div class="knav-grid">';
		$funnel = array();
		foreach ( array( 'open' => 'Открыли', 'step_1' => 'Шаг 1: настроение', 'step_2' => 'Шаг 2: когда', 'step_3' => 'Шаг 3: звучание', 'step_4' => 'Шаг 4: детали', 'results' => 'Подборка', 'buy_click' => 'Купить', 'table_click' => 'Стол' ) as $k => $name ) {
			$funnel[ $k ] = array( 'n' => $get( $k ), 't' => $name );
		}
		self::bars( 'Воронка (уникальные сессии)', $funnel, '', 20 );

		echo '<div class="knav-box"><h3>По дням: открыли навигатор</h3>';
		if ( empty( $r['daily'] ) ) {
			echo '<p style="color:#646970">Пока нет данных.</p>';
		} else {
			$mx = 1;
			foreach ( $r['daily'] as $v ) {
				$mx = max( $mx, isset( $v['open'] ) ? $v['open'] : 0 );
			}
			echo '<div class="knav-days">';
			foreach ( $r['daily'] as $d => $v ) {
				$o = isset( $v['open'] ) ? $v['open'] : 0;
				$t = $d . ': открыли ' . $o . ', подборок ' . ( isset( $v['results'] ) ? $v['results'] : 0 ) . ', «купить» ' . ( isset( $v['buy_click'] ) ? $v['buy_click'] : 0 );
				echo '<i style="height:' . esc_attr( round( 100 * $o / $mx ) ) . '%" title="' . esc_attr( $t ) . '"></i>';
			}
			echo '</div>';
		}
		echo '</div>';

		self::bars( 'Откуда открывают', $r['sources'], 'sources' );
		if ( ! empty( $r['dismissed'] ) ) {
			self::bars( 'Скрыли крестиком', $r['dismissed'], 'dismissed' );
		}
		if ( ! empty( $r['pages'] ) ) {
			self::bars( 'Остальные страницы, где открывают чаще', $r['pages'] );
		}
		$a = $r['answers'];
		self::bars( 'Настроение', isset( $a['moods'] ) ? $a['moods'] : array(), 'moods' );
		self::bars( 'Когда', isset( $a['when'] ) ? $a['when'] : array(), 'when' );
		self::bars( 'Время суток', isset( $a['times'] ) ? $a['times'] : array(), 'times' );
		self::bars( 'Инструменты', isset( $a['instruments'] ) ? $a['instruments'] : array(), 'instruments' );
		self::bars( 'Форматы', isset( $a['formats'] ) ? $a['formats'] : array(), 'formats' );
		self::bars( 'Опыт', isset( $a['experience'] ) ? $a['experience'] : array(), 'experience' );
		self::bars( 'С кем идут', isset( $a['company'] ) ? $a['company'] : array(), 'company' );
		self::bars( 'Бюджет', isset( $a['budget'] ) ? $a['budget'] : array() );
		self::bars( 'Какие концерты навигатор советует чаще', $r['picks'] );
		self::bars( 'На какие нажимают «Купить»', $r['buys'] );
		self::bars( 'Где хотят стол', $r['tables'] );
		self::bars( 'Спрос без предложения: запросы, под которые точных концертов не нашлось', $r['unmet'], '', 15 );
		echo '</div>';

		echo '<p style="margin-top:18px;color:#646970">Покупки считает Яндекс Метрика: окно Ticketscloud передаёт оплату в счётчик ' . esc_html( $s['ym'] ) . '. Чтобы увидеть выручку от навигатора, откройте в Метрике отчёт «Конверсии» с сегментом «визиты с целью nav_results» и смотрите цели «Получение оплаты» и «Ecommerce: покупка».</p>';
		echo '</div>';
	}

	public static function settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s  = KNAV_Settings::get();
		$on = function ( $k ) use ( $s ) { return ! empty( $s[ $k ] ) ? ' checked' : ''; };
		$o  = KNAV_Settings::OPTION;
		echo '<div class="wrap"><h1>Джаз-навигатор: настройки</h1>';
		if ( 'all' !== $s['audience'] ) {
			echo '<div class="notice notice-warning inline"><p>Сейчас режим проверки: навигатор видят только администраторы. Посетители видят сайт как раньше, со старым виджетом.</p></div>';
		}
		echo '<form method="post" action="options.php">';
		settings_fields( 'knav' );
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th scope="row">Навигатор</th><td><label><input type="checkbox" name="' . $o . '[enabled]" value="1"' . $on( 'enabled' ) . '> Включён</label><p class="description">Общий выключатель. Выключили: сайт сразу работает как до установки.</p></td></tr>';
		echo '<tr><th scope="row">Кто видит</th><td><label><input type="radio" name="' . $o . '[audience]" value="admins"' . ( 'admins' === $s['audience'] ? ' checked' : '' ) . '> Только администраторы (проверка)</label><br><label><input type="radio" name="' . $o . '[audience]" value="all"' . ( 'all' === $s['audience'] ? ' checked' : '' ) . '> Все посетители</label></td></tr>';
		echo '<tr><th scope="row">Главная (афиша)</th><td><label><input type="checkbox" name="' . $o . '[strip]" value="1"' . $on( 'strip' ) . '> Вход у фильтров: на компьютере кнопка «Подобрать концерт» в строке фильтров после «Сцена», на телефоне строка «Не знаете, что выбрать?» под панелью</label><br><label><input type="checkbox" name="' . $o . '[launcher]" value="1"' . $on( 'launcher' ) . '> Плавающая кнопка «Подобрать концерт»</label><p class="description">Кнопка появляется, когда полосу пролистали, и прячется, пока листают вниз по афише, чтобы не закрывать «Купить билет».</p></td></tr>';
		echo '<tr><th scope="row">Крестик для посетителей</th><td><label><input type="checkbox" name="' . $o . '[dismiss]" value="1"' . $on( 'dismiss' ) . '> Посетитель может скрыть плашку и плавающую кнопку крестиком</label><br><label>Помнить <input type="number" min="1" max="365" name="' . $o . '[dismiss_days]" value="' . (int) $s['dismiss_days'] . '" style="width:70px"> дней, потом снова показать</label><p class="description">Выбор хранится в браузере посетителя и действует на всём сайте. После нажатия на крестик внизу на 6 секунд появляется «Вернуть», на случай случайного нажатия. Увидеть скрытое у себя снова: открыть любую страницу с <code>?knav_show=1</code> в конце адреса.</p></td></tr>';
		echo '<tr><th scope="row">Остальные страницы</th><td><label><input type="radio" name="' . $o . '[others]" value="off"' . ( 'off' === $s['others'] ? ' checked' : '' ) . '> Не показывать навигатор</label><br><label><input type="radio" name="' . $o . '[others]" value="button"' . ( 'button' === $s['others'] ? ' checked' : '' ) . '> Только плавающая кнопка: появляется при прокрутке страницы и остаётся, как у прежнего виджета</label><p class="description">FAQ, контакты, страницы концертов и все другие, кроме главной. Полосы там нет. Ещё один вход на любой странице: ссылка с адресом <code>#navigator</code> открывает навигатор, если он на этой странице включён.</p></td></tr>';
		echo '<tr><th scope="row"><label for="knav-sa">Полоса после блока</label></th><td><input id="knav-sa" class="regular-text" type="text" name="' . $o . '[strip_after]" value="' . esc_attr( $s['strip_after'] ) . '"><p class="description">Селектор панели фильтров. По умолчанию <code>#kaf-filters</code>. Если блока на странице нет, полоса не появляется.</p></td></tr>';
		echo '<tr><th scope="row">Старый виджет</th><td><label><input type="checkbox" name="' . $o . '[replace_old]" value="1"' . $on( 'replace_old' ) . '> Отключать старый виджет там, где показан новый</label><br><label><input type="checkbox" name="' . $o . '[old_everywhere]" value="1"' . $on( 'old_everywhere' ) . '> И не загружать его на остальных страницах, даже если навигатора там нет</label><br><input class="regular-text" type="text" name="' . $o . '[old_handle]" value="' . esc_attr( $s['old_handle'] ) . '"><p class="description">Имя скрипта старого виджета. Сам файл и тема не меняются.</p></td></tr>';
		echo '<tr><th scope="row"><label for="knav-ym">Счётчик Метрики</label></th><td><input id="knav-ym" type="text" name="' . $o . '[ym]" value="' . esc_attr( $s['ym'] ) . '"><p class="description">Цели: nav_open, nav_results, nav_buy_click, nav_table_click (тип «JavaScript-событие»).</p></td></tr>';
		echo '<tr><th scope="row"><label for="knav-ph">Телефон для столов</label></th><td><input id="knav-ph" class="regular-text" type="text" name="' . $o . '[phone]" value="' . esc_attr( $s['phone'] ) . '"></td></tr>';
		echo '<tr><th scope="row">Статистика</th><td><label><input type="checkbox" name="' . $o . '[stats]" value="1"' . $on( 'stats' ) . '> Собирать анонимную статистику для страницы «Статистика»</label><br><label>Хранить <input type="number" min="30" max="1000" name="' . $o . '[keep_days]" value="' . (int) $s['keep_days'] . '" style="width:80px"> дней</label></td></tr>';
		echo '</table>';
		submit_button( 'Сохранить' );
		echo '</form><p class="description">После сохранения кэш страниц W3 Total Cache сбрасывается автоматически.</p></div>';
	}
}
