<?php
// Разбор состава на 35 реальных анонсах kozlovclub.ru (текстовые блоки страниц, октябрь 2026).
// Запуск: wp eval-file tests/lineup_real.php            (сверка с lineup_expected.json)
//         wp eval-file tests/lineup_real.php write (перезаписать ожидаемое, только после ручной проверки)
$samples  = json_decode( file_get_contents( __DIR__ . '/lineup_samples.json' ), true );
$expected_file = __DIR__ . '/lineup_expected.json';
$got = array();
$found = 0;
foreach ( $samples as $s ) {
	$names = array();
	foreach ( $s['blocks'] as $b ) {
		foreach ( Kozlov_Event_Schema_Builder::parse_performers( $b['html'] ) as $n ) {
			if ( ! in_array( $n, $names, true ) ) { $names[] = $n; }
		}
	}
	if ( $names ) { $found++; }
	$got[ $s['url'] ] = $names;
}
if ( in_array( 'write', isset( $args ) ? (array) $args : array(), true ) ) {
	file_put_contents( $expected_file, wp_json_encode( $got, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
	echo "written\n";
	return;
}
if ( ! file_exists( $expected_file ) ) { echo "FAIL: no lineup_expected.json\n"; return; }
$expected = json_decode( file_get_contents( $expected_file ), true );
$fail = 0;
foreach ( $expected as $url => $names ) {
	if ( ! isset( $got[ $url ] ) || $got[ $url ] !== $names ) {
		$fail++;
		echo "FAIL $url\n  expected: " . implode( ', ', $names ) . "\n  got:      " . implode( ', ', isset( $got[ $url ] ) ? $got[ $url ] : array() ) . "\n";
	}
}
echo "lineup found in $found of " . count( $samples ) . " real announcements; mismatches: $fail\n";
