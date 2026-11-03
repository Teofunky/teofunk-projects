<?php
/** Концерты жанра: /genre/jazz/ */
get_header();
$term = get_queried_object();
$kz_view = kz_view();
$kz_title = 'free' === $term->slug ? 'Концерты со свободным входом' : $term->name . ': концерты в Москве';
$kz_sub = $term->description ?: '<a href="' . esc_url( home_url( '/' ) ) . '">Вся афиша</a>';
$kz_concerts = kozlov_concerts( array( 'genre' => $term->slug, 'limit' => 600 ) );
include get_template_directory() . '/template-parts/listing.php';
get_footer();
