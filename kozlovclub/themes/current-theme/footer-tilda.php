
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
<script type="text/javascript">

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
<!-- Google Plus share button support script -->
<script type="text/javascript">(function(){var po=document.createElement('script');po.type='text/javascript';po.async=true;po.src='https://apis.google.com/js/plusone.js';var s=document.getElementsByTagName('script')[0];s.parentNode.insertBefore(po,s);})();</script>

<?php wp_footer(); ?>

<!-- BEGIN PLERDY CODE -->
<script type="text/javascript" defer>
  var _protocol = (("https:" == document.location.protocol) ? " https://" : " http://");
  var _site_hash_code = "a76e36cfd6d6c8f2a8bb204f51e211ac";
  var _suid = 13660;
</script>
<script type="text/javascript" defer src="https://a.plerdy.com/public/js/click/main.js"></script>
<!-- END PLERDY CODE -->

</body>
</html>
<?php /*

<?php $language = WPGlobus::Config()->language ?>
			<footer class="footer">
				<div class="upper">
					<div class="inner">
						<div class="wrap">
							<div class="copyright">alexeykozlovclub &copy; 2017</div>
							<?php if ( $language == 'ru' ) { ?>
								<div class="contacts">+7 (495) 792-39-59 <span>kozlovclub@gmail.com</span> ул. Маросейка, дом 9/2, «Left bank»</div>
							<?php } else if ( $language == 'en' ) { ?>
								<div class="contacts">+7 (495) 792-39-59 <span>kozlovclub@gmail.com</span> st. Maroseyka, bld 9/2, «Left bank»</div>
							<?php } ?>
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
    function AppMetrica(){
        (function(m,e,t,r,i,k,a){m[i]=m[i]||function(){(m[i].a=m[i].a||[]).push(arguments)};
            m[i].l=1*new Date();k=e.createElement(t),a=e.getElementsByTagName(t)[0],k.async=1,k.src=r,a.parentNode.insertBefore(k,a)})
        (window, document, "script", "https://mc.yandex.ru/metrika/tag.js", "ym");

        ym(44419291, "init", {
            clickmap:true,
            trackLinks:true,
            accurateTrackBounce:true,
            webvisor:true
        });
    }
    $(document).one('scroll mosemove',AppMetrica);
    setTimeout(AppMetrica,6000)

<?
// $curMonth = !empty($_GET['date1']) ? date('m', strtotime($_GET['date'])) : date('m');
// $curYear = !empty($_GET['date1']) ? date('Y', strtotime($_GET['date'])) : date('Y');
$curMonth = date('m');
$curYear = date('Y');
// var_dump($curMonth);
// var_dump($curYear);
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
<noscript><div><img src="https://mc.yandex.ru/watch/66613225" style="position:absolute; left:-9999px;" alt="" /></div></noscript>
<!-- /Yandex.Metrika counter -->
		<!-- Google Plus share button support script -->
		<script type="text/javascript">(function(){var po=document.createElement('script');po.type='text/javascript';po.async=true;po.src='https://apis.google.com/js/plusone.js';var s=document.getElementsByTagName('script')[0];s.parentNode.insertBefore(po,s);})();</script>

		<?php wp_footer(); ?>

 <!-- BEGIN PLERDY CODE -->
<script type="text/javascript" defer>
    var _protocol = (("https:" == document.location.protocol) ? " https://" : " http://");
    var _site_hash_code = "a76e36cfd6d6c8f2a8bb204f51e211ac";
    var _suid = 13660;
    </script>
<script type="text/javascript" defer src="https://a.plerdy.com/public/js/click/main.js"></script>
<!-- END PLERDY CODE -->

</body>
</html>

*/ ?>
