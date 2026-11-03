<?php
/** Поиск: концерты (будущие, затем прошедшие) и страницы. */
get_header();
$q = get_search_query();
$kz_view = kz_view();
$future = $q ? kozlov_concerts( array( 'q' => $q, 'limit' => 100 ) ) : array();
$past   = $q ? kozlov_concerts( array( 'q' => $q, 'past' => true, 'to' => wp_date( 'Y-m-d H:i:s' ), 'order' => 'DESC', 'limit' => 60 ) ) : array();
$pages  = $q ? get_posts( array( 'post_type' => 'page', 's' => $q, 'posts_per_page' => 20 ) ) : array();
?>
<div class="content content_home">
	<div class="inner content_inner events_wrap view_<?php echo esc_attr( $kz_view ); ?>">
		<div class="wrap">
			<h1 class="afisha-heading">Поиск: <?php echo esc_html( $q ); ?><small>Найдено концертов: <?php echo count( $future ) + count( $past ); ?></small></h1>
			<?php if ( $pages ) : ?>
				<div class="afisha-search-pages" style="margin:0 0 30px;text-align:center"><?php foreach ( $pages as $p ) : ?><a href="<?php echo esc_url( get_permalink( $p ) ); ?>" style="margin:0 10px"><?php echo esc_html( get_the_title( $p ) ); ?></a><?php endforeach; ?></div>
			<?php endif; ?>
			<?php if ( $future ) : ?><?php echo kz_afisha( $future, $kz_view ); // phpcs:ignore ?><?php endif; ?>
			<?php if ( $past ) : ?><h2 class="afisha-heading">Прошедшие</h2><?php echo kz_afisha( $past, $kz_view, 'Концерты в ' ); // phpcs:ignore ?><?php endif; ?>
			<?php if ( ! $future && ! $past && ! $pages ) : ?><div class="afisha-empty">Ничего не нашлось. <a href="<?php echo esc_url( home_url( '/' ) ); ?>">Вся афиша</a></div><?php endif; ?>
		</div>
	</div>
</div>
<?php get_footer(); ?>
