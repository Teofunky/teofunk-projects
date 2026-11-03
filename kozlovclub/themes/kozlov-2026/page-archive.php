<?php
/** Template Name: Архив концертов */
get_header();
$kz_view = kz_view();
$year = isset( $_GET['y'] ) ? (int) $_GET['y'] : (int) wp_date( 'Y' );
$kz_title = 'Архив концертов ' . $year;
$years = '';
for ( $y = (int) wp_date( 'Y' ); $y >= 2025; $y-- ) {
	$years .= ( $y === $year ? '<b>' . $y . '</b>' : '<a href="' . esc_url( add_query_arg( 'y', $y ) ) . '">' . $y . '</a>' ) . ' ';
}
$kz_sub = $years . ' · <a href="' . esc_url( home_url( '/' ) ) . '">Вся афиша</a>';
$kz_concerts = kozlov_concerts( array( 'from' => $year . '-01-01', 'to' => min( $year . '-12-31 23:59:59', wp_date( 'Y-m-d H:i:s' ) ), 'past' => true, 'order' => 'DESC', 'limit' => 2000 ) );
include get_template_directory() . '/template-parts/listing.php';
get_footer();
