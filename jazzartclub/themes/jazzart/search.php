<?php
/** Результаты поиска. */
get_header();
$jac_total = (int) $GLOBALS['wp_query']->found_posts;
$jac_query = trim( get_search_query() );
?>
<div class="wrap layout">
	<div class="sheet">
		<?php jac_crumbs(); ?>
		<header class="page-head">
			<h1 class="page-title"><?php echo '' === $jac_query ? 'Поиск по сайту' : 'Поиск: «' . esc_html( $jac_query ) . '»'; ?></h1>
		</header>
		<div class="search-again"><?php get_search_form(); ?></div>

		<?php if ( '' === $jac_query ) : ?>
			<p>Наберите слово или фамилию и нажмите «Найти».</p>
		<?php elseif ( have_posts() ) : ?>
			<p class="list-count">Найдено: <?php echo esc_html( jac_plural( $jac_total, 'материал', 'материала', 'материалов' ) ); ?></p>
			<ul class="rows">
				<?php
				while ( have_posts() ) {
					the_post();
					get_template_part( 'parts/row' );
				}
				?>
			</ul>
			<?php jac_pagination(); ?>
		<?php else : ?>
			<div class="notice">
				<p><strong>Ничего не найдено.</strong> Попробуйте одно слово вместо фразы или другое написание, например фамилию музыканта.</p>
			</div>
		<?php endif; ?>
	</div>
	<?php get_template_part( 'parts/aside' ); ?>
</div>
<?php
get_footer();
