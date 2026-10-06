<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?> id="top">
<?php wp_body_open(); ?>
<a class="skip" href="#content">Перейти к тексту</a>

<header class="site-header">
	<div class="wrap header-row">
		<a class="brand" href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home">
			<img class="brand-logo" src="<?php echo esc_url( jac_logo_url() ); ?>" alt="Джаз Арт Клуб: на главную" width="105" height="63">
		</a>

		<nav class="site-nav" aria-label="Главное меню">
			<button type="button" class="nav-toggle" aria-expanded="false" aria-controls="primary-menu">Меню</button>
			<?php
			wp_nav_menu( array(
				'theme_location' => 'primary',
				'container'      => false,
				'menu_id'        => 'primary-menu',
				'menu_class'     => 'menu',
				'depth'          => 2,
				'fallback_cb'    => 'jac_menu_fallback',
			) );
			?>
		</nav>

		<div class="header-tools">
			<?php $jac_phone = get_theme_mod( 'jac_phone' ); ?>
			<?php if ( $jac_phone ) : ?>
				<a class="header-phone" href="tel:<?php echo esc_attr( preg_replace( '~[^\d+]~', '', $jac_phone ) ); ?>"><?php echo esc_html( $jac_phone ); ?></a>
			<?php endif; ?>
			<a class="button header-search" href="<?php echo esc_url( home_url( '/?s=' ) ); ?>" data-search-toggle aria-controls="search-panel" aria-expanded="false">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m20 20-4.7-4.7"/></svg>
				Поиск
			</a>
		</div>
	</div>
	<div class="search-panel" id="search-panel" hidden>
		<div class="wrap"><?php get_search_form(); ?></div>
	</div>
</header>

<main id="content">
