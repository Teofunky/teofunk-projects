<?php
/** Результаты поиска. */
get_header();
$jac_total = (int) $GLOBALS['wp_query']->found_posts;
?>
<header class="page-head">
	<h1 class="page-title">Поиск: «<?php echo esc_html( get_search_query() ); ?>»</h1>
</header>

<?php if ( have_posts() ) : ?>
	<h2 class="section-title">Найдено: <?php echo esc_html( jac_plural( $jac_total, 'материал', 'материала', 'материалов' ) ); ?></h2>
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
	<div class="search-again"><?php get_search_form(); ?></div>
	<p>Можно также выбрать рубрику в списке справа.</p>
<?php endif; ?>

<?php
get_footer();
