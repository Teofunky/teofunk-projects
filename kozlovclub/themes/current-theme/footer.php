<?php $language = WPGlobus::Config()->language ?>
			<footer class="footer">
				<div class="upper">
					<div class="inner">
						<div class="wrap">
							<div class="copyright">alexeykozlovclub &copy; 2017</div>
							<?php if ( $language == 'ru' ) { ?>
								<div class="contacts">+7 (495) 296-12-00 <span>info@kozlovclub.ru</span> ул. Маросейка, дом 9/2</div>
							<?php } else if ( $language == 'en' ) { ?>
								<div class="contacts">+7 (495) 296-12-00 <span>info@kozlovclub.ru</span> st. Maroseyka, bld 9/2</div>
							<?php } ?>

              <?php /*
							<?php if ( $language == 'ru' ) { ?>
								<div class="ipartner">Разработано в <a href="http://i-partner.ru">айпартнер</a></div>
							<?php } else if ( $language == 'en' ) { ?>
								<div class="ipartner">Developed by <a href="http://i-partner.ru">ipartner</a></div>
							<?php } ?>
              */ ?>

						</div>
					</div>
				</div>
				<div class="bottom">
					<div class="inner">
						<div class="wrap">
							<div class="social_icons">
								<?php if ( $language == 'ru' ) { ?>
									<div class="title">Следите за нами в социальных сетях</div>
								<?php } else if ( $language == 'en' ) { ?>
									<div class="title">Follow us</div>
								<?php } ?>

								<div class="items">
									<ul>
										<li><a href="https://vk.com/kozlovclubfan" target="_blank"><img src="<?php bloginfo('template_url') ?>/assets/icons/vk.png" /></a></li>
										<li><a href="https://t.me/kozlovclub" target="_blank"><img src="<?php bloginfo('template_url') ?>/assets/icons/telegram.png" /></a></li>
										<li><a href="https://www.youtube.com/user/kozlovclubtv" target="_blank"><img src="<?php bloginfo('template_url') ?>/assets/icons/youtube.png" /></a></li>
									</ul>
								</div>
							</div> <!-- social_icons -->
						</div>
					</div>
				</div>
			</footer>
		</div> <!-- .page -->

<div class="block-modal block-modal--datepicker">
	<div class="block-modal-overlay"></div>
	<div class="block-modal-inner">
		<div class="block-modal-main">
			<div class="block-modal-body">
				<div class="calendar-picker">
					<div class="block-modal-close calendar-picker-close"><span>&times;</span></div>
					<div class="calendar-picker-content"></div>
				</div>
			</div>
		</div>
	</div>
</div>


<!-- Yandex.Metrika counter -->
<script type="text/javascript" >

// function AppMetrica(){(function(m,e,t,r,i,k,a){m[i]=m[i]||function(){(m[i].a=m[i].a||[]).push(arguments)};m[i].l=1*new Date();k=e.createElement(t),a=e.getElementsByTagName(t)[0],k.async=1,k.src=r,a.parentNode.insertBefore(k,a)})(window, document, "script", "https://mc.yandex.ru/metrika/tag.js", "ym");ym(44419291, "init", {clickmap:true,trackLinks:true,accurateTrackBounce:true,webvisor:true});}
// $(document).one('scroll mosemove',AppMetrica);
// setTimeout(AppMetrica,6000)

<?
$curMonth = date('m');
$curYear = date('Y');
?>

setDatepicker(<?= $curMonth ?>, <?= $curYear ?>, '<?= $language ?>');

function setDatepicker (curMonth, curYear, language) {

	$.ajax({
		url: "/wp-content/themes/KozlovCLub/components/datepicker.php",
		method: 'GET',
		data : {
			'month' : curMonth,
			'year' : curYear,
			'language': language,
		}
	}).done(function (data) {
		data = jQuery.parseJSON(data);
		$('.calendar-picker-content').html(data);
	});
}

$(document).on('click', '.datepicker_nav-item', function () {
	var setMonth = $(this).data('set-month');
	var setYear = $(this).data('set-year');
	var language = '<?= $language ?>';

	$.ajax({
		url: "/wp-content/themes/KozlovCLub/components/datepicker.php",
		method: 'GET',
		data : {
			'month': setMonth,
			'year': setYear,
			'language': language,
		}
	}).done(function (data) {
		data = jQuery.parseJSON(data);
		$('.calendar-picker-content').html(data);
	});
});

$(document).on('click', '.block-modal-close', function () {
	$(this).parents('.block-modal').fadeOut();
});

$(document).on('click', '.calendar-toggler', function (e) {
  e.preventDefault();
	$('.block-modal--datepicker').fadeIn();
});

</script>
<?
// <noscript><div><img src="https://mc.yandex.ru/watch/66613225" style="position:absolute; left:-9999px;" alt="" /></div></noscript>
// <!-- /Yandex.Metrika counter -->
?>

		<?php wp_footer(); ?>

<?php
/*
 * SEA: Отключено по причине прекращения деятельност и сервиса в России
 <!-- BEGIN PLERDY CODE -->
<script type="text/javascript" defer>
    var _protocol = (("https:" == document.location.protocol) ? " https://" : " http://");
    var _site_hash_code = "a76e36cfd6d6c8f2a8bb204f51e211ac";
    var _suid = 13660;
    </script>
<script type="text/javascript" defer src="https://a.plerdy.com/public/js/click/main.js"></script>
<!-- END PLERDY CODE -->
*/
?>

		
<!-- REMARKED-LAZY-START: remarked грузится только по клику на кнопку брони (правка 2026-10-13). Для отката: удалить этот блок и вернуть 3 script defer + 3 link с remarked.ru -->
<script>
(function () {
  var BASE = 'https://remarked.ru/widget/new/';
  var CSS = ['css/stylesheet.css', 'points/446068/446068.css', 'css/tables/Tables.widget.css'];
  var JS  = ['points/446068/config.446068.js', 'js/tables/newidget.js', 'js/tables/Tables.widget.js'];
  var state = 0; // 0 не грузили, 1 грузим, 2 готово
  function load(done) {
    if (state === 2) return done(true);
    if (state === 1) return;
    state = 1;
    CSS.forEach(function (p) {
      var l = document.createElement('link'); l.rel = 'stylesheet'; l.href = BASE + p;
      document.head.appendChild(l);
    });
    var timer = setTimeout(function () { state = 0; done(false); }, 8000);
    (function next(i) {
      if (i >= JS.length) {
        clearTimeout(timer); state = 2;
        document.dispatchEvent(new Event('DOMContentLoaded'));
        return setTimeout(function () { done(true); }, 300);
      }
      var s = document.createElement('script'); s.src = BASE + JS[i];
      s.onload = function () { next(i + 1); };
      s.onerror = function () { clearTimeout(timer); state = 0; done(false); };
      document.body.appendChild(s);
    })(0);
  }
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.open__primary__widget');
    if (!btn || state === 2) return;
    e.preventDefault(); e.stopPropagation();
    btn.style.opacity = '0.6';
    load(function (ok) {
      btn.style.opacity = '';
      if (ok) { btn.click(); }
      else { window.location.href = 'tel:+74952961200'; }
    });
  }, true);
})();
</script>
<!-- REMARKED-LAZY-END -->
		
</body>
</html>
