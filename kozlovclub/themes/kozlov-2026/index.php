<?php
/** Запасной шаблон: список записей. */
get_header();
?>
<div class="content content_page"><div class="inner content_inner content_page_inner"><div class="wrap"><div class="post page_post"><div class="post_content page_post_content">
<?php if ( have_posts() ) : while ( have_posts() ) : the_post(); ?>
	<h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
	<div class="description"><?php the_excerpt(); ?></div>
<?php endwhile; else : ?>
	<p>Ничего не найдено.</p>
<?php endif; ?>
</div></div></div></div></div>
<?php get_footer(); ?>
