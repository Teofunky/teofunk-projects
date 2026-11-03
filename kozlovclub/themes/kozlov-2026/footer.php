<?php
/** Подвал: перенос footer.php прежней темы. Календарь и Remarked грузятся по клику. */
?>
			<footer class="footer">
				<div class="upper">
					<div class="inner">
						<div class="wrap">
							<div class="copyright">alexeykozlovclub &copy; 2017–<?php echo esc_html( wp_date( 'Y' ) ); ?></div>
							<div class="contacts"><?php echo esc_html( kz_setting( 'phone', '+7 (495) 296-12-00' ) ); ?> <span>info@kozlovclub.ru</span> <?php echo esc_html( kz_setting( 'address', 'ул. Маросейка, дом 9/2' ) ); ?></div>
						</div>
					</div>
				</div>
				<div class="bottom">
					<div class="inner">
						<div class="wrap">
							<div class="social_icons">
								<div class="title">Следите за нами в социальных сетях</div>
								<div class="items">
									<ul>
										<li><a href="https://vk.com/kozlovclubfan" target="_blank" rel="noopener"><img src="<?php echo esc_url( get_template_directory_uri() ); ?>/assets/icons/vk.png" alt="ВКонтакте" /></a></li>
										<li><a href="https://t.me/kozlovclub" target="_blank" rel="noopener"><img src="<?php echo esc_url( get_template_directory_uri() ); ?>/assets/icons/telegram.png" alt="Telegram" /></a></li>
										<li><a href="https://www.youtube.com/user/kozlovclubtv" target="_blank" rel="noopener"><img src="<?php echo esc_url( get_template_directory_uri() ); ?>/assets/icons/youtube.png" alt="YouTube" /></a></li>
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

<?php wp_footer(); ?>

<!-- Remarked: виджет брони столика грузится только по клику на кнопку (правило прежней темы, 13.10.2026) -->
<script>
(function () {
  var BASE = 'https://remarked.ru/widget/new/';
  var CSS = ['css/stylesheet.css', 'points/446068/446068.css', 'css/tables/Tables.widget.css'];
  var JS  = ['points/446068/config.446068.js', 'js/tables/newidget.js', 'js/tables/Tables.widget.js'];
  var state = 0;
  function load(done) {
    if (state === 2) return done(true);
    if (state === 1) return;
    state = 1;
    CSS.forEach(function (p) { var l = document.createElement('link'); l.rel = 'stylesheet'; l.href = BASE + p; document.head.appendChild(l); });
    var timer = setTimeout(function () { state = 0; done(false); }, 8000);
    (function next(i) {
      if (i >= JS.length) { clearTimeout(timer); state = 2; document.dispatchEvent(new Event('DOMContentLoaded')); return setTimeout(function () { done(true); }, 300); }
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
    load(function (ok) { btn.style.opacity = ''; if (ok) { btn.click(); } else { window.location.href = 'tel:' + (window.KZ && KZ.tel ? KZ.tel : '+74952961200'); } });
  }, true);
})();
</script>
</body>
</html>
