<?php
/** Главная: слайдер и афиша будущих концертов, как в index.php прежней темы, на данных Kozlov Core. */
get_header();

$view = kz_view();
$date = isset( $_GET['date'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ) ? $_GET['date'] : ( isset( $_GET['date1'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $_GET['date1'] ) ? $_GET['date1'] : '' );
$date2 = ( $date && isset( $_GET['date2'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $_GET['date2'] ) && $_GET['date2'] > $date ) ? $_GET['date2'] : '';
$filtered = (bool) $date;

if ( $date ) {
	// день или период: до 31 часа от начала последних суток, чтобы ночные концерты (23:59 и позже) не терялись
	$concerts = kozlov_concerts( array( 'from' => $date . ' 00:00:00', 'to' => wp_date( 'Y-m-d H:i:s', strtotime( ( $date2 ?: $date ) . ' +31 hours' ) ), 'past' => true, 'limit' => 600 ) );
} else {
	$concerts = kozlov_concerts( array( 'limit' => 600 ) );
}
$slides = $filtered ? array() : array_values( array_filter( $concerts, fn( $c ) => $c['slider']['show'] ) );
?>
<div class="content content_home">
	<div class="inner content_inner events_wrap view_<?php echo esc_attr( $view ); ?>">
		<div class="wrap">
			<div class="slider-wrapper">
			<?php if ( $slides ) : ?>
				<div class="slider">
					<div class="inner">
						<div class="wrap">
							<div class="slides">
								<?php foreach ( $slides as $s ) :
									$title = $s['slider']['title'] ?: $s['title'];
									$img   = $s['slider']['image'] ? (int) get_post_meta( $s['id'], 'kc_slider_img', true ) : $s['image']['id'];
									?>
									<div class="slide">
										<div class="slide_inner">
											<div class="wrap">
												<div class="thumbnail">
													<a href="<?php echo esc_url( $s['url'] ); ?>"><?php echo $img ? wp_get_attachment_image( $img, 'slider-thumb', false, array( 'fetchpriority' => 'high' ) ) : ''; ?></a>
												</div>
												<div class="info">
													<div class="top">
														<div class="datetime"><?php echo esc_html( $s['day'] . ' ' . $s['month_short'] . ' | ' . $s['time'] ); ?><?php if ( $s['stage'] ) : ?> | <span class="mansarda"><?php echo esc_html( $s['stage']['name'] ); ?></span><?php endif; ?></div>
														<div class="price"><?php echo esc_html( $s['price']['text'] ); ?></div>
													</div>
													<div class="title">
														<a href="<?php echo esc_url( $s['url'] ); ?>"><?php echo esc_html( $title ); ?><?php if ( $s['program'] ) : ?><span class="kz-sub"><?php echo esc_html( $s['program'] ); ?></span><?php endif; ?></a>
													</div>
													<div class="readmore"><?php echo kz_buy_button( $s ); // phpcs:ignore ?></div>
												</div> <!-- info -->
											</div>
										</div>
									</div>
								<?php endforeach; ?>
							</div> <!-- slides -->
							<div class="titles">
								<div class="titles_inner">
									<?php foreach ( $slides as $i => $s ) : ?>
										<div class="nav <?php echo 0 === $i ? 'active' : ''; ?>" data-pos="<?php echo (int) $i; ?>">
											<div class="title"><?php echo esc_html( $s['slider']['title'] ?: $s['title'] ); ?><?php if ( $s['program'] ) : ?><span class="kz-tab-sub"><?php echo esc_html( $s['program'] ); ?></span><?php endif; ?></div>
										</div>
									<?php endforeach; ?>
								</div>
							</div>
						</div>
					</div>
				</div>
			<?php endif; ?>
			</div> <!-- slider-wrapper -->

			<h1 class="home_h1">Джаз-клуб Алексея Козлова: афиша концертов в Москве</h1>
			<?php echo kz_viewtabs( $view ); // phpcs:ignore ?>
			<?php if ( $date ) : ?>
				<h2 class="afisha-heading">Концерты <?php echo esc_html( kz_range_label( $date, $date2 ) ); ?><small><a href="#" class="calendar-toggler">Другие даты</a> <a href="<?php echo esc_url( home_url( '/' ) ); ?>">Вся афиша</a></small></h2>
				<?php if ( ! $concerts ) : ?>
					<div class="afisha-empty"><?php echo $date2 ? 'В эти дни концертов нет.' : 'В этот день концертов нет.'; ?> <a href="<?php echo esc_url( home_url( '/' ) ); ?>">Вся афиша</a></div>
				<?php elseif ( $date2 && substr( $date, 0, 7 ) !== substr( $date2, 0, 7 ) ) : ?>
					<?php echo kz_afisha( $concerts, $view ); // phpcs:ignore ?>
				<?php else : ?>
					<div class="events view_<?php echo esc_attr( $view ); ?>"><?php echo implode( '', array_map( fn( $c ) => kz_event_card( $c, $view ), $concerts ) ); // phpcs:ignore ?></div>
				<?php endif; ?>
			<?php else : ?>
				<?php echo kz_afisha( $concerts, $view ); // phpcs:ignore ?>
			<?php endif; ?>
		</div>
	</div>
</div>
<?php if ( $filtered ) : ?>
	<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="go-to-home-mb"></a>
<?php endif; ?>
<?php get_footer(); ?>
