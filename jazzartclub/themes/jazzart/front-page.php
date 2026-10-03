<?php
/**
 * Главная: первая статья крупно, ниже список новых статей.
 * Если в настройках темы выбрана страница для главной (обычно «Анонсы»), выводится она.
 */
get_header();

$jac_paged = max( 1, (int) get_query_var( 'paged' ), (int) get_query_var( 'page' ) );

$jac_home = ( 1 === $jac_paged ) ? jac_home_page() : null;
if ( ! is_home() || $jac_home ) {
	// На главной показывается страница целиком: выбранная в настройках темы (обычно «Анонсы»)
	// либо назначенная в «Настройки → Чтение».
	if ( $jac_home ) {
		$GLOBALS['post'] = $jac_home;
		setup_postdata( $jac_home );
		$jac_pages = array( $jac_home );
	} else {
		$jac_pages = $GLOBALS['wp_query']->posts;
	}
	foreach ( $jac_pages as $jac_page ) {
		$GLOBALS['post'] = $jac_page;
		setup_postdata( $jac_page );
		?>
		<article>
			<header class="article-head">
				<h1 class="page-title"><?php the_title(); ?></h1>
			</header>
			<div class="prose"><?php the_content(); ?></div>
			<footer class="article-foot">
				<?php edit_post_link( 'Редактировать эту страницу', '<p class="edit-link">', '</p>' ); ?>
			</footer>
		</article>
		<?php
	}
	wp_reset_postdata();
	get_footer();
	return;
}

$jac_query = $GLOBALS['wp_query'];
if ( 1 === $jac_paged ) {
	echo '<h1 class="visually-hidden">' . esc_html( get_bloginfo( 'name' ) ) . '</h1>';
}

if ( $jac_query->have_posts() ) :

	if ( 1 === $jac_paged ) :
		$jac_query->the_post();
		?>
		<article class="lead">
			<?php if ( has_post_thumbnail() ) : ?>
				<a class="lead-image" href="<?php the_permalink(); ?>" tabindex="-1" aria-hidden="true"><?php the_post_thumbnail( 'jac-wide', array( 'alt' => '' ) ); ?></a>
			<?php endif; ?>
			<?php if ( jac_meta() ) : ?><p class="meta"><?php echo jac_meta(); ?></p><?php endif; ?>
			<h2 class="lead-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
			<p class="lead-excerpt"><?php echo esc_html( get_the_excerpt() ); ?></p>
			<a class="button" href="<?php the_permalink(); ?>">Читать статью<span class="visually-hidden">: <?php the_title(); ?></span></a>
		</article>
	<?php else : ?>
		<header class="page-head">
			<h1 class="page-title">Все статьи</h1>
			<p class="page-intro">Страница <?php echo (int) $jac_paged; ?> из <?php echo (int) $jac_query->max_num_pages; ?></p>
		</header>
	<?php endif; ?>

	<?php if ( $jac_query->have_posts() ) : ?>
		<section aria-labelledby="latest-title">
			<h2 class="section-title" id="latest-title"><?php echo 1 === $jac_paged ? 'Новые статьи' : 'Статьи'; ?></h2>
			<ul class="rows">
				<?php
				while ( $jac_query->have_posts() ) {
					$jac_query->the_post();
					get_template_part( 'parts/row' );
				}
				?>
			</ul>
		</section>
	<?php endif; ?>

	<?php
	$jac_links = paginate_links( array(
		'total'              => $jac_query->max_num_pages,
		'current'            => $jac_paged,
		'mid_size'           => 1,
		'prev_text'          => 'Предыдущая страница',
		'next_text'          => 'Следующая страница',
		'before_page_number' => '<span class="visually-hidden">Страница </span>',
	) );
	if ( $jac_links ) {
		echo '<nav class="pagination" aria-label="Страницы"><div class="nav-links">' . $jac_links . '</div></nav>';
	}
	wp_reset_postdata();

else :
	?>
	<div class="notice">
		<p>Статей пока нет. Первая опубликованная статья появится здесь.</p>
	</div>
	<?php
endif;

get_footer();
