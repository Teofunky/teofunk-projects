<?php // шаблон постов (статей) ?>
<?php get_header(); ?>
<?php
$lang = WPGlobus::Config()->language;

$start = time();

// Предпросмотр черновика: сам черновик попадает в выборку только при предпросмотре
// и только для того, кто может его редактировать. Для всех остальных 0, запрос прежний.
$kz_preview_id = (int) get_queried_object_id();
if ( ! ( is_preview() && $kz_preview_id && get_post_status( $kz_preview_id ) !== 'publish' && current_user_can( 'edit_post', $kz_preview_id ) ) ) {
	$kz_preview_id = 0;
}

$query = "SELECT wp.id, wpei.start, wpei.end, CONCAT('/event/', wp.post_name, '/') as url, wpe.cost, wp.post_status
		FROM wp_ai1ec_event_instances AS wpei
		LEFT JOIN wp_posts AS wp ON wp.id = wpei.post_id
		LEFT JOIN wp_ai1ec_events AS wpe ON wpe.post_id = wpei.post_id
		-- WHERE wp.post_status <> 'inherit'
		WHERE ( wp.post_status = 'publish' OR wp.id = {$kz_preview_id} )
		ORDER BY wpei.start";

$events = $wpdb->get_results($query);

$published_events = array();
foreach ($events as $key => $post) {
	if ( $post->post_status == 'publish' ) {
		$published_events[] = $post;
	}
}

$prev_concert = '';
$next_concert = '';

?>

<div class="content content_page">
	<div class="inner content_inner content_page_inner">
		<div class="wrap">
			<div class="post page_post">
				<div class="post_content page_post_content event_page_post_content clearfix">

					<?php if ( have_posts() ) : while ( have_posts() ) : the_post(); ?>

						<?php

						$post_id = get_the_ID();
						$post_title = get_the_title();

						foreach ($events as $key => $post) {
							if ( $post->id == $post_id ) {
								$event = $post;
							}
						}

						$meta = get_post_meta($event->id);

						$artistList = $meta['artist_list'][0];

						// echo '<pre>';
						// var_dump($event);
						// var_dump($artistList);
						// echo '</pre>';

						$artistOtherEventsIds = [];
						$notSet = 'a:1:{i:0;s:17:"Не выбран";}';
						
						foreach ( $published_events as $key => $post ) {

							if ($artistList != NULL && $artistList != $notSet) {
								if ($post->start > $start && $post->id != $post_id) {
									$post_meta = get_post_meta($post->id);
									$post_artistList = $post_meta['artist_list'][0];
									if ($post_artistList == $artistList) {
										$arItem = [
											'id' => $post->id,
											'date' => date('d', $post->start) . ' ' . mb_strtolower(_month(date('n', $post->start), $lang)) . ' ' . date('Y', $post->start),
											'url' => $post->url,
										];
										$artistOtherEventsIds[] = $arItem;
										// echo '<pre>';
										// // var_dump($post->id);
										// var_dump($post);
										// echo '</pre>';
									}
								}
							}

							if ( $post->id == $post_id ) {
								// $event = $post;
								if ( $post->start > $start ) {
									if ( $published_events[$key - 1]->start > $start ) {
										$prev_concert = $published_events[$key - 1];
									}
									$next_concert = $published_events[$key + 1];
								}
							}
						}

						if ( !empty($prev_concert) ) {
							$prev_event_date = array();
							$prev_event_date['date'] = date('d', $prev_concert->start);
							$prev_event_date['month'] = _month( date('n', $prev_concert->start), $lang );
							$prev_event_date['time'] = date('H:i', $prev_concert->start);
							$prev_event_date['day'] = _day( date('w', $prev_concert->start), $lang);
							$prev_event_date['month1'] = date('n', $prev_concert->start);
						}

						if ( !empty($next_concert) ) {
							$next_event_date = array();
							$next_event_date['date'] = date('d', $next_concert->start);
							$next_event_date['month'] = _month( date('n', $next_concert->start), $lang );
							$next_event_date['time'] = date('H:i', $next_concert->start);
							$next_event_date['day'] = _day( date('w', $next_concert->start), $lang);
							$next_event_date['month1'] = date('n', $next_concert->start);
						}
						?>
					<?php if ( !empty($prev_concert) || !empty($next_concert) ) { ?>
						<div class="concert_nav">
							<?php if ( !empty($prev_concert) ) { ?>
								<div class="concert_nav_item prev">
									<a href="<?php echo $prev_concert->url ?>"><?php echo($prev_event_date['date'].' '.$prev_event_date['month']) ?></a>
								</div>
							<?php } ?>

							<?php if ( !empty($next_concert) ) { ?>
								<div class="concert_nav_item next">
									<a href="<?php echo $next_concert->url ?>"><?php echo($next_event_date['date'].' '.$next_event_date['month']) ?></a>
								</div>
							<?php } ?>
						</div> <!-- concert-nav -->
					<?php } ?>

					<div class="intro">


						<?php $is_upcoming = ( $event->start > $start ) ? true : false ?>
						<div class="info <?php if ($is_upcoming) echo 'upcoming' ?>">
							<?php
							$event_date = array();
							$event_date['date'] = date('d', $event->start);
							$event_date['month'] = _month( date('n', $event->start), $lang );
							$event_date['time'] = date('H:i', $event->start);
							$event_date['day'] = _day( date('w', $event->start), $lang);
							$event_date['month1'] = date('n', $event->start);
							?>
							<div class="datetime">
								<div class="cell_1"><?php echo $event_date['date'] ?></div>
								<div class="cell_2">
									<div class="row_1"><?php echo($event_date['month'].' | '.$event_date['time']) ?></div>
									<div class="row_2"><?php echo $event_date['day'] ?></div>
									<?php
									$arResStr = [];
									if ($meta['location_mansarda'][0]) {
										if ($lang == 'ru') {
											$arResStr[] = 'Мансарда';
										} else if ( $lang == ['en'] ) {
											$arResStr[] = 'Mansard';
										}
									}

									if ($meta['location_the_beat'][0]) {
										$arResStr[] = 'The Beat';
									}

									if ($meta['location_main_scene'][0]) {
										if ($lang == 'ru') {
											$arResStr[] = 'Главная сцена';
										} else if ( $lang == ['en'] ) {
											$arResStr[] = 'Main Scene';
										}
									}

                  if ($meta['location_myasnitskaya'][0]) {
										if ($lang == 'ru') {
											$arResStr[] = 'Сцена на Мясницкой, 15';
										} else if ( $lang == ['en'] ) {
											$arResStr[] = 'Scene Myasnitskaya, 15';
										}
									}

									if (!empty($arResStr)) {
										echo '<div class="row_3">' . implode(' | ', $arResStr) , '</div>';
									}
									?>
								</div>
							</div>

							<div class="title">
								<h1><?php echo $post_title ?></h1>
							</div>

							<?php if ( $is_upcoming ) { ?>
                <div>
                  <div class="price">
  									<?php
  									$event_cost = preg_split("/[\[\]]+/", is_array(unserialize($event->cost)) ? unserialize($event->cost)['cost'] : $event->cost);
  									if ( $lang == 'ru' ) echo $event_cost[2];
  									else if ( $lang == 'en' ) echo $event_cost[4];

                    // $event_cost = preg_split("/[\[\]]+/", is_array(unserialize($event->cost)) ? unserialize($event->cost)['cost'] : $event->cost);
                    // if ($lang == 'ru') {
                    //   if (mb_strtolower(trim($event_cost[2])) === 'вход свободный') echo 'Депозит 1500 руб.';
                    //   else echo $event_cost[2];
                    // } else if ($lang == 'en') {
                    //   if (mb_strtolower(trim($event_cost[4])) === 'free entrance') echo '1500 RUR deposit';
                    //   else echo $event_cost[4];
                    // }
  									?>
  								</div>

                  <div class="readmore">
                    <?
    								$ticketcloud = [];
                    $need_booking = $meta['need_booking'][0];
                    $ticketcloud['link'] = $meta['ticketscloud_link'][0];
                    $ticketcloud['id'] = $meta['ticketcloudid'][0];
                    $ticketcloud['id'] = $meta['ticketcloudid'][0];
										$isRegisterBtn = $meta['btn_text_register'][0];
                    ?>

                    <? if (!empty($ticketcloud['link']) && !empty($ticketcloud['id'])) { ?>
  										<div class="btn-widget">
  											<button class="tc-background-tomato" type="button" tc-event="<?php echo $ticketcloud['link'] ?>"><? if ($isRegisterBtn) echo 'Регистрация'; else echo 'Купить билет' ?></button>
  										</div>
  										<? if ( $lang == 'ru' ) { ?>
  											<a class="btn-widget-buy"><? if ($isRegisterBtn) echo 'регистрация'; else echo 'купить билет' ?></a>
  										<? } else if ( $lang == 'en' ) { ?>
  											<a class="btn-widget-buy"><? if ($isRegisterBtn) echo 'register'; else echo 'buy ticket' ?></a>
  										<? } ?>
                    <? } else if (!empty($need_booking)) { ?>
                      <? if ( $lang == 'ru' ) { ?>
                        <a class="btn-widget-read open__primary__widget" role="button">Забронировать столик</a>
                      <? } else if ( $lang == 'en' ) { ?>
                        <a class="btn-widget-read open__primary__widget" role="button">Book a table</a>
                      <? } ?>
                    <? } ?>
                  </div> <!-- readmore-->
                </div>
							<? } ?>
						</div> <!-- info -->

            <div class="row-block" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap;">
              <?php if ( $is_upcoming ) { ?>
                <div class="row-block-item">
                  <div class="add_to_calendar">
    								<?php $calendar_link = '/?plugin=all-in-one-event-calendar&controller=ai1ec_exporter_controller&action=export_events&ai1ec_post_ids='.$event->id; ?>
    								<?php if ( $lang == 'ru' ) { ?>
    									<a href="<?php echo $calendar_link ?>" ?>Добавить в календарь</a>
    								<?php } else if ( $lang == 'en' ) { ?>
    									<a href="<?php echo $calendar_link ?>" ?>Add to calendar</a>
    								<?php } ?>
    							</div>
                </div>
  						<?php } ?>
              <div class="row-block-item">
                <?php
								$genre_categories = $meta['genre_categories'][0];
                // var_dump($genre_categories);
								$genre_categories_ar = unserialize($genre_categories);
                // var_dump($genre_categories_ar);
                // pre($lang);
                $arGenresLang = [];
                if ($genre_categories_ar) foreach ($genre_categories_ar as $gc) {
                  $_gcRu = explode('|', $gc)[0];
                  $_gcEn = explode('|', $gc)[1];
                  // $arGenresLang = [];
                  // var_dump($_gcEn);
                  $_gcLink = '#';
                  $_gcTitle = '';
                  if ($lang == 'ru' && isset($_gcRu)) {
                    $_gcTitle = $_gcRu;
                    if (isset($_gcEn)) {
                      $_gcLink = '/?genre=' . mb_strtolower($_gcEn);
                    }
                  } else if ($lang == 'en' && isset($_gcEn)) {
                    $_gcTitle = $_gcEn;
                    $_gcLink = '/en/?genre=' . mb_strtolower($_gcEn);
                  }
                  // var_dump($_gcTitle);

                  if (!empty($_gcTitle)) {
                    $arGenresLang[] = '<a href="' . $_gcLink . '">' . $_gcTitle . '</a>';
                  }
                }
                // pre($arGenresLang);
								$genre_categories_str = implode(', ', $arGenresLang);
								// var_dump($genre_categories_str);
								?>
								<?php if (!empty($genre_categories)) { ?>
									<div class="genres-categories" style="padding: 15px 0 0; text-transform: uppercase; font-size: 12px; font-weight: 500;">
                    <?
                    if ($lang == 'ru') {
                      $genre_str = 'Жанры: ';
                    } else if ($lang == 'en') {
                      $genre_str = 'Genres: ';
                    }
                    ?>
										<?= $genre_str . $genre_categories_str ?>
									</div>
								<?php }?>
              </div>
            </div>

						<? if ($artistOtherEventsIds) { ?>
							<div class="event-other-dates-list">
								<div class="event-other-dates-title">Другие даты выступления:</div>
								<?
								// echo '<pre>';
								// var_dump($artistOtherEventsIds);
								// echo '</pre>';
								?>
								<div class="event-other-dates-items">
									<? foreach ($artistOtherEventsIds as $ev) { ?>
										<div class="event-other-dates-item">
											<a href="<?= $ev['url'] ?>" class="event-other-dates-item-link"><?= $ev['date'] ?></a>
										</div>
									<? } ?>
								</div>
							</div>
						<? } ?>


						<?php $anons_thumb = $meta['anons_img_thumb'][0] ?>
						<?// pre($meta['anons_img_alt']) ?>
						<?php if ( !empty ($anons_thumb) ) { ?>
							<div class="anons_thumb meta_block">
								<img src="/wp-content/files_mf/<?php echo $anons_thumb ?>" alt="<?= $meta['anons_img_alt'][0] ?>" />
							</div>
						<?php } ?>
					</div>


					<div class="description">
						<?php // the_content() ?>

						<?php
						$partners_support_image = $meta['partners_support_image'];
						$partners_support_link = $meta['partners_support_link'];
						$partners_support_alt = $meta['partners_support_alt'];
						$partners_support_title = $meta['partners_support_title_name'];

						if ( !empty($partners_support_image[0]) ) {
							$partners_support = array();
							foreach ( $partners_support_image as $key => $value ) {
								$partners_support[] = array(
									'img' => $partners_support_image[$key],
									'alt' => $partners_support_alt[$key],
									'link' => $partners_support_link[$key],
								);
							}
						}
						?>


						<?php // PARTNERS SUPPORT ?>
						<?php if ( !empty($partners_support) || !empty($partners_support_title[0]) ) { ?>
							<div class="partners_support meta_block">
								<?php
								if ( !empty($partners_support_title[0]) ) {
									$_partners_support_title = $partners_support_title[0];
								} else {
									if ( $lang == 'ru' ) {
										$_partners_support_title = 'При поддержке';
									} else if ( $lang == 'en' ) {
										$_partners_support_title = 'Supported by';
									}
								}
								?>
								<div class="title"><?php echo $_partners_support_title ?></div>
								<ul class="items">
								<?php if ( !empty($partners_support) ) foreach ( $partners_support as $key => $value ) { ?>
									<li>
										<a <?php if ( !empty($value['link']) ) print('target="_balnk" href="'.$value['link'].'"') ?>>
											<img src="/wp-content/files_mf/<?php echo $value['img']?>" alt="<?= $value['alt'] ?>" />
										</a>
									</li>
								<?php } ?>
								</ul>
							</div> <!-- partners-support -->
						<?php } ?>


						<?php // QUOTEMARK ?>
						<?php $quote_text = $meta['quote_text'] ?>
						<?php if ( !empty($quote_text[0]) ) { ?>
							<div class="quote meta_block">
								<div class="desc">&laquo;<?php echo $quote_text[0];?>&raquo;</div>
								<?php $quote_author = $meta['quote_author'] ?>
								<?php if ( !empty($quote_author[0]) ) { ?>
									<div class="author"><?php echo $quote_author[0] ?></div>
								<?php } ?>
							</div>
						<?php } ?>

            <? if (empty($meta['ticketscloud_link'][0]) && empty($meta['ticketcloudid'][0])) { ?>
              <? if (!empty($meta['need_booking'][0])) { ?>

                <?
                if (!empty($meta['need_deposit'][0])) {
                  $deposit_text = get_option('custom_deposit_text');
                } else {
                  $deposit_text = get_option('custom_no_deposit_text');
                }
                ?>

                <? if ($deposit_text) { ?>
                  <div class="textblock deposit_block">
                    <div class="deposit_block_text"><?= wp_kses_post( $deposit_text ) // Важно: wp_kses_post ?></div>

                    <? /*
                    <div class="readmore">
                    <?php if ( $lang == 'ru' ) { ?>
                    <a class="btn-widget-read open__primary__widget" role="button">Забронировать столик</a>
                    <?php } else if ( $lang == 'en' ) { ?>
                    <a class="btn-widget-read open__primary__widget" role="button">Book a table</a>
                    <?php } ?>
                    </div>
                    */ ?>

                  </div>
                <? } ?>
              <? } ?>

            <? } ?>

						<?php // TEXTBLOCK ?>
						<?php $textblock_1 = $meta['textblock_1_description'] ?>
						<?php if ( !empty($textblock_1[0]) ) { ?>
							<div class="textblock meta_block"><?php echo $textblock_1[0]?></div>
						<?php } ?>


						<?php // ARTIST DESCRIPTION ?>
						<?php $artist_description = $meta['artist_description'] ?>
						<?php if ( !empty($artist_description[0]) ) { ?>
							<?php
							$artist_bio = array();
							$artist_image = $meta['artist_image'];
							$artist_alt = $meta['artist_alt'];
							foreach ( $artist_description as $key => $value ) {
								$artist_bio[] = array(
									'img' => $artist_image[ $key ],
									'alt' => $artist_alt[$key],
									'text' => $artist_description[ $key ],
								);
							}
							if ( !empty($artist_bio) ) foreach ( $artist_bio as $key => $value ) { ?>
							<div class="artist_desc meta_block">
								<div class="img">
									<img src="/wp-content/files_mf/<?php echo $value['img'] ?>" alt="<?= $value['alt'] ?>" />
								</div>
								<div class="text">
									<?php echo $value['text'] ?>
								</div>
							</div>
							<?php } ?>
						<?php } ?>


						<?php // TEXTBLOCK ?>
						<?php $textblock_2 = $meta['textblock_2_description'] ?>
						<?php if ( !empty($textblock_2[0]) ) { ?>
							<div class="textblock meta_block"><?php echo $textblock_2[0]?></div>
						<?php } ?>


						<?php // YOUTUBE ?>
						<?php $youtube_video = $meta['youtube_source'] ?>
							<?php if ( !empty($youtube_video[0]) ) foreach ( $youtube_video as $key => $value ) { ?>
							<div class="youtube meta_block">
								<div class="youtube-video"><?php echo $value ?></div>
							</div>
						<?php } ?>


						<?php // TEXTBLOCK ?>
						<?php $additioanl_block =$meta['additional_text_description'] ?>
						<?php if ( !empty($additioanl_block[0]) ) { ?>
							<div class="textblock meta_block"><?php echo $additioanl_block[0] ?></div>
						<?php } ?>


						<?php // BOTTOM BLOCK ?>
						<div class="bottom_block">
							<?php if ($event->start > $start) { ?>
								<div class="buy_button meta_block">
                  <?
                  $ticketcloud = [];
                  $need_booking = $meta['need_booking'][0];
                  $ticketcloud['link'] = $meta['ticketscloud_link'][0];
                  $ticketcloud['id'] = $meta['ticketcloudid'][0];
									$isRegisterBtn = $meta['btn_text_register'][0];
                  ?>

                  <? if (!empty($ticketcloud['link']) && !empty($ticketcloud['id'])) { ?>
                    <div class="btn-widget">
                      <button class="tc-background-tomato" type="button" tc-event="<?php echo $ticketcloud['link'] ?>"><? if ($isRegisterBtn) echo 'Регистрация'; else echo 'Купить билет' ?></button>
                    </div>
                    <? if ( $lang == 'ru' ) { ?>
                      <a class="btn-widget-buy"><? if ($isRegisterBtn) echo 'регистрация'; else echo 'купить билет' ?></a>
                    <? } else if ( $lang == 'en' ) { ?>
                      <a class="btn-widget-buy"><? if ($isRegisterBtn) echo 'register'; else echo 'buy ticket' ?></a>
                    <? } ?>
                  <? } else if (!empty($need_booking)) { ?>
                    <? if ( $lang == 'ru' ) { ?>
                      <a class="btn-widget-read open__primary__widget" role="button">Забронировать столик</a>
                    <? } else if ( $lang == 'en' ) { ?>
                      <a class="btn-widget-read open__primary__widget" role="button">Book a table</a>
                    <? } ?>
                  <? } ?>
								</div> <!-- buy_button-->
							<?php } ?>

							<?php
							$share_lang = array( 'ru' => 'Поделиться', 'en' => 'Share' );
							$share_url = 'http://'.$_SERVER['SERVER_NAME'].$_SERVER['REQUEST_URI'];
							?>
							<div class="share_panel meta_block">
								<ul class="items">
									<li><span><?php echo $share_lang[$lang]?></span></li>
									<li><a href="http://vk.com/share.php?url=<?php echo $share_url ?>" target="_blank"><img src="<?php bloginfo('template_directory');?>/assets/icons/share_vk.png" /></a></li>

						</div> <!-- bottom_block-->

					</div>

					<?php if ( !empty($prev_concert) || !empty($next_concert) ) {?>
						<div class="concert_nav">
							<?php if ( !empty($prev_concert) ) { ?>
								<div class="concert_nav_item prev">
									<a href="<?php echo $prev_concert->url ?>"><?php echo($prev_event_date['date'].' '.$prev_event_date['month']) ?></a>
								</div>
							<?php } ?>

							<div class="concert_nav_item afisha">
								<?php if ( $lang == 'ru' ) { ?>
									<a href="/">афиша</a>
								<?php } else if ( $lang == 'en' ) { ?>
									<a href="/<?php echo $lang ?>/">afisha</a>
								<? } ?>
							</div>

							<?php if ( !empty($next_concert) ) { ?>
								<div class="concert_nav_item next">
									<a href="<?php echo $next_concert->url ?>"><?php echo($next_event_date['date'].' '.$next_event_date['month']) ?></a>
								</div>
							<?php } ?>
						</div> <!-- concert-nav -->
					<?php } ?>



					<?php endwhile; endif; ?>


				</div> <!-- .event_page_post_content -->
			</div> <!-- .page_post -->
		</div>
	</div>
</div>

<a href="<? if ($lang == 'ru') { echo '/'; } else if ($lang == 'en') { echo '/en/'; } ?>" class="go-to-home-mb">
<? /*
  <? if ($lang == 'ru') { ?>
    <a href="/">Вернуться на главную</a>
  <? } else if ($lang == 'en') { ?>
    <a href="/en/">Go Home</a>
  <? } ?>
*/ ?>
</a>
<?php get_footer(); ?>
