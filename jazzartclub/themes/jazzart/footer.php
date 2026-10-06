</main>

<footer class="site-footer">
	<div class="wrap footer-grid">
		<div>
			<img class="footer-logo" src="<?php echo esc_url( jac_logo_url( 'footer' ) ); ?>" alt="Джаз Арт Клуб" width="118" height="70" loading="lazy">
			<?php if ( get_bloginfo( 'description' ) ) : ?>
				<p><?php bloginfo( 'description' ); ?></p>
			<?php endif; ?>
			<?php
			$jac_address = get_theme_mod( 'jac_address' );
			$jac_phone   = get_theme_mod( 'jac_phone' );
			$jac_email   = get_theme_mod( 'jac_email' );
			$jac_note    = get_theme_mod( 'jac_note' );
			?>
			<?php if ( $jac_address ) : ?><p><?php echo esc_html( $jac_address ); ?></p><?php endif; ?>
			<?php if ( $jac_phone ) : ?><p>Телефон: <a href="tel:<?php echo esc_attr( preg_replace( '~[^\d+]~', '', $jac_phone ) ); ?>"><?php echo esc_html( $jac_phone ); ?></a></p><?php endif; ?>
			<?php if ( $jac_email ) : ?><p>Почта: <a href="mailto:<?php echo esc_attr( antispambot( $jac_email ) ); ?>"><?php echo esc_html( antispambot( $jac_email ) ); ?></a></p><?php endif; ?>
			<?php if ( $jac_note ) : ?><p><?php echo esc_html( $jac_note ); ?></p><?php endif; ?>
		</div>

		<nav aria-labelledby="footer-menu-title">
			<h2 class="footer-title" id="footer-menu-title">Разделы</h2>
			<?php
			wp_nav_menu( array(
				'theme_location' => has_nav_menu( 'footer' ) ? 'footer' : 'primary',
				'container'      => false,
				'menu_class'     => 'footer-menu',
				'depth'          => 2,
				'fallback_cb'    => 'jac_menu_fallback',
			) );
			?>
		</nav>

		<?php if ( has_nav_menu( 'projects' ) ) : ?>
			<nav aria-labelledby="footer-projects-title">
				<h2 class="footer-title" id="footer-projects-title">Проекты клуба</h2>
				<?php wp_nav_menu( array( 'theme_location' => 'projects', 'container' => false, 'menu_class' => 'footer-menu', 'depth' => 1, 'fallback_cb' => false ) ); ?>
			</nav>
		<?php endif; ?>

		<div>
			<h2 class="footer-title">Поиск по сайту</h2>
			<?php get_search_form(); ?>
			<?php jac_text_size(); ?>
		</div>
	</div>
	<div class="wrap footer-bottom">
		<p>© <?php echo esc_html( wp_date( 'Y' ) ); ?> Джаз Арт Клуб<?php if ( get_theme_mod( 'jac_credit' ) ) : ?>. <?php echo esc_html( get_theme_mod( 'jac_credit' ) ); ?><?php endif; ?></p>
		<p><a href="#top">Наверх</a></p>
	</div>
</footer>

<?php wp_footer(); ?>
</body>
</html>
