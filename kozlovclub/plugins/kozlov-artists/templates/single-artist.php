<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
get_header();

while ( have_posts() ) :
	the_post();
	$id      = get_the_ID();
	$events  = ka_get_events( $id );
	$next    = $events ? $events[0] : null;
	$genre   = get_post_meta( $id, 'ka_genre', true );
	$founded = get_post_meta( $id, 'ka_founded', true );
	$city    = get_post_meta( $id, 'ka_city', true ) ?: 'Москва';
	$videos  = array_filter( array_map( 'ka_video_embed', array(
		get_post_meta( $id, 'ka_video_1', true ),
		get_post_meta( $id, 'ka_video_2', true ),
		get_post_meta( $id, 'ka_video_3', true ),
	) ) );
	$gallery = ka_lines( $id, 'ka_gallery' );
	$credit  = get_post_meta( $id, 'ka_credit', true );
	$title   = get_the_title();
	?>
<main class="ka">
	<nav class="ka-bc" aria-label="Хлебные крошки">
		<a href="<?php echo esc_url( home_url( '/' ) ); ?>">Главная</a><span>/</span>
		<a href="<?php echo esc_url( get_post_type_archive_link( 'kozlov_artist' ) ); ?>">Артисты</a><span>/</span>
		<span class="ka-bc__cur"><?php echo esc_html( $title ); ?></span>
	</nav>
	<section class="ka-hero">
		<div class="ka-hero__text">
			<span class="ka-eyebrow"><?php echo esc_html( trim( ( $genre ?: 'Артист' ) . ' / ' . $city . ( $founded ? ' · с ' . $founded : '' ) ) ); ?></span>
			<h1 class="ka-h1"><?php echo esc_html( $title ); ?></h1>
			<?php if ( has_excerpt() ) : ?><p class="ka-lede"><?php echo esc_html( get_the_excerpt() ); ?></p><?php endif; ?>
			<a class="ka-btn" href="#ka-afisha">Ближайшие концерты</a>
		</div>
		<?php if ( has_post_thumbnail() ) : ?>
			<div class="ka-hero__photo"><?php the_post_thumbnail( 'large', array( 'alt' => esc_attr( $title ), 'loading' => 'eager', 'fetchpriority' => 'high' ) ); ?></div>
		<?php endif; ?>
	</section>

	<?php if ( $next ) : ?>
	<section class="ka-next" aria-label="Ближайший концерт">
		<span class="ka-next__date"><?php echo esc_html( ka_date( $next['ts'], 'j F · H:i' ) ); ?></span>
		<span class="ka-next__title"><?php echo esc_html( $next['title'] ); ?></span>
		<a class="ka-btn ka-btn--white" href="<?php echo esc_url( $next['url'] ); ?>">Купить билет</a>
	</section>
	<?php endif; ?>

	<section id="ka-afisha" class="ka-sec">
		<h2 class="ka-h2">Ближайшие <b>концерты и билеты</b></h2>
		<?php if ( $events ) : ?>
		<ul class="ka-rows">
			<?php foreach ( $events as $e ) : ?>
			<li class="ka-row">
				<span class="ka-row__day"><?php echo esc_html( ka_date( $e['ts'], 'j' ) ); ?></span>
				<span class="ka-row__meta"><?php echo esc_html( ka_date( $e['ts'], 'F' ) ); ?><br><i><?php echo esc_html( ka_date( $e['ts'], 'l · H:i' ) ); ?></i></span>
				<a class="ka-row__title" href="<?php echo esc_url( $e['url'] ); ?>"><?php echo esc_html( $e['title'] ); ?></a>
				<a class="ka-btn" href="<?php echo esc_url( $e['url'] ); ?>">Билеты</a>
			</li>
			<?php endforeach; ?>
		</ul>
		<?php else : ?>
			<p class="ka-empty">Ближайших концертов в афише пока нет. Загляните позже: новые даты появляются здесь автоматически.</p>
		<?php endif; ?>
	</section>

	<?php if ( $videos ) : ?>
	<section class="ka-sec">
		<h2 class="ka-h2">Послушайте <b>перед концертом</b></h2>
		<div class="ka-videos">
			<?php foreach ( $videos as $i => $src ) : ?>
			<div class="ka-video<?php echo 0 === $i ? ' ka-video--main' : ''; ?>">
				<iframe src="<?php echo esc_url( $src ); ?>" title="<?php echo esc_attr( $title . ': видео ' . ( $i + 1 ) ); ?>" loading="lazy" allow="autoplay; encrypted-media; fullscreen; picture-in-picture" allowfullscreen></iframe>
			</div>
			<?php endforeach; ?>
		</div>
	</section>
	<?php endif; ?>

	<section class="ka-sec ka-about">
		<h2 class="ka-h2">Об <b>артисте</b></h2>
		<div class="ka-about__body"><?php the_content(); ?></div>
	</section>

	<?php if ( $gallery ) : ?>
	<section class="ka-sec">
		<h2 class="ka-h2">Фото <b>с концертов</b></h2>
		<div class="ka-gallery">
			<?php foreach ( $gallery as $i => $src ) : ?>
				<img src="<?php echo esc_url( $src ); ?>" alt="<?php echo esc_attr( $title . ', фото ' . ( $i + 1 ) ); ?>" loading="lazy">
			<?php endforeach; ?>
		</div>
		<?php if ( $credit ) : ?><p class="ka-credit"><?php echo esc_html( $credit ); ?></p><?php endif; ?>
	</section>
	<?php endif; ?>

	<section class="ka-cta">
		<h2 class="ka-h2 ka-h2--white">Выбрать <b>дату</b></h2>
		<a class="ka-btn ka-btn--white" href="#ka-afisha">К афише</a>
	</section>
</main>
	<?php
endwhile;
get_footer();
