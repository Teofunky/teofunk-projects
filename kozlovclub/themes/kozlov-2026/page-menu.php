<?php
/** Template Name: Меню ресторана */
get_header();
?>
<div class="content content_page">
	<div class="inner content_inner content_page_inner">
		<div class="wrap">
			<div class="post page_post">
				<?php while ( have_posts() ) : the_post(); ?>
					<div class="post_content page_post_content">
						<?php if ( ! get_post_meta( get_the_ID(), 'kz_hide_title', true ) ) : ?><h1 class="page_title"><?php the_title(); ?></h1><?php endif; ?>
						<div class="description"><?php the_content(); ?></div>
					</div>
				<?php endwhile; ?>
			</div>
		</div>
	</div>
</div>
<?php get_footer(); ?>
