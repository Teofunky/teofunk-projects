<?php
/** Страница: обычная («О клубе», «Контакты») или афиша с концертами. */
get_header();

while ( have_posts() ) :
	the_post();
	$jac_afisha = jac_home_page();
	$jac_data   = ( $jac_afisha && get_the_ID() === $jac_afisha->ID ) ? jac_events( $jac_afisha ) : array( 'events' => array() );
	$jac_today  = ( new DateTimeImmutable( 'today', wp_timezone() ) )->getTimestamp();
	?>
	<div class="wrap layout">
		<article class="sheet">
			<?php jac_crumbs(); ?>

			<?php if ( $jac_data['events'] ) : ?>
				<header class="page-head">
					<h1 class="page-title">Афиша</h1>
					<?php if ( $jac_data['season'] ) : ?>
						<p class="page-intro"><?php echo esc_html( $jac_data['season'] ); ?></p>
					<?php endif; ?>
				</header>

				<?php
				$jac_section = null;
				foreach ( $jac_data['events'] as $jac_e ) :
					if ( $jac_e['section'] && $jac_e['section'] !== $jac_section ) {
						$jac_section = $jac_e['section'];
						echo '<h2 class="afisha-month">' . esc_html( $jac_section ) . '</h2>';
					}
					$jac_past = $jac_e['ts'] < $jac_today;
					?>
					<section class="afisha-item<?php echo $jac_past ? ' is-past' : ''; ?>" id="<?php echo esc_attr( $jac_e['id'] ); ?>">
						<div class="afisha-date">
							<?php echo jac_date_badge( $jac_e ); ?>
							<?php if ( $jac_e['time'] ) : ?>
								<span class="afisha-time">Начало в <?php echo esc_html( $jac_e['time'] ); ?></span>
							<?php endif; ?>
						</div>
						<div class="afisha-body">
							<h3 class="afisha-title"><?php echo esc_html( $jac_e['title'] ); ?></h3>
							<?php if ( $jac_past ) : ?><span class="afisha-past">Концерт прошёл</span><?php endif; ?>
							<?php if ( $jac_e['image_full'] ) : ?>
								<a class="afisha-photo" href="<?php echo esc_url( jac_img( $jac_e['image_full'] ) ); ?>"><img src="<?php echo esc_url( jac_img( $jac_e['image_full'], 960 ) ); ?>" alt="<?php echo esc_attr( $jac_e['title'] ); ?>" loading="lazy"></a>
							<?php endif; ?>
							<?php if ( trim( wp_strip_all_tags( $jac_e['text'] ) ) ) : ?>
								<div class="prose"><?php echo apply_filters( 'the_content', $jac_e['text'] ); // phpcs:ignore ?></div>
							<?php endif; ?>
						</div>
					</section>
				<?php endforeach; ?>

			<?php else : ?>
				<header class="article-head">
					<h1 class="page-title"><?php the_title(); ?></h1>
					<?php jac_subnav(); ?>
				</header>

				<?php if ( has_post_thumbnail() ) : ?>
					<figure class="article-image"><?php the_post_thumbnail( 'jac-wide' ); ?></figure>
				<?php endif; ?>

				<div class="prose"><?php the_content(); ?></div>

				<?php
				$jac_children = get_pages( array( 'parent' => get_the_ID(), 'sort_column' => 'menu_order,post_title' ) );
				if ( $jac_children ) :
					?>
					<nav class="children-list" aria-labelledby="children-title">
						<h2 id="children-title">В этом разделе</h2>
						<ul>
							<?php foreach ( $jac_children as $jac_child ) : ?>
								<li><a href="<?php echo esc_url( get_permalink( $jac_child ) ); ?>"><?php echo esc_html( get_the_title( $jac_child ) ); ?></a></li>
							<?php endforeach; ?>
						</ul>
					</nav>
				<?php endif; ?>
			<?php endif; ?>

			<footer class="article-foot">
				<?php edit_post_link( $jac_data['events'] ? 'Изменить программу концертов' : 'Редактировать страницу', '<p class="edit-link">', '</p>' ); ?>
			</footer>

			<?php
			if ( comments_open() || get_comments_number() ) {
				comments_template();
			}
			?>
		</article>
		<?php get_template_part( 'parts/aside' ); ?>
	</div>
	<?php
endwhile;

get_footer();
