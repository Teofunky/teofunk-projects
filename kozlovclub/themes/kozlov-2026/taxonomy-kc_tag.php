<?php
get_header();
$term = get_queried_object();
$kz_view = kz_view();
$kz_title = $term->name;
$kz_sub = '<a href="' . esc_url( home_url( '/' ) ) . '">Вся афиша</a>';
$kz_concerts = kozlov_concerts( array( 'limit' => 600 ) );
$kz_concerts = array_values( array_filter( $kz_concerts, fn( $c ) => has_term( $term->term_id, 'kc_tag', $c['id'] ) ) );
include get_template_directory() . '/template-parts/listing.php';
get_footer();
