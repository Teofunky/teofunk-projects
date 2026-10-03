<?php
/** Страница не найдена. */
get_header();
?>
<header class="page-head">
	<h1 class="page-title">Такой страницы нет</h1>
	<p class="page-intro">Возможно, адрес набран с ошибкой или страницу перенесли.</p>
</header>
<div class="search-again"><?php get_search_form(); ?></div>
<p class="more"><a class="button" href="<?php echo esc_url( home_url( '/' ) ); ?>">Перейти на главную</a></p>
<?php
get_footer();
