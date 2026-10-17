<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
get_header();
?>
<main class="ka">
	<nav class="ka-bc" aria-label="Хлебные крошки">
		<a href="<?php echo esc_url( home_url( '/' ) ); ?>">Главная</a><span>/</span><span class="ka-bc__cur">Артисты</span>
	</nav>
	<section class="ka-sec">
		<h1 class="ka-h1" style="font-size:clamp(36px,6vw,80px);margin-bottom:40px">Артисты <b>клуба</b></h1>
		<div class="ka-cards">
		<?php while ( have_posts() ) : the_post(); $id = get_the_ID(); $ev = ka_get_events( $id, 1 ); ?>
			<a class="ka-card" href="<?php the_permalink(); ?>">
				<?php if ( has_post_thumbnail() ) { the_post_thumbnail( 'medium_large', array( 'loading' => 'lazy' ) ); } ?>
				<span class="ka-card__n"><?php the_title(); ?></span>
				<span class="ka-card__m"><?php echo esc_html( trim( get_post_meta( $id, 'ka_genre', true ) . ( $ev ? ' · ближайший ' . ka_date( $ev[0]['ts'], 'j F' ) : '' ), ' ·' ) ); ?></span>
			</a>
		<?php endwhile; ?>
		</div>
	</section>
</main>
<?php get_footer();
