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
	<div class="site-header-top">
	<div class="wrap header-row">
		<a class="brand" href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home">
			<img class="brand-logo" src="<?php echo esc_url( jac_logo_url() ); ?>" alt="Jazz Art Club" width="150" height="90">
			<span class="brand-text">
				<span class="brand-name"><?php bloginfo( 'name' ); ?></span>
				<?php if ( get_bloginfo( 'description' ) ) : ?>
					<span class="brand-tagline"><?php bloginfo( 'description' ); ?></span>
				<?php endif; ?>
			</span>
		</a>

		<div class="header-tools">
			<?php $jac_phone = get_theme_mod( 'jac_phone' ); ?>
			<?php if ( $jac_phone ) : ?>
				<p class="header-phone">Телефон: <a href="tel:<?php echo esc_attr( preg_replace( '~[^\d+]~', '', $jac_phone ) ); ?>"><?php echo esc_html( $jac_phone ); ?></a></p>
			<?php endif; ?>
			<?php get_search_form(); ?>
			<div class="text-size" role="group" aria-label="Размер текста">
				<span class="text-size-label">Размер текста</span>
				<button type="button" data-size="m" aria-pressed="true" title="Обычный"><span aria-hidden="true">А</span><span class="visually-hidden">Обычный</span></button>
				<button type="button" data-size="l" aria-pressed="false" title="Крупный"><span aria-hidden="true">А</span><span class="visually-hidden">Крупный</span></button>
				<button type="button" data-size="xl" aria-pressed="false" title="Очень крупный"><span aria-hidden="true">А</span><span class="visually-hidden">Очень крупный</span></button>
			</div>
		</div>
	</div>
	</div>

	<nav class="site-nav" aria-label="Главное меню">
		<div class="wrap">
			<button type="button" class="nav-toggle" aria-expanded="false" aria-controls="primary-menu">Меню</button>
			<?php
			$jac_menus = has_nav_menu( 'primary' ) ? array() : wp_get_nav_menus();
			wp_nav_menu( array(
				'theme_location' => 'primary',
				'menu'           => $jac_menus ? $jac_menus[0] : '',
				'container'      => false,
				'menu_id'        => 'primary-menu',
				'menu_class'     => 'menu',
				'depth'          => 2,
				'fallback_cb'    => 'jac_menu_fallback',
			) );
			?>
		</div>
	</nav>
</header>

<div class="wrap layout">
	<main id="content">
		<?php jac_crumbs(); ?>
