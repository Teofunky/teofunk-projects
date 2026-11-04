<?php
/** Шапка сайта: перенос header.php прежней темы без WPGlobus и Magic Fields. */
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php if ( kz_is_production() ) : ?>
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
<meta name="yandex-verification" content="06c1efa3b6f5e029" />
<script type="text/javascript">!function(){var t=document.createElement("script");t.type="text/javascript",t.async=!0,t.src="https://vk.com/js/api/openapi.js?169",t.onload=function(){VK.Retargeting.Init("VK-RTRG-103063-cbLpq"),VK.Retargeting.Hit()},document.head.appendChild(t)}();</script><noscript><img src="https://vk.com/rtrg?p=VK-RTRG-103063-cbLpq" style="position:fixed; left:-999px;" alt=""/></noscript>
<script type="text/javascript">!function(){var t=document.createElement("script");t.type="text/javascript",t.async=!0,t.src='https://vk.com/js/api/openapi.js?171',t.onload=function(){VK.Retargeting.Init("VK-RTRG-1871816-4rce2"),VK.Retargeting.Hit()},document.head.appendChild(t)}();</script><noscript><img src="https://vk.com/rtrg?p=VK-RTRG-1871816-4rce2" style="position:fixed; left:-999px;" alt=""/></noscript>
<!-- Yandex.Metrika counter -->
<script type="text/javascript">
(function(m,e,t,r,i,k,a){m[i]=m[i]||function(){(m[i].a=m[i].a||[]).push(arguments)};
m[i].l=1*new Date();
for (var j = 0; j < document.scripts.length; j++) {if (document.scripts[j].src === r) { return; }}
k=e.createElement(t),a=e.getElementsByTagName(t)[0],k.async=1,k.src=r,a.parentNode.insertBefore(k,a)})
(window, document, "script", "https://mc.yandex.ru/metrika/tag.js", "ym");
ym(44419291, "init", { clickmap:true, trackLinks:true, accurateTrackBounce:true, webvisor:true, ecommerce:"dataLayer" });
</script>
<noscript><div><img src="https://mc.yandex.ru/watch/44419291" style="position:absolute; left:-9999px;" alt="" /></div></noscript>
<!-- /Yandex.Metrika counter -->
<?php else : ?>
<meta name="robots" content="noindex, nofollow">
<?php endif; ?>
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php if ( kz_is_production() ) : ?>
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
</script><noscript><div><img src="https://top-fwz1.mail.ru/counter?id=3208431;js=na" style="border:0;position:absolute;left:-9999px;" alt="Top.Mail.Ru" /></div></noscript>
<?php endif; ?>
		<div class="search_block <?php echo isset( $_GET['s'] ) ? 'active' : ''; ?>">
			<div class="inner">
				<form method="get" class="searchform" action="<?php echo esc_url( home_url( '/' ) ); ?>">
					<input type="text" autocomplete="off" value="<?php echo esc_attr( get_search_query() ); ?>" placeholder="Поиск по сайту" name="s" class="searchinput" />
					<input type="image" src="<?php echo esc_url( get_template_directory_uri() ); ?>/assets/icons/search.png" class="searchsubmit" alt="Найти" />
				</form>
			</div>
		</div>
		<div class="page">
			<header class="header">
				<div class="inner">
					<div class="wrap">
						<div class="logo">
							<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><img src="<?php echo esc_url( get_template_directory_uri() ); ?>/assets/images/logo-kozlovclub.svg" alt="Клуб Алексея Козлова" width="200" height="95" /></a>
						</div>
						<div class="nav">
							<div class="contacts">
								<span><?php echo esc_html( kz_setting( 'phone', '+7 (495) 296-12-00' ) ); ?></span>
								<span><?php echo esc_html( kz_setting( 'address', 'ул. Маросейка, дом 9/2' ) ); ?>, «Kozlov club»</span>
								<span class="open__primary__widget">Забронировать столик на бесплатный концерт</span>
							</div>
							<menu class="menu">
								<?php
								wp_nav_menu( array(
									'theme_location' => 'main-menu', 'container' => 'nav', 'container_class' => 'menu-container', 'container_id' => 'main-menu',
									'menu_class' => 'primary', 'menu_id' => 'primary', 'fallback_cb' => '__return_empty_string', 'depth' => 0,
									'items_wrap' => '<ul id="%1$s" class="%2$s">%3$s</ul>',
								) );
								?>
							</menu>
							<span class="calendar-btn mobile calendar-toggler"></span>
							<span class="info-btn mobile"></span>
							<span class="search-btn mobile"></span>
							<span class="burger mobile"></span>
						</div>
						<div class="calendar-item calendar-toggler">
							<div class="calendar-item-icon"></div>
							<div class="calendar-item-text">календарь</div>
						</div>
					</div> <!-- wrap -->
				</div> <!-- inner -->
			</header>
