<?php // шапка сайта ?>
<!DOCTYPE html>
<html lang="ru-RU">
	<head>
<!-- Top.Mail.Ru counter -->
<script type="text/javascript">
var _tmr = window._tmr || (window._tmr = []);
_tmr.push({id: "3502092", type: "pageView", start: (new Date()).getTime()});
(function (d, w, id) {
  if (d.getElementById(id)) return;
  var ts = d.createElement("script"); ts.type = "text/javascript"; ts.async = true; ts.id = id;
  ts.src = "https://top-fwz1.mail.ru/js/code.js";
  var f = function () {var s = d.getElementsByTagName("script")[0]; s.parentNode.insertBefore(ts, s);};
  if (w.opera == "[object Opera]") { d.addEventListener("DOMContentLoaded", f, false); } else { f(); }
})(document, window, "tmr-code");
</script>
<noscript><div><img src="https://top-fwz1.mail.ru/counter?id=3502092;js=na" style="position:absolute;left:-9999px;" alt="Top.Mail.Ru" /></div></noscript>
<!-- /Top.Mail.Ru counter -->
<!-- script src="//web.webformscr.com/apps/fc3/build/loader.js" async sp-form-id="a6f4a1ab5e4e45b27dbe80685b08063d3f194dd6d04baa3a7b602962c7eb47cd"></script -->
<meta http-equiv="Content-Type" content="<?php bloginfo('html_type'); ?>; charset=<?php bloginfo('charset'); ?>" />
        <title><?php echo elegant_titles()?></title>
       <?php
        global $post;
        // Описание страницы выводит Yoast SEO. Тема выводит своё, только если Yoast выключен, иначе на странице два description.
        if ( ! defined( 'WPSEO_VERSION' ) ) {
        $my_descr = get_post_meta($post->ID, "textblock_2_description", true);
        if ($my_descr){
            echo '<meta name="description"  content="'.strip_tags($my_descr).'" />' ;
        }
        else{
            echo elegant_description();
        }
        } // Конец: описание темы только без Yoast
        ?>



        <?php echo elegant_keywords()?>
        <?php echo elegant_canonical()?>
		<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">

      

		<link rel="pingback" href="<?php bloginfo('pingback_url'); ?>" />

    <?php /*
    <link rel="stylesheet" href="https://remarked.ru/widget/new/css/stylesheet.css">
    <link rel="stylesheet" href="https://remarked.ru/widget/new/points/446068-1/446068-1.css">
    <link rel="stylesheet" href="https://remarked.ru/widget/new/css/tables/Tables.widget.css">

    <script src="https://remarked.ru/widget/new/points/446068-1/config.446068-1.js"></script>
    <script src="https://remarked.ru/widget/new/points/446068-1/newidget.js"></script>
    <script src="https://remarked.ru/widget/new/points/446068-1/Tables.widget.js"></script>
    */ ?>

	<?php /*
        * SEA: Moved third-party resources to footer.php to prevent blocking page rendering
    <script defer src="https://remarked.ru/widget/new/js/tables/newidget.js"></script>
    <script defer src="https://remarked.ru/widget/new/js/tables/Tables.widget.js"></script>
    <script defer src="https://remarked.ru/widget/new/points/446068/config.446068.js"></script>	
    <link rel="stylesheet" href="https://remarked.ru/widget/new/css/stylesheet.css">
    <link rel="stylesheet" href="https://remarked.ru/widget/new/points/446068/446068.css">
    <link rel="stylesheet" href="https://remarked.ru/widget/new/css/tables/Tables.widget.css">
	*/
	?>
		<?php wp_head(); ?>

		<script src="/wp-content/themes/KozlovCLub/js/libs/jquery.animateNumber.min.js"></script>	
		<? // <script src="/wp-content/themes/KozlovCLub/js/libs/jquery.color.min.js"></script>	 ?>
		
		<? /*
		ticketscloudid (new) => eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiIsImlzcyI6InRpY2tldHNjbG91ZC5ydSJ9.eyJwIjoiNWNlZmE4MTUxMzFjMGQwMDBmOWU5YmI1In0.UO8dYRJNhMsXTk2fspJJuF_yGo4LggOkQadFqtsXZWc
		ticketscloudid (old) => eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiIsImlzcyI6InRpY2tldHNjbG91ZC5ydSJ9.eyJwIjoiNWJjZWU4MGNjZmMwZDUwMDBjNzY1MGZkIn0.0AXhQrYWSwx4wb1YTZXDrVMB-gfPo4wq-msjF0LfdQA
		*/ ?>
		<script>window.ticketscloudid='<?php echo(!empty($ticketcloudid[0]))?$ticketcloudid[0]:'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiIsImlzcyI6InRpY2tldHNjbG91ZC5ydSJ9.eyJwIjoiNWNlZmE4MTUxMzFjMGQwMDBmOWU5YmI1In0.UO8dYRJNhMsXTk2fspJJuF_yGo4LggOkQadFqtsXZWc'; ?>';</script>
		<meta name="yandex-verification" content="06c1efa3b6f5e029" />
		<script type="text/javascript">!function(){var t=document.createElement("script");t.type="text/javascript",t.async=!0,t.src="https://vk.com/js/api/openapi.js?169",t.onload=function(){VK.Retargeting.Init("VK-RTRG-103063-cbLpq"),VK.Retargeting.Hit()},document.head.appendChild(t)}();</script><noscript><img src="https://vk.com/rtrg?p=VK-RTRG-103063-cbLpq" style="position:fixed; left:-999px;" alt=""/></noscript>
		<script type="text/javascript">!function(){var t=document.createElement("script");t.type="text/javascript",t.async=!0,t.src='https://vk.com/js/api/openapi.js?171',t.onload=function(){VK.Retargeting.Init("VK-RTRG-1871816-4rce2"),VK.Retargeting.Hit()},document.head.appendChild(t)}();</script><noscript><img src="https://vk.com/rtrg?p=VK-RTRG-1871816-4rce2" style="position:fixed; left:-999px;" alt=""/></noscript>
<!-- Yandex.Metrika counter -->
<script type="text/javascript" >
(function(m,e,t,r,i,k,a){m[i]=m[i]||function(){(m[i].a=m[i].a||[]).push(arguments)};
m[i].l=1*new Date();
for (var j = 0; j < document.scripts.length; j++) {if (document.scripts[j].src === r) { return; }}
k=e.createElement(t),a=e.getElementsByTagName(t)[0],k.async=1,k.src=r,a.parentNode.insertBefore(k,a)})
(window, document, "script", "https://mc.yandex.ru/metrika/tag.js", "ym");

ym(44419291, "init", {
clickmap:true,
trackLinks:true,
accurateTrackBounce:true,
webvisor:true,
ecommerce:"dataLayer"
});
</script>
<noscript><div><img src="https://mc.yandex.ru/watch/44419291" style="position:absolute; left:-9999px;" alt="" /></div></noscript>
<!-- /Yandex.Metrika counter -->

	</head>
	<body <?php body_class(); ?>>
		
		<!-- <script type="text/javascript">(window.Image?(new Image()):document.createElement('img')).src='https://vk.com/rtrg?p=VK-RTRG-103063-cbLpq';</script> --><!— Rating Mail.ru counter —>
<script type="text/javascript">
var _tmr = window._tmr || (window._tmr = []);
_tmr.push({id: "3208431", type: "pageView", start: (new Date()).getTime(), pid: "USER_ID"});
(function (d, w, id) {
if (d.getElementById(id)) return;
var ts = d.createElement("script"); ts.type = "text/javascript"; ts.async = true; ts.id = id;
ts.src = "https://top-fwz1.mail.ru/js/code.js";
var f = function () {var s = d.getElementsByTagName("script")[0]; s.parentNode.insertBefore(ts, s);};
if (w.opera == "[object Opera]") { d.addEventListener("DOMContentLoaded", f, false); } else { f(); }
})(document, window, "topmailru-code");
</script><noscript><div>
<img src="https://top-fwz1.mail.ru/counter?id=3208431;js=na" style="border:0;position:absolute;left:-9999px;" alt="Top.Mail.Ru" />
</div></noscript>
<!-- Rating Mail.ru counter -->

<!-- Rating@Mail.ru counter dynamic remarketing appendix -->
<script type="text/javascript">
var _tmr = _tmr || [];
_tmr.push({
type: 'itemView',
productid: 'VALUE',
pagetype: 'VALUE',
list: 'VALUE',
totalvalue: 'VALUE'
});
</script>
<!-- Rating@Mail.ru counter dynamic remarketing appendix -->
<!-- GIF0 КБ -->


		<?php $language = WPGlobus::Config()->language ?>

		<div class="search_block <?php if (isset($_GET['s'])) echo 'active' ?>">
			<div class="inner">
				<form method="get" class="searchform" action="<?php echo home_url() ?>">
					<?php if ( $language == 'ru' ) { ?>
						<input type="text" autocomplete="off" value="<?php echo $_GET['s']?>" placeholder="Поиск по сайту"  name="s" class="searchinput" />
					<?php } else if ( $language == 'en' ) { ?>
						<input type="text" autocomplete="off" value="<?php echo $_GET['s']?>" placeholder="Search on website"  name="s" class="searchinput" />
					<?php } ?>
					<input type="image" src="<?php bloginfo('template_url') ?>/assets/icons/search.png" class="searchsubmit" />
				</form>
			</div>
		</div>

		<div class="page">
			<header class="header">
				<div class="inner">
					<div class="wrap">
						<div class="logo">
							<a href="<?php bloginfo('url')?>"><img src="<?php bloginfo('template_url') ?>/assets/images/logo-kozlovclub.png" alt="Клуб Алексея Козлова" /></a>
						</div>
						<div class="nav">
							<?php if ( $language == 'ru' ) { ?>
								<div class="contacts">
                  <span>+7 (495) 296-12-00</span>
                  <span>ул. Маросейка, дом 9/2, «Kozlov club»</span>
                  <span class="open__primary__widget">Забронировать столик на бесплатный концерт</span>
                </div>
							<?php } else if ( $language == 'en' ) { ?>
								<div class="contacts">
                  <span>+7 (495) 296-12-00</span>
                  <span>st. Maroseyka, bld 9/2, «Kozlov club»</span>
                  <span class="open__primary__widget">Book a table for a free concert</span>
                </div>
							<?php } ?>

							<menu class="menu">
								<?php
								$main_menu = array(
									'theme_location' => 'main-menu',
									'menu' => 'main-menu',
									'container' => 'nav',
									'container_class' => 'menu-container',
									'container_id' => 'main-menu',
									'menu_class' => 'primary',
									'menu_id' => 'primary',
									'echo' => true,
									'fallback_cb' => '__return_empty_string',
									'before' => '',
									'after' => '',
									'link_before' => '',
									'link_after' => '',
									'items_wrap' => '<ul id = "%1$s" class = "%2$s">%3$s</ul>',
									'depth' => 0,
									'walker' => ''
								);
								wp_nav_menu( $main_menu );
								?>
							</menu>

<!-- 							<div class="language">
								<ul>
									<li class="lang-ru <?php if ($language == 'ru') echo 'active' ?>"><a href="/" hreflang="ru" title="Рус"><span>Рус</span></a></li>
									<li class="lang-en <?php if ($language == 'en') echo 'active' ?>"><a href="/en/" hreflang="en" title="Eng"><span>Eng</span></a></li>
								</ul>
							</div> -->


							<span class="calendar-btn mobile calendar-toggler"></span>
							<span class="info-btn mobile"></span>
							<span class="search-btn mobile"></span>
							<span class="burger mobile"></span>

						</div>

							<div class="calendar-item calendar-toggler">
								<div class="calendar-item-icon"></div>

								<div class="calendar-item-text">
									<?php if ($language == 'ru') { ?>
										календарь
									<? } else if ($language == 'en') { ?>
										calendar
									<? } ?>
								</div>
							</div>

					</div> <!-- wrap -->
				</div> <!-- inner -->
			</header>
