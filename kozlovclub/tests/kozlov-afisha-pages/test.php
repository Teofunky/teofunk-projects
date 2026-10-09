<?php
// Проверки Kozlov Afisha Pages. Запуск: wp eval-file test.php (после seed.php).
$ids  = json_decode( file_get_contents( __DIR__ . '/ids.json' ), true );
$GLOBALS['af_name'] = array_flip( $ids );
$GLOBALS['af_fail'] = 0;
$GLOBALS['af_pass'] = 0;

function af_ok( $cond, $label ) {
	if ( $cond ) {
		$GLOBALS['af_pass']++;
	} else {
		$GLOBALS['af_fail']++;
		echo "FAIL: $label\n";
	}
}

function af_keys( $result ) {
	$name = $GLOBALS['af_name'];
	$out  = array();
	foreach ( $result['items'] as $item ) {
		$out[] = isset( $name[ $item['id'] ] ) ? $name[ $item['id'] ] : '?' . $item['id'];
	}
	return $out;
}

function af_at( $wall ) {
	$d = new DateTime( $wall, new DateTimeZone( 'Europe/Moscow' ) );
	return $d->getTimestamp();
}

function af_sel( $atts, $wall ) {
	return Kozlov_Afisha_Data::select( Kozlov_Afisha_Data::normalize_args( $atts ), af_at( $wall ) );
}

function af_eq( $atts, $wall, $expected, $label ) {
	$got = af_keys( af_sel( $atts, $wall ) );
	af_ok( $got === $expected, $label . ' => ' . implode( ',', $got ) . ' (ждали ' . implode( ',', $expected ) . ')' );
}

$fri = '2026-10-09 18:30';

// Выборки.
af_eq( array(), $fri, array( 'T1', 'T3', 'W1', 'W2', 'M1', 'FE', 'KD', 'S0', 'S1', 'XS', 'MS', 'YO', 'NI', 'LT', 'N1' ), 'upcoming по умолчанию' );
af_eq( array( 'genre' => 'jazz', 'days' => '400' ), $fri, array( 'T1', 'W1', 'FE', 'LT', 'J1', 'FAR' ), 'jazz 400 дней' );
af_eq( array( 'genre' => 'Джаз', 'days' => '400' ), $fri, array( 'T1', 'W1', 'FE', 'LT', 'J1', 'FAR' ), 'Джаз по-русски' );
af_eq( array( 'genre' => ' JAZZ ', 'days' => '400', 'limit' => '2' ), $fri, array( 'T1', 'W1' ), 'регистр, пробелы, limit' );
af_eq( array( 'genre' => 'jazz rock' ), $fri, array( 'W2' ), 'jazz rock = Jazz-rock' );
af_eq( array( 'genre' => 'Джаз-рок' ), $fri, array( 'W2' ), 'Джаз-рок' );
af_eq( array( 'genre' => 'for kids' ), $fri, array( 'KD' ), 'for kids с пробелом в начале значения' );
af_eq( array( 'genre' => 'Детям' ), $fri, array( 'KD' ), 'Детям' );
af_eq( array( 'genre' => 'soul' ), $fri, array( 'W1', 'NI' ), 'soul (в т.ч. жанры строками)' );
af_eq( array( 'genre' => 'funk,blues' ), $fri, array( 'M1', 'NI', 'N1' ), 'funk или blues' );
af_eq( array( 'genre' => 'ежик' ), $fri, array(), 'нет такого жанра' );
af_eq( array( 'genre' => 'этно-джаз' ), $fri, array( 'YO' ), 'этно-джаз не путается с джазом' );
af_eq( array( 'free' => '1' ), $fri, array( 'W2', 'FE', 'S1' ), 'free' );
af_eq( array( 'stage' => 'the_beat' ), $fri, array( 'T3', 'YO', 'NI', 'LT', 'N1' ), 'the_beat' );
af_eq( array( 'stage' => 'main' ), $fri, array( 'W1', 'M1', 'MS' ), 'main' );
af_eq( array( 'stage' => 'myasnitskaya,mansarda', 'days' => '10' ), $fri, array( 'T1', 'W2', 'KD' ), 'две сцены, 10 дней' );
af_eq( array( 'stage' => 'nonsense' ), $fri, array( 'T1', 'T3', 'W1', 'W2', 'M1', 'FE', 'KD', 'S0', 'S1', 'XS', 'MS', 'YO', 'NI', 'LT', 'N1' ), 'неизвестная сцена = без фильтра' );
af_eq( array( 'when' => 'today' ), $fri, array( 'T1', 'T3' ), 'today пятница' );
af_eq( array( 'when' => 'weekend' ), $fri, array( 'T1', 'T3', 'W1', 'W2' ), 'weekend пятница' );
af_eq( array( 'when' => 'weekend' ), '2026-10-14 12:00', array( 'FE', 'KD' ), 'weekend среда' );
af_eq( array( 'when' => 'weekend' ), '2026-10-10 23:30', array( 'W2' ), 'weekend суббота поздно' );
af_eq( array( 'when' => 'today' ), '2026-10-09 23:00', array( 'T3' ), 'today 23:00: T1 закончился, T3 идёт' );
af_eq( array( 'when' => 'today' ), '2026-10-10 00:10', array( 'W1' ), 'today после полуночи (T3 вчерашний не показываем)' );
af_eq( array( 'when' => 'nonsense', 'days' => '3' ), $fri, array( 'T1', 'T3', 'W1', 'W2', 'M1' ), 'неизвестный when = upcoming' );

$r = af_sel( array( 'when' => 'today' ), '2026-10-11 23:00' );
af_ok( af_keys( $r ) === array( 'M1' ) && '2026-10-12' === $r['fallback'], 'today fallback на понедельник' );
$r = af_sel( array( 'when' => 'weekend' ), '2026-10-11 23:00' );
af_ok( af_keys( $r ) === array( 'FE', 'KD' ) && '2026-10-16' === $r['fallback'], 'weekend fallback на следующие выходные' );
$r = af_sel( array( 'when' => 'today', 'genre' => 'blues' ), $fri );
af_ok( af_keys( $r ) === array( 'M1' ) && '2026-10-12' === $r['fallback'], 'today blues fallback' );
$r = af_sel( array( 'limit' => '3' ), $fri );
af_ok( 3 === count( $r['items'] ) && 15 === $r['total'], 'limit и total' );
$r = af_sel( array( 'when' => 'today', 'genre' => 'ежик' ), $fri );
af_ok( array() === $r['items'] && '' === $r['fallback'], 'today пусто без fallback' );

// Параметры.
$a = Kozlov_Afisha_Data::normalize_args( array( 'days' => '-5', 'limit' => '9999', 'when' => 'TODAY', 'free' => 'да', 'more' => 'нет', 'empty' => '<b>Пусто</b>' ) );
af_ok( 60 === $a['days'] && 200 === $a['limit'] && 'today' === $a['when'] && true === $a['free'] && false === $a['more'] && 'Пусто' === $a['empty'] && 'day' === $a['group'], 'normalize_args границы' );

// Рендер.
$html = Kozlov_Afisha_Render::render( Kozlov_Afisha_Data::normalize_args( array() ), af_at( $fri ) );
af_ok( false === stripos( $html, '<script' ), 'нет <script> в выводе' );
af_ok( false !== strpos( $html, 'ОПАСНО &lt;script&gt;' ) || false !== strpos( $html, 'ОПАСНО alert(1)' ) || false !== strpos( $html, 'ОПАСНО  &amp;' ) || false !== strpos( $html, 'ОПАСНО &amp;' ), 'заголовок с тегами экранирован' );
af_ok( substr_count( $html, 'class="event ' ) === 15, 'карточек 15' );
af_ok( false !== strpos( $html, '<h2 class="event_month"><span>Концерты в октябре</span></h2>' ), 'заголовок месяца октябрь' );
af_ok( false !== strpos( $html, '<span>Концерты в ноябре</span>' ), 'заголовок месяца ноябрь' );
af_ok( 1 === substr_count( $html, 'tc-event="6a9ad87181b34b2f0c254d99"' ), 'ID Ticketscloud из ссылки' );
af_ok( false === strpos( $html, 'alert(2)' ), 'плохой ID Ticketscloud не выведен' );
af_ok( false !== strpos( $html, 'Концерт сегодня | <span>Сцена на Мясницкой, 15</span>' ), 'сегодня + сцена' );
af_ok( false !== strpos( $html, 'today_1  ' ) || false !== strpos( $html, 'today_1 mansarda_0' ), 'класс today_1' );
af_ok( false !== strpos( $html, 'Окт | 19:30 | Пятница' ), 'пояс Москва 19:30' );
af_ok( false !== strpos( $html, 'Окт | Суббота</div>' ), 'весь день без времени' );
af_ok( false !== strpos( $html, '<div class="cell cell_1">09</div>' ), 'день с нулём' );
af_ok( false !== strpos( $html, 'от 700 руб.</div>' ) && false === strpos( $html, '<b>от 700' ), 'цена очищена от тегов' );
af_ok( false !== strpos( $html, 'ДЕПОЗИТ 2 000 РУБ.' ) && false === strpos( $html, 'DEPOSIT' ), 'цена: русская часть' );
af_ok( false !== strpos( $html, 'Вход свободный</div>' ), 'бесплатно из сериализованной цены' );
af_ok( false === strpos( $html, 'a:2:{' ), 'нет сырого сериализованного текста' );
af_ok( false !== strpos( $html, 'shortened' ), 'длинный заголовок shortened' );
af_ok( false !== strpos( $html, 'href="' . esc_url( get_permalink( $ids['T3'] ) ) . '">подробнее</a>' ), 'без билетов: подробнее' );
af_ok( false !== strpos( $html, 'Вся афиша клуба' ), 'ссылка на всю афишу' );
af_ok( false === strpos( $html, '?sort=' ), 'нет ссылок на ?sort=' );

$dom = new DOMDocument();
libxml_use_internal_errors( true );
$dom->loadHTML( '<?xml encoding="UTF-8"><body>' . $html . '</body>' );
$errors = array_filter(
	libxml_get_errors(),
	function ( $e ) {
		return false === strpos( $e->message, 'Tag h2' ) && false === strpos( $e->message, 'invalid in Entity' );
	}
);
libxml_clear_errors();
$xp = new DOMXPath( $dom );
af_ok( 15 === $xp->query( '//div[contains(concat(" ",normalize-space(@class)," ")," event ")]' )->length, 'DOM: 15 карточек' );
af_ok( 14 === $xp->query( '//div[@class="thumbnail"]/a/img' )->length, 'DOM: 14 картинок (у одного нет)' );
af_ok( 0 === $xp->query( '//img[not(@alt) or @alt=""]' )->length, 'DOM: у всех картинок есть alt' );
$cards_ok = true;
foreach ( $xp->query( '//div[contains(concat(" ",normalize-space(@class)," ")," event ")]' ) as $card ) {
	if ( 1 !== $xp->query( './div[@class="wrap"]/div[@class="desc"]/a[contains(@class,"title")]', $card )->length || 1 !== $xp->query( './div[@class="wrap"]/div[@class="readmore"]', $card )->length ) {
		$cards_ok = false;
	}
}
af_ok( $cards_ok, 'DOM: структура карточек как на главной' );
af_ok( empty( $errors ), 'DOM: HTML без ошибок разбора ' . ( empty( $errors ) ? '' : reset( $errors )->message ) );

$html = Kozlov_Afisha_Render::render( Kozlov_Afisha_Data::normalize_args( array( 'when' => 'weekend' ) ), af_at( $fri ) );
af_ok( false !== strpos( $html, '<span>Сегодня, 9 октября</span>' ) && false !== strpos( $html, '<span>Завтра, 10 октября</span>' ) && false !== strpos( $html, '<span>Воскресенье, 11 октября</span>' ), 'weekend: заголовки дней' );

$html = Kozlov_Afisha_Render::render( Kozlov_Afisha_Data::normalize_args( array( 'genre' => 'jazz', 'days' => '400' ) ), af_at( $fri ) );
af_ok( false !== strpos( $html, '<span>Концерты в январе 2027</span>' ) && false !== strpos( $html, '<span>Концерты в июне 2027</span>' ), 'год у месяцев следующего года' );

$html = Kozlov_Afisha_Render::render( Kozlov_Afisha_Data::normalize_args( array( 'when' => 'today' ) ), af_at( '2026-10-11 23:00' ) );
af_ok( false !== strpos( $html, 'Сегодня концертов больше нет' ) && false !== strpos( $html, '<span>Завтра, 12 октября</span>' ), 'today fallback текст' );

$html = Kozlov_Afisha_Render::render( Kozlov_Afisha_Data::normalize_args( array( 'genre' => 'ежик', 'empty' => 'Нет "ёжиков" <b>' ) ), af_at( $fri ) );
af_ok( false !== strpos( $html, 'kozlov-afisha__empty' ) && false !== strpos( $html, 'Нет &quot;ёжиков&quot;' ) && false === strpos( $html, '<b>' ), 'пустой список, свой текст экранирован' );

$html = Kozlov_Afisha_Render::render( Kozlov_Afisha_Data::normalize_args( array( 'limit' => '3' ) ), af_at( $fri ) );
af_ok( false !== strpos( $html, 'Показаны ближайшие 3 концерта.' ), 'подпись при limit' );
$html = Kozlov_Afisha_Render::render( Kozlov_Afisha_Data::normalize_args( array( 'more' => '0', 'group' => 'none', 'days' => '3' ) ), af_at( $fri ) );
af_ok( false === strpos( $html, 'Вся афиша клуба' ) && false === strpos( $html, 'event_month' ) && false !== strpos( $html, 'kozlov-afisha__group--plain' ), 'more=0, group=none' );

af_ok( 'концерт' === Kozlov_Afisha_Render::plural( 21, 'концерт', 'концерта', 'концертов' ) && 'концертов' === Kozlov_Afisha_Render::plural( 11, 'концерт', 'концерта', 'концертов' ) && 'концерта' === Kozlov_Afisha_Render::plural( 22, 'концерт', 'концерта', 'концертов' ) && 'концертов' === Kozlov_Afisha_Render::plural( 12, 'концерт', 'концерта', 'концертов' ), 'склонение' );

// Шорткод целиком: кэш, DONOTCACHEPAGE, стиль один раз.
delete_transient( 'x' );
$out1 = do_shortcode( '[kozlov_afisha genre="jazz" days="400"]' );
$out2 = do_shortcode( '[kozlov_afisha genre="jazz" days="400"]' );
af_ok( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE, 'DONOTCACHEPAGE' );
af_ok( 1 === substr_count( $out1 . $out2, 'kozlov-afisha-pages-inline-css' ), 'стиль выведен один раз' );
af_ok( false !== strpos( $out2, 'class="kozlov-afisha"' ), 'шорткод выводит афишу' );
$wpdb   = $GLOBALS['wpdb'];
$cached = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_kozlov\\_afisha\\_%'" );
af_ok( $cached >= 1 || wp_using_ext_object_cache(), 'кэш создан' );

// Ошибка внутри: страница не падает.
$GLOBALS['wpdb']->suppress_errors( true );
$saved        = $wpdb->prefix;
$wpdb->prefix = 'nosuchprefix_';
$out          = Kozlov_Afisha_Render::render( Kozlov_Afisha_Data::normalize_args( array( 'genre' => 'blues', 'days' => '33' ) ), af_at( $fri ) );
$wpdb->prefix = $saved;
af_ok( false !== strpos( $out, 'kozlov-afisha__empty' ), 'нет таблицы календаря: пустой список, без ошибок' );

echo 'pass=' . $GLOBALS['af_pass'] . ' fail=' . $GLOBALS['af_fail'] . "\n";
