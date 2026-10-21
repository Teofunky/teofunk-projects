<?php // шапка сайта ?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="format-detection" content="telephone=no">
  <?php wp_head() ?>

  <link rel="pingback" href="<?php bloginfo('pingback_url'); ?>" />

  <script>
    (function(i,s,o,g,r,a,m){i['GoogleAnalyticsObject']=r;i[r]=i[r]||function(){
    (i[r].q=i[r].q||[]).push(arguments)},i[r].l=1*new Date();a=s.createElement(o),
    m=s.getElementsByTagName(o)[0];a.async=1;a.src=g;m.parentNode.insertBefore(a,m)
    })(window,document,'script','https://www.google-analytics.com/analytics.js','ga');
    ga('create','UA-97996494-1','auto');ga('send','pageview');
  </script>

  <meta name="yandex-verification" content="06c1efa3b6f5e029" />
  <script type="text/javascript">!function(){var t=document.createElement("script");t.type="text/javascript",t.async=!0,t.src="https://vk.com/js/api/openapi.js?169",t.onload=function(){VK.Retargeting.Init("VK-RTRG-103063-cbLpq"),VK.Retargeting.Hit()},document.head.appendChild(t)}();</script><noscript><img src="https://vk.com/rtrg?p=VK-RTRG-103063-cbLpq" style="position:fixed; left:-999px;" alt=""/></noscript>
  <!-- Facebook Pixel Code -->
  <script>
    !function(f,b,e,v,n,t,s)
    {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
    n.callMethod.apply(n,arguments):n.queue.push(arguments)};
    if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
    n.queue=[];t=b.createElement(e);t.async=!0;
    t.src=v;s=b.getElementsByTagName(e)[0];
    s.parentNode.insertBefore(t,s)}(window, document,'script',
    'https://connect.facebook.net/en_US/fbevents.js');
    fbq('init', '268205588080894');
    fbq('track', 'PageView');
  </script>
  <noscript><img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id=268205588080894&ev=PageVie.."/></noscript>
  <!-- End Facebook Pixel Code -->
  <!-- Facebook Pixel Code -->
  <script>
    !function(f,b,e,v,n,t,s)
    {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
    n.callMethod.apply(n,arguments):n.queue.push(arguments)};
    if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
    n.queue=[];t=b.createElement(e);t.async=!0;
    t.src=v;s=b.getElementsByTagName(e)[0];
    s.parentNode.insertBefore(t,s)}(window, document,'script',
    'https://connect.facebook.net/en_US/fbevents.js');
    fbq('init', '816504109229799');
    fbq('track', 'PageView');
  </script>
  <noscript><img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id=816504109229799&ev=PageView&noscript=1"/></noscript>
  <!-- End Facebook Pixel Code -->
  <!-- Facebook Pixel Code -->
  <script>
    !function(f,b,e,v,n,t,s)
    {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
    n.callMethod.apply(n,arguments):n.queue.push(arguments)};
    if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
    n.queue=[];t=b.createElement(e);t.async=!0;
    t.src=v;s=b.getElementsByTagName(e)[0];
    s.parentNode.insertBefore(t,s)}(window, document,'script',
    'https://connect.facebook.net/en_US/fbevents.js');
    fbq('init', '117454070424513');
    fbq('track', 'PageView');
  </script>
  <noscript><img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id=117454070424513&ev=PageView&noscript=1"/></noscript>
  <!-- End Facebook Pixel Code -->

  <?php
  $id = get_the_ID();
  $meta = get_post_meta($id);
  echo $meta['css'][0];
  echo $meta['js'][0];
  ?>

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

  <style>
  .search_block.active + .header {
    top: 50px;
  }

  .search_block.active + .header .nav .search-btn {
    background-image: url('/wp-content/themes/KozlovCLub/assets/icons/close.png');
    background-size: 12px;
  }

  .header + #allrecords {
    padding-top: 190px;
  }

  @media screen and (max-width: 1024px) {
    .header + #allrecords {
      padding-top: 70px;
    }
  }
  @media screen and (max-width: 1024px) {
    .header .inner {
      width: 96%;
      padding: 0px;
    }
  }
  </style>
</head>
<body class="t-body" style="margin:0;">

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

			<header class="header">
				<div class="inner">
					<div class="wrap">
						<div class="logo">
							<a href="<?php bloginfo('url')?>"><img src="<?php bloginfo('template_url') ?>/assets/images/logo-kozlovclub.png" alt="Клуб Алексея Козлова" /></a>
						</div>
						<div class="nav">
							<?php if ( $language == 'ru' ) { ?>
								<div class="contacts"><span>+7 (495) 792-39-59</span> ул. Маросейка, дом 9/2, «Left bank»</div>
							<?php } else if ( $language == 'en' ) { ?>
								<div class="contacts"><span>+7 (495) 792-39-59</span> st. Maroseyka, bld 9/2, «Left bank»</div>
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

							<div class="language">
								<ul>
									<li class="lang-ru <?php if ($language == 'ru') echo 'active' ?>"><a href="/" hreflang="ru" title="Рус"><span>Рус</span></a></li>
									<li class="lang-en <?php if ($language == 'en') echo 'active' ?>"><a href="/en/" hreflang="en" title="Eng"><span>Eng</span></a></li>
								</ul>
							</div>


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

<?php /* */ ?>
