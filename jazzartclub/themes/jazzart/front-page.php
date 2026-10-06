<?php
/**
 * Главная: название клуба и ближайший концерт, карточки афиши, проекты клуба,
 * материалы, контакты. Концерты берутся из текста страницы «Анонсы».
 */
get_header();

$jac_paged = max( 1, (int) get_query_var( 'paged' ), (int) get_query_var( 'page' ) );

if ( $jac_paged > 1 ) :
	// Вторая и следующие страницы общего списка статей.
	?>
	<div class="wrap layout">
		<div class="sheet">
			<header class="page-head"><h1 class="page-title">Все материалы</h1></header>
			<?php if ( have_posts() ) : ?>
				<ul class="rows">
					<?php
					while ( have_posts() ) {
						the_post();
						get_template_part( 'parts/row' );
					}
					?>
				</ul>
				<?php jac_pagination(); ?>
			<?php endif; ?>
		</div>
		<?php get_template_part( 'parts/aside' ); ?>
	</div>
	<?php
	get_footer();
	return;
endif;

$jac_upcoming = jac_upcoming( 4 );
$jac_first    = $jac_upcoming ? $jac_upcoming[0] : null;
$jac_rest     = array_slice( $jac_upcoming, 1, 3 );
$jac_about    = get_page_by_path( 'about' );
$jac_contacts = get_page_by_path( 'contacts' );
$jac_address  = get_theme_mod( 'jac_address' );
$jac_phone    = get_theme_mod( 'jac_phone' );
$jac_email    = get_theme_mod( 'jac_email' );
?>

<section class="hero">
	<div class="wrap hero-grid">
		<div>
			<h1 class="hero-title">Джаз Арт Клуб</h1>
			<?php if ( get_bloginfo( 'description' ) ) : ?>
				<p class="hero-tagline"><?php bloginfo( 'description' ); ?></p>
			<?php endif; ?>
			<p class="hero-lead"><?php echo esc_html( get_theme_mod( 'jac_hero_text' ) ? get_theme_mod( 'jac_hero_text' ) : 'Московский джазовый клуб. Концерты, фестивали и джем-сейшны с 1994 года.' ); ?></p>
			<p class="hero-actions">
				<a class="button" href="<?php echo esc_url( jac_afisha_url() ); ?>">Смотреть афишу</a>
				<?php if ( $jac_about ) : ?>
					<a class="button is-quiet" href="<?php echo esc_url( get_permalink( $jac_about ) ); ?>">О клубе</a>
				<?php endif; ?>
			</p>
		</div>

		<?php if ( $jac_first ) : ?>
			<a class="hero-concert" href="<?php echo esc_url( jac_afisha_url( $jac_first ) ); ?>">
				<span class="hero-photo">
					<?php if ( $jac_first['image_full'] ) : ?>
						<img src="<?php echo esc_url( jac_img( $jac_first['image_full'], 1440 ) ); ?>" alt="">
					<?php endif; ?>
				</span>
				<span class="hero-concert-body">
					<?php echo jac_date_badge( $jac_first ); ?>
					<span class="hero-concert-text">
						<span class="hero-concert-label">Ближайший концерт</span>
						<span class="hero-concert-title"><?php echo esc_html( $jac_first['title'] ); ?></span>
						<?php if ( $jac_first['time'] ) : ?>
							<span class="hero-concert-time">Начало в <?php echo esc_html( $jac_first['time'] ); ?></span>
						<?php endif; ?>
					</span>
				</span>
			</a>
		<?php else : ?>
			<span class="hero-logo-panel" aria-hidden="true"></span>
		<?php endif; ?>
	</div>
</section>

<?php if ( $jac_rest ) : ?>
	<section class="section" aria-labelledby="afisha-title">
		<div class="wrap">
			<div class="section-head">
				<h2 class="section-title" id="afisha-title">Ближайшие концерты</h2>
				<a class="more-link" href="<?php echo esc_url( jac_afisha_url() ); ?>">Вся афиша</a>
			</div>
			<ul class="cards">
				<?php
				foreach ( $jac_rest as $jac_event ) {
					get_template_part( 'parts/event-card', null, array( 'event' => $jac_event ) );
				}
				?>
			</ul>
		</div>
	</section>
<?php endif; ?>

<?php $jac_projects = jac_project_items(); ?>
<?php if ( $jac_projects ) : ?>
	<section class="section section-tint" aria-labelledby="projects-home-title">
		<div class="wrap">
			<div class="section-head"><h2 class="section-title" id="projects-home-title">Проекты клуба</h2></div>
			<ul class="tiles">
				<?php foreach ( $jac_projects as $jac_item ) : ?>
					<li class="tile">
						<a href="<?php echo esc_url( $jac_item['url'] ); ?>">
							<span class="tile-photo"><?php if ( $jac_item['image'] ) : ?><img src="<?php echo esc_url( jac_img( $jac_item['image'] ) ); ?>" alt="" loading="lazy"><?php endif; ?></span>
							<span class="tile-text">
								<span class="tile-title"><?php echo esc_html( $jac_item['title'] ); ?></span>
								<?php if ( $jac_item['text'] ) : ?><span class="tile-note"><?php echo esc_html( $jac_item['text'] ); ?></span><?php endif; ?>
							</span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
	</section>
<?php endif; ?>

<?php
$jac_news_term = get_term_by( 'slug', 'novosti', 'category' );
$jac_news      = new WP_Query( array( 'post_type' => 'post', 'posts_per_page' => 4, 'ignore_sticky_posts' => true, 'no_found_rows' => true ) );
if ( $jac_news->have_posts() ) :
	?>
	<section class="section" aria-labelledby="news-title">
		<div class="wrap">
			<div class="section-head">
				<h2 class="section-title" id="news-title">Новости и материалы</h2>
				<?php if ( $jac_news_term ) : ?>
					<a class="more-link" href="<?php echo esc_url( get_category_link( $jac_news_term ) ); ?>">Все материалы</a>
				<?php endif; ?>
			</div>
			<ul class="rows news">
				<?php
				while ( $jac_news->have_posts() ) {
					$jac_news->the_post();
					get_template_part( 'parts/row' );
				}
				wp_reset_postdata();
				?>
			</ul>
		</div>
	</section>
<?php endif; ?>

<?php if ( $jac_address || $jac_phone || $jac_email ) : ?>
	<section class="section section-tint" aria-labelledby="visit-title">
		<div class="wrap">
			<div class="section-head"><h2 class="section-title" id="visit-title">Для посетителей</h2></div>
			<ul class="visit">
				<?php if ( $jac_address ) : ?>
					<li>
						<span class="visit-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s7-6.1 7-11.5A7 7 0 0 0 5 9.500C5 14.9 12 21 12 21z"/><circle cx="12" cy="9.500" r="2.500"/></svg></span>
						<div>
							<span class="visit-title">Как добраться</span>
							<p><?php echo esc_html( $jac_address ); ?></p>
							<?php if ( $jac_contacts ) : ?><p><a class="more-link" href="<?php echo esc_url( get_permalink( $jac_contacts ) ); ?>">Контакты</a></p><?php endif; ?>
						</div>
					</li>
				<?php endif; ?>
				<?php if ( $jac_phone ) : ?>
					<li>
						<span class="visit-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 4h4l2 5-2.500 1.500a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z"/></svg></span>
						<div>
							<span class="visit-title">Телефон</span>
							<p class="big"><a href="tel:<?php echo esc_attr( preg_replace( '~[^\d+]~', '', $jac_phone ) ); ?>"><?php echo esc_html( $jac_phone ); ?></a></p>
							<p>Заказ мест и справки</p>
						</div>
					</li>
				<?php endif; ?>
				<?php if ( $jac_email ) : ?>
					<li>
						<span class="visit-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3.500 7 8.500 6 8.500-6"/></svg></span>
						<div>
							<span class="visit-title">Написать</span>
							<p><a href="mailto:<?php echo esc_attr( antispambot( $jac_email ) ); ?>"><?php echo esc_html( antispambot( $jac_email ) ); ?></a></p>
						</div>
					</li>
				<?php endif; ?>
			</ul>
		</div>
	</section>
<?php endif; ?>

<?php
get_footer();
