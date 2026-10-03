<?php
/** Обычная страница: «О клубе», «Контакты» и подобные. */
get_header();

while ( have_posts() ) :
	the_post();
	?>
	<article>
		<header class="article-head">
			<h1 class="page-title"><?php the_title(); ?></h1>
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
				<h2 class="section-title" id="children-title">В этом разделе</h2>
				<ul>
					<?php foreach ( $jac_children as $jac_child ) : ?>
						<li><a href="<?php echo esc_url( get_permalink( $jac_child ) ); ?>"><?php echo esc_html( get_the_title( $jac_child ) ); ?></a></li>
					<?php endforeach; ?>
				</ul>
			</nav>
		<?php endif; ?>

		<footer class="article-foot">
			<?php edit_post_link( 'Редактировать страницу', '<p class="edit-link">', '</p>' ); ?>
		</footer>
	</article>
	<?php
	if ( comments_open() || get_comments_number() ) {
		comments_template();
	}
endwhile;

get_footer();
