	</main>

	<aside class="side" aria-label="Разделы и контакты">
		<?php
		jac_projects();
		jac_rubrics();
		jac_venue();
		if ( is_active_sidebar( 'side' ) ) {
			dynamic_sidebar( 'side' );
		}
		?>
	</aside>
</div>

<footer class="site-footer">
	<div class="wrap footer-grid">
		<div>
			<img class="footer-logo" src="<?php echo esc_url( jac_logo_url() ); ?>" alt="" width="134" height="80" loading="lazy">
			<p class="footer-name"><?php bloginfo( 'name' ); ?></p>
			<?php if ( get_bloginfo( 'description' ) ) : ?>
				<p><?php bloginfo( 'description' ); ?></p>
			<?php endif; ?>
			<?php
			$address = get_theme_mod( 'jac_address' );
			$phone   = get_theme_mod( 'jac_phone' );
			$email   = get_theme_mod( 'jac_email' );
			$note    = get_theme_mod( 'jac_note' );
			if ( $address || $phone || $email || $note ) :
				?>
				<div class="footer-contacts">
					<?php if ( $address ) : ?><p>Адрес: <?php echo esc_html( $address ); ?></p><?php endif; ?>
					<?php if ( $phone ) : ?><p>Телефон: <a href="tel:<?php echo esc_attr( preg_replace( '~[^\d+]~', '', $phone ) ); ?>"><?php echo esc_html( $phone ); ?></a></p><?php endif; ?>
					<?php if ( $email ) : ?><p>Почта: <a href="mailto:<?php echo esc_attr( antispambot( $email ) ); ?>"><?php echo esc_html( antispambot( $email ) ); ?></a></p><?php endif; ?>
					<?php if ( $note ) : ?><p><?php echo esc_html( $note ); ?></p><?php endif; ?>
				</div>
			<?php endif; ?>
		</div>

		<nav aria-labelledby="footer-menu-title">
			<h2 class="footer-title" id="footer-menu-title">Разделы сайта</h2>
			<?php
			wp_nav_menu( array(
				'theme_location' => has_nav_menu( 'footer' ) ? 'footer' : 'primary',
				'container'      => false,
				'menu_class'     => 'footer-menu',
				'depth'          => 1,
				'fallback_cb'    => 'jac_menu_fallback',
			) );
			?>
		</nav>
	</div>
	<div class="wrap footer-bottom">
		<p>© <?php echo esc_html( wp_date( 'Y' ) ); ?> <?php bloginfo( 'name' ); ?><?php if ( get_theme_mod( 'jac_credit' ) ) : ?>. <?php echo esc_html( get_theme_mod( 'jac_credit' ) ); ?><?php endif; ?></p>
		<p><a href="#top">Наверх, к началу страницы</a></p>
	</div>
</footer>

<?php wp_footer(); ?>
</body>
</html>
