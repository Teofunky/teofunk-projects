<?php
/** Страница не найдена. */
get_header();
?>
<div class="wrap layout">
	<div class="sheet">
		<header class="page-head">
			<h1 class="page-title">Такой страницы нет</h1>
			<p class="page-intro">Возможно, адрес набран с ошибкой или страницу перенесли.</p>
		</header>
		<div class="search-again"><?php get_search_form(); ?></div>
		<p class="more"><a class="button" href="<?php echo esc_url( home_url( '/' ) ); ?>">Перейти на главную</a> <a class="button is-quiet" href="<?php echo esc_url( jac_afisha_url() ); ?>">Афиша</a></p>
	</div>
	<?php get_template_part( 'parts/aside' ); ?>
</div>
<?php
get_footer();
