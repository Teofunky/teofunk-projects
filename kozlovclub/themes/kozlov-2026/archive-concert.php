<?php
/** /afisha/ : вся афиша; /afisha/?archive=1 : прошедшие концерты, новые сверху. */
get_header();
$kz_view = kz_view();
$past = ! empty( $_GET['archive'] );
$kz_title = $past ? 'Архив концертов' : 'Афиша';
$kz_sub = $past ? '<a href="' . esc_url( home_url( '/' ) ) . '">Вся афиша</a>' : '<a href="' . esc_url( home_url( '/afisha/?archive=1' ) ) . '">Архив прошедших концертов</a>';
$kz_concerts = $past ? kozlov_concerts( array( 'past' => true, 'to' => wp_date( 'Y-m-d H:i:s' ), 'order' => 'DESC', 'limit' => 200 ) ) : kozlov_concerts( array( 'limit' => 600 ) );
$kz_divider = $past ? 'Концерты в ' : 'Концерты в ';
include get_template_directory() . '/template-parts/listing.php';
get_footer();
