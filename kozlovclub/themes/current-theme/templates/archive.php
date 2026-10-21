<?php
$lang = WPGlobus::Config()->language;
$start = time();
global $wpdb;
global $post;
$query = "SELECT wp.id, wpei.start, wpei.end, CONCAT('/{$lang}/event/', wp.post_name) as url
		FROM wp_ai1ec_event_instances AS wpei
		LEFT JOIN wp_posts AS wp ON wp.id = wpei.post_id
		WHERE wpei.start < '{$start}' AND wp.post_status = 'publish'
		ORDER BY wpei.start DESC";
$events = $wpdb->get_results($query);

$_items_per_page = 30;
$pages_qnty = ceil( count($events) / $_items_per_page );

$uri = $_SERVER['REQUEST_URI'];
$uri = explode('/', $uri);
if ( $lang == 'ru' ) {
	$currnet_page = empty($uri[2]) ? 1 : $uri[2];
} else if ( $lang == 'en' ) {
	$currnet_page = empty($uri[3]) ? 1 : $uri[3];
}

if ( $currnet_page == 1 ) {
	$show_min = $currnet_page * 30 - 29;
} else {
	$show_min = ( $currnet_page * 30 + 1 ) - 30;
}
$show_max = $currnet_page * 30;

$i = 1;

?>
<div class="title">
	<h1><?php the_title() ?></h1>
</div>
<div class="archive_block">
<div class="pagination top">

<?php
echo paginate_links( array(
    'base' => wp_normalize_path( get_permalink() .'/%#%/' ) ,
    'format' => '%#%',
    'prev_text' => false,
    'next_text' => false,
    'mid_size' => '3',
    'total' => ceil(count($events) / $_items_per_page),
    'current' => $currnet_page
));
?>
</div>


	<div class="archived">
		<?php if ( $events ) foreach ( $events as $event ) { ?>
			<?php if ( $i >= $show_min && $i <= $show_max ) { ?>
				<?php
				$event_date = array();
				$event_date['date'] = date('d', $event->start);
				$event_date['month'] = _month( date('n', $event->start), $lang );
				$event_date['time'] = date('H:i', $event->start);
				$event_date['month1'] = date('n', $event->start);
				?>

				<div class="item">
					<div class="thumbnail">
						<a href="<?php echo $event->url ?>"><?php echo get_the_post_thumbnail($event->id) ?></a>
					</div>
					<div class="desc">
						<a class="title" href="<?php echo $event->url?>"><?php echo get_the_title($event->id) ?></a>
						<div class="datetime"><?php echo($event_date['date'].' '.$event_date['month'].' | '.$event_date['time']) ?></div>
						<?php if ( $lang == 'ru' ) { ?>
							<a class="readmore" href="<?php echo $event->url?>">подробнее</a>
						<?php } else if ( $lang == 'en' ) { ?>
							<a class="readmore" href="<?php echo $event->url?>">read more</a>
						<?php } ?>
					</div>
				</div> <!-- item -->
			<?php } $i++ ?>

		<?php }  ?>
	</div> <!-- archived -->

    <div class="pagination bottom">

        <?php
        echo paginate_links( array(
            'base' => wp_normalize_path( get_permalink() .'/%#%/' ) ,
            'format' => '%#%',
            'prev_text' => false,
            'next_text' => false,
            'mid_size' => '3',
            'total' => ceil(count($events) / $_items_per_page),
            'current' => $currnet_page
        ));
        ?>
    </div>

    <script>
        max_height = 0;
        $('.archived .item .title').each(function () {
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
        $('.mansarda_only, .the_beat_only').remove();
        // $('').remove();
    </script>
</div> <!-- archive_block-->
