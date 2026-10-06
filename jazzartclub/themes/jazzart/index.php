<?php
/** Списки статей: рубрика, метка, архив по дате. */
get_header();

$jac_title = 'Все материалы';
$jac_intro = '';
if ( is_category() || is_tag() || is_tax() ) {
	$jac_title = single_term_title( '', false );
	$jac_intro = term_description();
} elseif ( is_author() ) {
	$jac_title = 'Статьи автора: ' . get_the_author_meta( 'display_name', get_queried_object_id() );
} elseif ( is_year() ) {
	$jac_title = 'Статьи за ' . get_the_date( 'Y' ) . ' год';
} elseif ( is_archive() ) {
	$jac_title = wp_strip_all_tags( get_the_archive_title() );
}
// Описание, которое просто повторяет название рубрики, не показываем.
if ( trim( wp_strip_all_tags( $jac_intro ) ) === trim( $jac_title ) ) {
	$jac_intro = '';
}
$jac_total = (int) $GLOBALS['wp_query']->found_posts;
?>
<div class="wrap layout">
	<div class="sheet">
		<?php jac_crumbs(); ?>
		<header class="page-head">
			<h1 class="page-title"><?php echo esc_html( $jac_title ); ?></h1>
			<?php if ( $jac_intro ) : ?>
				<div class="page-intro"><?php echo wp_kses_post( $jac_intro ); ?></div>
			<?php endif; ?>
		</header>

		<?php jac_rubrics(); ?>

		<?php if ( have_posts() ) : ?>
			<p class="list-count"><?php echo esc_html( jac_plural( $jac_total, 'статья', 'статьи', 'статей' ) ); ?></p>
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
			<div class="notice"><p>В этом разделе пока нет статей.</p></div>
			<p class="more"><a class="button" href="<?php echo esc_url( home_url( '/' ) ); ?>">Вернуться на главную</a></p>
		<?php endif; ?>
	</div>
	<?php get_template_part( 'parts/aside' ); ?>
</div>
<?php
get_footer();
