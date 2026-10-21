<?php // шаблон выдачи результатов поиска ?>
<?php get_header(); ?>

<?php $lang = WPGlobus::Config()->language; ?>

<div class="content content_page">
	<div class="inner content_inner content_page_inner">
		<div class="wrap">
			<div class="post page_post">
				<div class="post_content page_post_content clearfix">
					<?php if ( have_posts() ) : ?>
						<div class="search_results">
							<?php while ( have_posts() ) : the_post(); ?>
								<?php if ( get_the_post_thumbnail() ) { ?>
									<div class="item">
										<?php $url = '/'.$lang.'/event/'.$post->post_name ?>
										<div class="thumbnail">
											<a href="<?php echo $url ?>"><?php echo get_the_post_thumbnail() ?></a>
										</div>
										<a class="title" href="<?php echo $url ?>"><?php the_title() ?></a>
									</div>
								<?php } ?>
							<?php endwhile; ?>
						</div>
						<script>
						max_height = 0;
						$('.search_results .item .title').each(function () {
							$this_height = $(this).height();
							if ( $this_height > max_height ) {
								max_height = $this_height;
							}
						});
						$('.search_results .item .title').css({ 'height' : max_height + 40 });
						</script>
					<?php else : ?>
						<div class="search_no_results">
							<?php if ( $lang == 'ru' ) { ?>
								<strong>Ничего не найдено</strong><br>
								<p>Запрашиваемая страница не найдена. Попробуйте уточнить параметры поиска или используйте меню для нахождения страницы.</p>
							<?php } else if ( $lang == 'en' ) { ?>
								<strong>No results are found</strong>
							<?php } ?>

						</div>
					<?php endif; ?>

				</div> <!-- .page_post_content -->
			</div> <!-- .page_post -->
		</div>
	</div>
</div>
<?php get_footer(); ?>
