<?php
/** Страница статьи. */
get_header();

while ( have_posts() ) :
	the_post();
	$jac_rubric = jac_rubric();
	$jac_title  = get_the_title() ? get_the_title() : 'Без названия';
	?>
	<div class="wrap layout">
		<div class="sheet">
			<?php jac_crumbs(); ?>
			<article>
				<header class="article-head">
					<h1 class="page-title"><?php echo $jac_title; // phpcs:ignore ?></h1>
					<div class="meta">
						<?php echo jac_meta(); // phpcs:ignore ?>
						<?php jac_text_size(); ?>
						<button type="button" class="print-button" data-print>Распечатать</button>
					</div>
				</header>

				<?php if ( has_post_thumbnail() ) : ?>
					<figure class="article-image"><?php the_post_thumbnail( 'jac-wide' ); ?></figure>
				<?php endif; ?>

				<div class="prose">
					<?php
					the_content();
					wp_link_pages( array( 'before' => '<p class="pagination"><span>Части статьи:</span> ', 'after' => '</p>' ) );
					?>
				</div>

				<footer class="article-foot">
					<?php the_tags( '<p class="tags">Метки: ', ', ', '</p>' ); ?>
					<?php edit_post_link( 'Редактировать статью', '<p class="edit-link">', '</p>' ); ?>
				</footer>
			</article>

			<?php
			$jac_prev = get_previous_post();
			$jac_next = get_next_post();
			if ( $jac_prev || $jac_next ) :
				?>
				<nav class="neighbors" aria-label="Соседние статьи">
					<?php if ( $jac_prev ) : ?>
						<a class="prev" href="<?php echo esc_url( get_permalink( $jac_prev ) ); ?>"><span class="label">Предыдущая статья</span><span class="title"><?php echo esc_html( get_the_title( $jac_prev ) ? get_the_title( $jac_prev ) : 'Без названия' ); ?></span></a>
					<?php endif; ?>
					<?php if ( $jac_next ) : ?>
						<a class="next" href="<?php echo esc_url( get_permalink( $jac_next ) ); ?>"><span class="label">Следующая статья</span><span class="title"><?php echo esc_html( get_the_title( $jac_next ) ? get_the_title( $jac_next ) : 'Без названия' ); ?></span></a>
					<?php endif; ?>
				</nav>
			<?php endif; ?>

			<?php if ( $jac_rubric ) : ?>
				<p class="more"><a class="button is-quiet" href="<?php echo esc_url( get_category_link( $jac_rubric ) ); ?>">Все статьи рубрики «<?php echo esc_html( $jac_rubric->name ); ?>»</a></p>
			<?php endif; ?>

			<?php
			if ( comments_open() || get_comments_number() ) {
				comments_template();
			}
			?>
		</div>
		<?php get_template_part( 'parts/aside' ); ?>
	</div>
	<?php
endwhile;

get_footer();
