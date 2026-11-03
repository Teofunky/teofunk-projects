<?php
/** Страница концерта: перенос single.php прежней темы на Kozlov Core. */
get_header();
the_post();
$c = kozlov_concert( get_the_ID() );
$upcoming = $c && ! $c['is_past'];

// соседние концерты по дате (только будущие), как раньше
$prev = $next = null;
if ( $upcoming ) {
	$p = kozlov_concerts( array( 'to' => $c['start'], 'limit' => 3, 'order' => 'DESC' ) );
	foreach ( $p as $x ) { if ( $x['id'] !== $c['id'] && $x['start'] <= $c['start'] ) { $prev = $x; break; } }
	$n = kozlov_concerts( array( 'from' => $c['start'], 'limit' => 3 ) );
	foreach ( $n as $x ) { if ( $x['id'] !== $c['id'] && $x['start'] >= $c['start'] ) { $next = $x; break; } }
}
// другие даты того же артиста: по тегам концерта
$other = array();
$tags = wp_get_post_terms( $c['id'], 'kc_tag', array( 'fields' => 'ids' ) );
if ( $tags && ! is_wp_error( $tags ) ) {
	$ids = get_posts( array( 'post_type' => 'concert', 'posts_per_page' => 12, 'fields' => 'ids', 'post__not_in' => array( $c['id'] ), 'tax_query' => array( array( 'taxonomy' => 'kc_tag', 'field' => 'term_id', 'terms' => $tags ) ), 'meta_key' => 'kc_start', 'orderby' => 'meta_value', 'order' => 'ASC', 'meta_query' => array( array( 'key' => 'kc_start', 'value' => wp_date( 'Y-m-d H:i:s' ), 'compare' => '>=' ) ) ) );
	foreach ( $ids as $id ) { $other[] = kozlov_concert( $id ); }
}
$nav = function () use ( $prev, $next ) {
	if ( ! $prev && ! $next ) { return; }
	echo '<div class="concert_nav">';
	if ( $prev ) { echo '<div class="concert_nav_item prev"><a href="' . esc_url( $prev['url'] ) . '">' . esc_html( $prev['day'] . ' ' . $prev['month_short'] ) . '</a></div>'; }
	echo '<div class="concert_nav_item afisha"><a href="' . esc_url( home_url( '/' ) ) . '">афиша</a></div>';
	if ( $next ) { echo '<div class="concert_nav_item next"><a href="' . esc_url( $next['url'] ) . '">' . esc_html( $next['day'] . ' ' . $next['month_short'] ) . '</a></div>'; }
	echo '</div>';
};
$m = fn( $k ) => get_post_meta( $c['id'], $k, true );
?>
<div class="content content_page">
	<div class="inner content_inner content_page_inner">
		<div class="wrap">
			<div class="post page_post">
				<div class="post_content page_post_content event_page_post_content clearfix">
					<?php $nav(); ?>
					<div class="intro">
						<div class="info <?php echo $upcoming ? 'upcoming' : ''; ?>">
							<div class="datetime">
								<div class="cell_1"><?php echo esc_html( $c['day'] ); ?></div>
								<div class="cell_2">
									<div class="row_1"><?php echo esc_html( $c['month_short'] . ' | ' . $c['time'] ); ?></div>
									<div class="row_2"><?php echo esc_html( $c['weekday'] ); ?></div>
									<?php if ( $c['stage'] ) : ?><div class="row_3"><a href="<?php echo esc_url( $c['stage']['url'] ); ?>"><?php echo esc_html( $c['stage']['name'] ); ?></a></div><?php endif; ?>
								</div>
							</div>
							<div class="title"><h1><?php echo esc_html( $c['title'] ); ?></h1><?php if ( $c['program'] ) : ?><div class="kz-program"><?php echo esc_html( $c['program'] ); ?></div><?php endif; ?></div>
							<?php if ( $upcoming ) : ?>
								<div>
									<div class="price"><?php echo esc_html( $c['price']['text'] ); ?></div>
									<div class="readmore"><?php echo kz_buy_button( $c, false ); // phpcs:ignore ?></div>
								</div>
							<?php endif; ?>
						</div> <!-- info -->
						<div class="row-block" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap">
							<?php if ( $upcoming ) : ?>
								<div class="row-block-item"><div class="add_to_calendar"><a href="<?php echo esc_url( add_query_arg( 'kz_ics', 1, $c['url'] ) ); ?>">Добавить в календарь</a></div></div>
							<?php endif; ?>
							<?php if ( $c['genres'] ) : ?>
								<div class="row-block-item"><div class="genres-categories" style="padding:15px 0 0;text-transform:uppercase;font-size:12px;font-weight:500">Жанры: <?php echo kz_genre_links( $c ); // phpcs:ignore ?></div></div>
							<?php endif; ?>
						</div>
						<?php if ( $other ) : ?>
							<div class="event-other-dates-list">
								<div class="event-other-dates-title">Другие даты выступления:</div>
								<div class="event-other-dates-items">
									<?php foreach ( $other as $o ) : ?><div class="event-other-dates-item"><a href="<?php echo esc_url( $o['url'] ); ?>" class="event-other-dates-item-link"><?php echo esc_html( $o['day'] . ' ' . mb_strtolower( $o['month_short'] ) . ' ' . substr( $o['date'], 0, 4 ) ); ?></a></div><?php endforeach; ?>
								</div>
							</div>
						<?php endif; ?>
						<?php if ( $c['image']['id'] ) : ?>
							<div class="anons_thumb meta_block"><?php echo wp_get_attachment_image( $c['image']['id'], 'large', false, array( 'fetchpriority' => 'high' ) ); ?></div>
						<?php endif; ?>
					</div>
					<div class="description">
						<?php $partners = (array) $m( 'kc_partners' ); $ptitle = $m( 'kc_partners_title' ); ?>
						<?php if ( $partners || $ptitle ) : ?>
							<div class="partners_support meta_block">
								<div class="title"><?php echo esc_html( $ptitle ?: 'При поддержке' ); ?></div>
								<ul class="items"><?php foreach ( $partners as $p ) : if ( empty( $p['image'] ) ) continue; ?><li><a <?php echo ! empty( $p['link'] ) ? 'target="_blank" rel="noopener" href="' . esc_url( $p['link'] ) . '"' : ''; ?>><?php echo wp_get_attachment_image( (int) $p['image'], 'medium' ); ?></a></li><?php endforeach; ?></ul>
							</div>
						<?php endif; ?>
						<?php if ( $q = $m( 'kc_quote' ) ) : ?>
							<div class="quote meta_block"><div class="desc">&laquo;<?php echo wp_kses_post( $q ); ?>&raquo;</div><?php if ( $qa = $m( 'kc_quote_author' ) ) : ?><div class="author"><?php echo esc_html( $qa ); ?></div><?php endif; ?></div>
						<?php endif; ?>
						<?php if ( ! $c['tc_event'] && $c['booking'] ) :
							$dt = kz_setting( 'deposit' === $c['price']['type'] ? 'deposit_text' : 'no_deposit_text', '' );
							if ( $dt ) : ?><div class="textblock deposit_block"><div class="deposit_block_text"><?php echo wp_kses_post( $dt ); ?></div></div><?php endif; ?>
						<?php endif; ?>
						<?php if ( $t = $m( 'kc_text_1' ) ) : ?><div class="textblock meta_block"><?php echo wp_kses_post( wpautop( $t ) ); ?></div><?php endif; ?>
						<?php foreach ( (array) $m( 'kc_artists' ) as $a ) : if ( empty( $a['text'] ) && empty( $a['image'] ) ) continue; ?>
							<div class="artist_desc meta_block">
								<?php if ( ! empty( $a['image'] ) && wp_get_attachment_image_url( (int) $a['image'], 'medium' ) ) : ?><div class="img"><?php echo wp_get_attachment_image( (int) $a['image'], 'medium' ); ?></div><?php endif; ?>
								<div class="text"><?php if ( ! empty( $a['name'] ) ) : ?><p><strong><?php echo esc_html( $a['name'] ); ?></strong></p><?php endif; ?><?php echo wp_kses_post( wpautop( $a['text'] ?? '' ) ); ?></div>
							</div>
						<?php endforeach; ?>
						<?php if ( $t = $m( 'kc_text_2' ) ) : ?><div class="textblock meta_block"><?php echo wp_kses_post( wpautop( $t ) ); ?></div><?php endif; ?>
						<?php if ( get_the_content() ) : ?><div class="textblock meta_block"><?php the_content(); ?></div><?php endif; ?>
						<?php foreach ( (array) $m( 'kc_videos' ) as $v ) : ?><div class="youtube meta_block"><div class="youtube-video"><?php echo kz_video_embed( $v ); // phpcs:ignore ?></div></div><?php endforeach; ?>
						<?php if ( $t = $m( 'kc_additional' ) ) : ?><div class="textblock meta_block"><?php echo wp_kses_post( wpautop( $t ) ); ?></div><?php endif; ?>
						<div class="bottom_block">
							<?php if ( $upcoming && ( $c['can_buy'] || $c['booking'] ) ) : ?><div class="buy_button meta_block"><?php echo kz_buy_button( $c, false ); // phpcs:ignore ?></div><?php endif; ?>
							<div class="share_panel meta_block">
								<ul class="items">
									<li><span>Поделиться</span></li>
									<li><a href="https://vk.com/share.php?url=<?php echo rawurlencode( $c['url'] ); ?>" target="_blank" rel="noopener"><img src="<?php echo esc_url( get_template_directory_uri() ); ?>/assets/icons/share_vk.png" alt="ВКонтакте" /></a></li>
									<li><a href="https://t.me/share/url?url=<?php echo rawurlencode( $c['url'] ); ?>&text=<?php echo rawurlencode( $c['title'] ); ?>" target="_blank" rel="noopener"><img src="<?php echo esc_url( get_template_directory_uri() ); ?>/assets/icons/telegram.png" alt="Telegram" style="width:26px" /></a></li>
								</ul>
							</div>
						</div>
					</div>
					<?php $nav(); ?>
				</div>
			</div>
		</div>
	</div>
</div>
<?php get_footer(); ?>
