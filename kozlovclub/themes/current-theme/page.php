<?php // шаблон статических страниц ?>
<?php get_header(); ?>
<div class="content content_page">
	<div class="inner content_inner content_page_inner">
		<div class="wrap">
			<div class="post page_post">
				<div class="post_content page_post_content clearfix">
					<?php
					$_template_php = preg_match( '/archive/iU', $_SERVER['REQUEST_URI'] ) ? 'archive.php' : 'page.php';
					require( 'templates/'.$_template_php );
					?>
				</div> <!-- .page_post_content -->
			</div> <!-- .page_post -->
		</div>
	</div>
</div>
<?php get_footer(); ?>
