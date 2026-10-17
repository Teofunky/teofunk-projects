<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

add_filter( 'template_include', function ( $tpl ) {
	if ( is_post_type_archive( 'kozlov_artist' ) && file_exists( KA_DIR . 'templates/archive-artist.php' ) ) { return KA_DIR . 'templates/archive-artist.php'; }
	if ( is_singular( 'kozlov_artist' ) ) {
		$own = KA_DIR . 'templates/single-artist.php';
		if ( file_exists( $own ) && ! locate_template( 'single-kozlov_artist.php' ) ) { return $own; }
	}
	return $tpl;
} );

add_action( 'wp_enqueue_scripts', function () {
	if ( ! is_singular( 'kozlov_artist' ) && ! is_post_type_archive( 'kozlov_artist' ) ) { return; }
	wp_enqueue_style( 'kozlov-artists', KA_URL . 'assets/artist.css', array(), KA_VERSION );
	wp_enqueue_style( 'kozlov-artists-font', 'https://fonts.googleapis.com/css2?family=Roboto:wght@100;300;400;500&display=swap', array(), null );
} );
