<?php
/** Концерты на сцене: /stage/mansarda/ */
get_header();
$term = get_queried_object();
$kz_view = kz_view();
$kz_title = 'Концерты на сцене ' . $term->name;
$kz_sub = $term->description ?: '<a href="' . esc_url( home_url( '/' ) ) . '">Вся афиша</a>';
$kz_concerts = kozlov_concerts( array( 'stage' => $term->slug, 'limit' => 600 ) );
include get_template_directory() . '/template-parts/listing.php';
get_footer();
