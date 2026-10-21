<?php if ( have_posts() ) : while ( have_posts() ) : the_post(); ?>
	<div class="title">
		<h1><?php the_title() ?></h1>
	</div>
	<div class="description">
		<?php the_content() ?>
	</div>
<?php endwhile; endif; ?>
