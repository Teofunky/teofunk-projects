<?php get_header(); ?>

<?php
$sort_genre = $_GET['genre'];
$sort_date = $_GET['date1'];
?>

<style>
.event.sort-genre-deactive {
	display: none;
}
.event.sort-genre-active {
	display: block;
}
</style>
<?php
$lang = WPGlobus::Config()->language;
$month_buf = date('n');

$start = time();

$queryFilterDateEnd = '';

if ($sort_date) {
  $start = strtotime($sort_date);
	// var_dump($start);
  $end = strtotime("+31 hours", $start);
	// var_dump(date('Y-m-d H:i:s', $end));
  $queryFilterDateEnd = "AND wpei.end < '{$end}'";
  // var_dump($queryFilterDateEnd);
}

$query = "SELECT wp.id, wpei.start, wpei.end, CONCAT('/event/', wp.post_name, '/') as url, wpe.cost
    FROM wp_ai1ec_event_instances AS wpei
    LEFT JOIN wp_posts AS wp ON wp.id = wpei.post_id
    LEFT JOIN wp_ai1ec_events AS wpe ON wpe.post_id = wpei.post_id
    WHERE wpei.start > '{$start}'
    {$queryFilterDateEnd}
    AND wp.post_status = 'publish'
    ORDER BY wpei.start";


$events = $wpdb->get_results($query);

function set_month_devider ( $month, $lang ) {
	if ( $lang == 'ru' ) {
		$_concert = 'Концерты в ';
	} else if ( $lang == 'en' ) {
		$_concert = 'Concerts in ';
	}
	return '<div class="event_month"><span>'.$_concert._month( $month, $lang, false ).'</span></div>';
}

?>

<?

// echo '<pre>';

// $targetDate = new DateTime('2026-04-07 20:00:00');
// $startDate = new DateTime('2026-03-19 00:00:00');

// $currentDate = new DateTime();
// $currentDate = new DateTime('2026-04-03');

// $currentDiff = $targetDate->diff($currentDate);
// $currentDiffDays = $currentDiff->days;
// var_dump($currentDiffDays);

// $diff = $targetDate->diff($startDate);
// var_dump($diff);
// $diffDays = $diff->days;
// var_dump($diffDays);

// $startEv = 19879;
// $stopEv = 20000;
// $diffEv = $stopEv - $startEv;
// var_dump($diffEv);

// $step = $diffEv / $diffDays;
// var_dump($step);
// $paramDiff = $diffDays - $currentDiffDays;
// var_dump($paramDiff);
// $currentAddEv = $paramDiff * $step;
// var_dump($currentAddEv);
// var_dump($currentAddEv);

// $currentCounter = $startEv + $currentAddEv;
// var_dump($currentCounter);

// $currentMaxCounter = $currentCounter;


// $counterTimestamp = $currentDate->getTimestamp();


// $counterQuery = "SELECT wp.id, wpei.start, wpei.end, CONCAT('/{$lang}/event/', wp.post_name) as url, wpe.cost
//     FROM wp_ai1ec_event_instances AS wpei
//     LEFT JOIN wp_posts AS wp ON wp.id = wpei.post_id
//     LEFT JOIN wp_ai1ec_events AS wpe ON wpe.post_id = wpei.post_id
// 		WHERE wpei.end < '{$counterTimestamp}'
//     AND wp.post_status = 'publish'
//     ORDER BY wpei.start";

// $counterEvents = $wpdb->get_results($counterQuery);

// $completedCounterEvents = count($counterEvents);
// // var_dump($completedCounterEvents);

// // $startCounterEvents = (19421 + $completedCounterEvents) / 2;
// $minCounterEvents = 19421;
// $startCounterEvents = $minCounterEvents - $completedCounterEvents;
// // var_dump($startCounterEvents);

// $maxCounterEvents = 20000;

// // Рассчитываем разницу в секундах между текущей датой и целевой
// $interval = $targetDate->diff($currentDate);
// // var_dump($interval);

// $secondsUntilTarget = $interval->format('%a') * 86400 + $interval->h * 3600 + $interval->i * 60 + $interval->s;
// // var_dump($secondsUntilTarget);

// // Рассчитываем общее количество дней до целевой даты
// $daysUntilTarget = ceil($secondsUntilTarget / 86400);
// // var_dump($daysUntilTarget);

// $a = ceil($startCounterEvents / $daysUntilTarget);
// // var_dump($a);

// // Рассчитываем необходимое приращение в день
// $totalDifference = $maxCounterEvents - $startCounterEvents;
// // var_dump($totalDifference);

// $dailyIncrement = floor($totalDifference / $daysUntilTarget);
// // var_dump($dailyIncrement);

// $currentMaxCounter = $startCounterEvents + $dailyIncrement;
// // var_dump($currentMaxCounter);

// Если до цели осталось меньше дня - округляем до целого
/*if ($currentDate->diff($targetDate)->days <= 1) {
		// $currentMaxCounter = $maxCounterEvents;
		// $currentMaxCounter = $stopEv;
		$currentMaxCounter = 19994;
}

if ($currentCounter >= $stopEv) {
	$currentMaxCounter = $stopEv;
	// $currentMaxCounter = $maxCounterEvents;
}

// echo '</pre>';
*/

?>

<? /*
<div class="counter-promo">
	<div class="inner content_inner">
		<div class="counter-promo-body">
			<div class="counter-promo-js">
				<div class="slots" data-max="<?= $currentMaxCounter ?>">
					<? for ($i = 1; $i <= 5; $i++) { ?>
						<span class="slot">0</span>
					<? } ?>
				</div>
			</div>
			<div class="counter-promo-info">Концертов в клубе Алексея Козлова за 14 лет!</div>
		</div>
	</div>
</div>
*/ ?>

<div class="content content_home">
	<?php $view_tab = ( isset( $_GET['view'] ) && $_GET['view'] === 'line' ) ? 'line' : 'cell' ?>
	<div class="inner content_inner events_wrap view_<?php echo $view_tab ?>">
		<div class="wrap">

			<?php if (!empty($events)) { ?>

        <div class="slider-wrapper">

        <? if (!isset($_GET['sort']) && empty($_GET['sort']) && !isset($_GET['genre']) && empty($_GET['genre']) && !isset($_GET['date1']) && empty($_GET['date1'])) { ?>

          <?php
  				$slider_events = array ();
  				foreach ( $events as $post ) {

  					$slider_show = implode('', get_post_meta($post->id, 'slider_show'));
  					$slider_title = implode('', get_post_meta($post->id, 'slider_title'));
  					$slider_img = implode('', get_post_meta($post->id, 'slider_img'));
            $slider_user_link = implode('', get_post_meta($post->id, 'slider_user_link'));
            $slider_event_check = (int) implode('', get_post_meta($post->id, 'slider_event_check'));
  					$slider_mansarda = implode('', get_post_meta($post->id, 'location_mansarda'));
  					$slider_the_beat = implode('', get_post_meta($post->id, 'location_the_beat'));
  					$slider_main_scene = implode('', get_post_meta($post->id, 'location_main_scene'));
  					$slider_myas = implode('', get_post_meta($post->id, 'location_myasnitskaya'));

            // var_dump($slider_user_link);

            if ( !empty($slider_show) ) {
              // var_dump($slider_event_check);
  						$slider_events[] = [
  							'id'		=>	$post->id,
  							'start'			=>	$post->start,
  							'cost'			=>	$post->cost,
  							'slider_show' 	=> 	$slider_show,
  							'slider_title' 	=> 	$slider_title,
  							'slider_img' 	=> 	$slider_img,
                'slider_user_link' => $slider_user_link,
  							'url'			=>	$post->url,
  							'mansarda'		=>	$slider_mansarda,
  							'the_beat'		=>	$slider_the_beat,
  							'main_scene'	=>	$slider_main_scene,
  							'myas'	=>	$slider_myas,
                'slider_event_check' => $slider_event_check,
  							''
  						];
  					}
  				}
  				?>
  				<?php if (!empty($slider_events)) { ?>
  					<div class="slider">
  						<div class="inner">
  							<div class="wrap">
  								<div class="slides">

  									<?php foreach ($slider_events as $slider) { ?>

                      <?php
                      $slider_url = !empty($slider['slider_user_link']) ? $slider['slider_user_link'] : $slider['url'];
                      // var_dump($slider_url);
                      ?>

  										<div class="slide">
  											<div class="slide_inner">
  												<div class="wrap">
  													<div class="thumbnail">
  														<a href="<?php echo $slider_url ?>">
  															<?php echo wp_get_attachment_image( $slider['slider_img'], 'slider-thumb' ); ?>
  														</a>
  													</div>

  													<div class="info">

                              <?php if (!$slider['slider_event_check']) { ?>
                                <div class="top">
    															<div class="datetime">
    																<?php
    																$event_date = array();
    																$event_date['date'] = date('d', $slider['start']);
    																$event_date['month'] = _month( date('n', $slider['start']), $lang );
    																$event_date['time'] = date('H:i', $slider['start']);
    																$event_date['day'] = _day( date('w', $slider['start']), $lang);
    																$event_date['month1'] = date('n', $slider['start']);

    																echo ( $event_date['date'].' '.$event_date['month'].' | '. $event_date['time'] );
                                    // var_dump($slider);
    																if ( $slider['mansarda'] ) {
    																	if ( $lang == 'ru' ) {
    																		echo ' | <span class="mansarda">Мансарда</span>';
    																	} else if ( $lang == ['en'] ) {
    																		echo ' | <span class="mansarda">Mansard</span>';
    																	}
    																}

    																if ( $slider['main_scene'] ) {
    																	if ( $lang == 'ru' ) {
    																		echo ' | <span class="mansarda">Главная сцена</span>';
    																	} else if ( $lang == ['en'] ) {
    																		echo ' | <span class="mansarda">Main Scene</span>';
    																	}
    																}

                                    if ( $slider['myas'] ) {
    																	if ( $lang == 'ru' ) {
    																		echo ' | <span class="myas">Сцена на Мясницкой, 15</span>';
    																	} else if ( $lang == ['en'] ) {
    																		echo ' | <span class="myas">Scene Myasnitskaya, 15 </span>';
    																	}
    																}

    																if ( $slider['the_beat'] ) {
    																	echo ' | <span class="mansarda">The Beat</span>';
    																}
    																?>

    															</div>
    															<div class="price">
    																<?php
    																$slider_cost = preg_split("/[\[\]]+/", is_array(unserialize($slider['cost'])) ? unserialize($slider['cost'])['cost'] : $slider['cost']);
    																if ( $lang == 'ru' ) echo $slider_cost[2];
    																else if ( $lang == 'en' ) echo $slider_cost[4];

                                    // $slider_cost = preg_split("/[\[\]]+/", is_array(unserialize($slider['cost'])) ? unserialize($slider['cost'])['cost'] : $slider['cost']);
                                    // if ($lang == 'ru') {
                                    //   if (mb_strtolower(trim($slider_cost[2])) === 'вход свободный') echo 'Депозит 1500 руб.';
                                    //   else echo $slider_cost[2];
                                    // } else if ($lang == 'en') {
                                    //   if (mb_strtolower(trim($slider_cost[4])) === 'free entrance') echo '1500 RUR deposit';
                                    //   else echo $slider_cost[4];
                                    // }
    																?>
    															</div>
    														</div>
                              <?php } ?>


  														<div class="title">
  															<a href="<?php echo $slider_url ?>"><?php echo $slider['slider_title'] ?></a>
  														</div>

                              <?php if (!$slider['slider_event_check']) { ?>
    														<div class="readmore">
    															<?
    															$ticketcloud = [];
                                  $need_booking = get_post_meta($slider['id'], 'need_booking')[0];
                                  $ticketcloud['link'] = get_post_meta($slider['id'], 'ticketscloud_link')[0];
                                  $ticketcloud['id'] = get_post_meta($slider['id'], 'ticketcloudid')[0];
																	$isRegisterBtn = get_post_meta($slider['id'], 'btn_text_register')[0];
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
    															<? } else { ?>
                                    <? if ( $lang == 'ru' ) { ?>
                                      <a class="btn-widget-buy" href="<?= $slider['url'] ?>">подробнее</a>
                                    <? } else if ( $lang == 'en' ) { ?>
                                      <a class="btn-widget-buy" href="<?= $slider['url'] ?>">read more</a>
                                    <? } ?>
                                  <? } ?>
    														</div> <!-- readmore -->
                              <?php } ?>

  													</div> <!-- info -->

  												</div> <!-- wrap -->
  											</div>
  										</div>
  									<?php } ?>
  								</div> <!-- slides -->

  								<div class="titles">
  									<div class="titles_inner">

                      <?php /*
  										<div class="nav active" data-pos="0">
  											<div class="title">Козлов. Фильм и альбом к 85-летию</div>
  										</div>
                      */ ?>

  										<?php $index = 0 ?>
  										<?php // $index = 1 ?>
  										<?php foreach ($slider_events as $slider) { ?>
  											<div class="nav <?php if ($index == 0) echo 'active' ?>" data-pos="<?php echo $index?>">
  												<div class="title">
  													<?php echo $slider['slider_title']?>
  												</div>
  											</div>
  											<?php $index++ ?>
  										<?php } ?>
  									</div>
  								</div> <!-- titles -->
  							</div>
  						</div>
  					</div>
  				<?php } ?>

        <? } ?>
      </div> <!-- slider-wrapper -->

				<?php if ( is_front_page() || is_home() ) : // SEO: главный заголовок страницы ?>
				<h1 class="home_h1" style="font-size:18px;font-weight:500;letter-spacing:.05em;text-transform:uppercase;line-height:1.3;margin:30px 0 10px;padding:0;color:#424242;">Джаз-клуб Алексея Козлова: афиша концертов в Москве</h1>
				<?php endif; ?>
				<div class="viewtabs" id="events">
					<a href="#" class="tab_item tab_calendar active calendar-toggler"></a>
					<a href="?view=line" class="tab_item tab_line <?php if ( $view_tab == 'line' ) echo 'active' ?>"></a>
					<a href="?view=cell" class="tab_item tab_cell <?php if ( $view_tab == 'cell' ) echo 'active' ?>"></a>
				</div>

				<?php echo set_month_devider($month_buf, $lang) ?>

				<div class="events view_<?php echo $view_tab ?>">
					<?php foreach ($events as $event) : ?>
						<?php
						$event_date = array();
						$event_date['date'] = date('d', $event->start);
						$event_date['month'] = _month( date('n', $event->start), $lang );
						$event_date['time'] = date('H:i', $event->start);
						$event_date['day'] = _day( date('w', $event->start), $lang);
						$event_date['month1'] = date('n', $event->start);
						// $event_date['day1'] = date('w', $event->start);

						if ( $event_date['month1'] != $month_buf ) {
							echo '</div>';
							echo set_month_devider( $event_date['month1'], $lang );
							echo '<div class="events view_'.$view_tab.'">';
							$month_buf = $event_date['month1'];
						}

						$postmeta = get_post_meta($event->id);
						$mansarda = get_post_meta($event->id, 'location_mansarda')[0];
						$the_beat = get_post_meta($event->id, 'location_the_beat')[0];
						$main_scene = get_post_meta($event->id, 'location_main_scene')[0];
            $myas = get_post_meta($event->id, 'location_myasnitskaya')[0];
						// var_dump($myas);

						$mansarda_class = ($_GET['sort'] == 'mansard' && !$mansarda) ? 'mansarda_only' : '';
						$the_beat_class = ($_GET['sort'] == 'the_beat' && !$the_beat) ? 'the_beat_only' : '';
						$main_scene_class = ($_GET['sort'] == 'main_scene' && !$main_scene) ? 'main_scene_only' : '';
						$myas_class = ($_GET['sort'] == 'myasnitskaya' && !$myas) ? 'myasnitskaya_only' : '';

						$is_today = (date('d') == $event_date['date'] && date('n') == $event_date['month1']) ? true : false;
            // echo '<div>';
            // var_dump(date('Y-m-d H:i:s'));
            // echo '<br>';
            // var_dump(date('d'));
            // echo '<br>';
            // var_dump($event_date['date']);
            // echo '<br>';
            // var_dump(date('n'));
            // echo '<br>';
            // var_dump($event_date['month1']);
            // echo '</div>';
						?>

						<?php
						$sort_genre_class = '';
						if (isset($sort_genre)) {
							$sort_genre_class = 'sort-genre-deactive';
							$genre_categories = get_post_meta($event->id, 'genre_categories')[0];
              $arGenresRu = [];
              $arGenresEn = [];
              foreach ($genre_categories as $gc) {
                $gc = explode('|', $gc);
                // pre($gc);
                $arGenresRu[] = mb_strtolower($gc[0]);
                $arGenresEn[] = mb_strtolower($gc[1]);
              }
              // pre($arGenresEn);
              // var_dump($genre_categories);

							if (isset($genre_categories)) {
								// var_dump($genre_categories);
                // var_dump($sort_genre);
              if (in_array($sort_genre, /*$genre_categories*/ $arGenresEn)) {
									$sort_genre_class = 'sort-genre-active';
								}
							}
						}
						?>

						<?php
						$arClass = [
							'today_' . $is_today,
							$mansarda_class,
							'mansarda_' . $mansarda,
							$the_beat_class,
							'the_beat_' . $the_beat,
							$main_scene_class,
							'main_scene_' . $main_scene,
							$myas_class,
							'myasnitskaya_' . $myas,
							$sort_genre_class,
						];
						// var_dump($arClass);
						?>
						<div class="event <?= implode(' ', $arClass) ?>">

							<?php if ($is_today || $mansarda || $the_beat || $main_scene || $myas) { ?>
								<?php
								$css_props = '';
								if (!$is_today && !$mansarda && !$main_scene && !$myas && $the_beat) {
									$css_props = 'style="background-image: url(\'/wp-content/themes/KozlovCLub/assets/icons/yellow_circle.png\')"';
								}
								if (!$is_today && !$mansarda && !$the_beat && !$myas && $main_scene) {
									$css_props = 'style="background-image:none; padding-left: 0"';
								}
								?>
								<div class="top_info" <?= $css_props ?>>
									<?php
									$the_beat_str = 'The Beat';
									if ( $lang == 'ru' ) {
										$today_str = 'Концерт сегодня';
										$mansarda_str = 'Мансарда';
										$main_scene_str = 'Главная сцена';
										$myas_str = 'Сцена на Мясницкой, 15';
									} else if ( $lang == 'en' ) {
										$today_str = 'Concert tonight';
										$mansarda_str = 'Mansard';
										$main_scene_str = 'Main Scene';
                    $myas_str = 'Scene Myasnitskaya, 15';
									}
									$mansarda_str = '<a href="?sort=mansard">' . $mansarda_str . '</a>';
									$the_beat_str = '<a href="?sort=the_beat">' . $the_beat_str . '</a>';
									$main_scene_str = '<a href="?sort=main_scene">' . $main_scene_str . '</a>';
									$myas_str = '<a href="?sort=myasnitskaya">' . $myas_str . '</a>';

									$res_str = '';

                  if ($is_today) {
										$res_str .= $today_str;
									}

									if ($mansarda) {
										$res_str .= ' | ' . $mansarda_str;
									}

                  if ($the_beat) {
										$res_str .= ' | ' . $the_beat_str;
									}

                  if ($main_scene) {
										$res_str .= ' | ' . $main_scene_str;
									}

                  if ($myas) {
										$res_str .= ' | ' . $myas_str;
									}
									?>

									<?= trim($res_str , ' | ') ?>
								</div>
							<?php } ?>

							<div class="wrap">
								<?php if ( $view_tab == 'cell' ) { ?>
									<div class="thumbnail">
										<a href="<?php echo $event->url?>"><?php echo get_the_post_thumbnail($event->id, 'category-thumb2') ?></a>
									</div>
								<?php } ?>
								<div class="desc">

									<?php if ( $view_tab == 'line' ) { ?>
										<div class="datetime">
											<div class="cell cell_1"><?php echo $event_date['date'] ?></div>
											<div class="cell cell_2">
												<div class="row row_1"><?php echo ($event_date['month'].' | '.$event_date['time']) ?></div>
												<div class="row row_2"><?php echo ($event_date['day']) ?></div>
											</div>
										</div>
									<?php } ?>

									<a class="title set_height" href="<?php echo $event->url?>"><?php echo get_the_title($event->id) ?></a>

									<?php if ( $view_tab == 'cell' ) { ?>
										<div class="datetime">
											<div class="wrap">
												<div class="cell cell_1"><?php echo $event_date['date'] ?></div>
												<div class="cell cell_2">
													<div class="row row_1"><?php echo ($event_date['month'].' | '.$event_date['time'].' | '.$event_date['day']) ?></div>
													<div class="row row_2">
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
												</div>
											</div>
										</div>
									<?php } ?>

									<?php if ( $view_tab == 'line' ) { ?>
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
									<?php } ?>

								</div> <!-- desc -->
								<div class="readmore">
									<?
									$ticketcloud = [];
                  $need_booking = get_post_meta($event->id, 'need_booking')[0];
                  $ticketcloud['link'] = get_post_meta($event->id, 'ticketscloud_link')[0];
                  $ticketcloud['id'] = get_post_meta($event->id, 'ticketcloudid')[0];
                  $isRegisterBtn = get_post_meta($event->id, 'btn_text_register')[0];
                  ?>

                  <? if (!empty($ticketcloud['link']) && !empty($ticketcloud['id'])) { ?>
                    <?
										// var_dump($isRegisterBtn);
                    // var_dump($ticketcloud);
                    ?>
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
									<? } else { ?>
                    <? if ( $lang == 'ru' ) { ?>
                      <a class="btn-widget-buy" href="<?= $event->url ?>">подробнее</a>
                    <? } else if ( $lang == 'en' ) { ?>
                      <a class="btn-widget-buy" href="<?= $event->url ?>">read more</a>
                    <? } ?>
                  <? } ?>
								</div>
							</div> <!-- wrap -->
						</div> <!-- event -->
					<?php endforeach; ?>
				</div> <!-- events -->
				<script>
					max_height = 0;
					$('.view_cell .event .title').each(function () {
						$this_height = $(this).height();
            //console.log($this_height);
						if ( $this_height >= 40 ) {
							$(this).addClass('shortened');
						}
						if ( $this_height > max_height ) {
							max_height = $this_height;
						}
					});
					$('.view_cell .set_height').css({ 'height' : '50px' });
					$('.mansarda_only, .the_beat_only, .main_scene_only, .myasnitskaya_only, .event.sort-genre-deactive').remove();

					$('.events').each(function () {
						// console.log($(this).children().length);
						if ($(this).children().length == 0) {
							$(this).prev('.event_month').remove();
						}
					})
					// $('').remove();
				</script>
			<?php } ?>

		</div>
	</div>
</div>

<? if (isset($_GET['date1']) || isset($_GET['genre']) || isset($_GET['sort'])) { ?>
	<a href="<? if ($lang == 'ru') { echo '/'; } else if ($lang == 'en') { echo '/en/'; } ?>" class="go-to-home-mb"></a>
<? } ?>

<? if (!isset($_GET['genre']) && !isset($_GET['sort']) && !isset($_GET['date1'])) { ?>
  <div class="view-all-events-mb">
    <? if ($lang == 'ru') { ?>
      <a href="#events">Смотреть всю афишу</a>
    <? } else if ($lang == 'en') { ?>
      <a href="#events">All concerts</a>
    <? } ?>
  </div>
<? } ?>



<?php //wp_tag_cloud('smallest=15&largest=40&number=50&orderby=count&taxonomy=events_categories'); ?>
<?php get_footer(); ?>
