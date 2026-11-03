/* Kozlov Club 2026: скрипт прежней темы (версия 14.10.2026) плюс календарь через admin-ajax. */
$(document).ready(function () {

	var slider_slides = $('.slider .slides');
	if (slider_slides.length) {
		var desktop = window.matchMedia('(min-width: 1025px)').matches;
		slider_slides.owlCarousel({
			loop: true,
			autoplay: desktop,                 // автопрокрутка только на десктопе
			autoplayHoverPause: true,
			autoHeight: !desktop,
			items: 1,
			navElement: 'span',
			navText: '',
			nav: true,
			dots: false,
			onInitialized: function () { $('.slider').addClass('initialized'); }
		});
		slider_slides.on('changed.owl.carousel', function (event) {
			var idx = event.relatedTarget.relative(event.item.index);
			$('.slider .titles .nav').removeClass('active');
			$('.slider .titles .nav[data-pos="' + idx + '"]').addClass('active');
		});
		$(document).on('click', '.slider .titles .nav', function () {
			$('.slider .titles .nav').removeClass('active');
			$(this).addClass('active');
			slider_slides.trigger('to.owl.carousel', $(this).data('pos'));
		});
	}

	$(document).on('click', '.menu-item-has-children > a', function (e) {
		if (window.matchMedia('(max-width: 1024px)').matches || $(this).attr('href') === '#') {
			e.preventDefault();
			var $sub_menu = $(this).parent().find('> .sub-menu');
			$sub_menu.toggle();
		}
	});

	$('.search-btn').on('click', function () {
		$('.search_block').toggleClass('active');
		if ($('.search_block').hasClass('active')) { $('.search_block .searchinput').trigger('focus'); }
	});

	$('.header .burger').on('click', function () {
		$('.header .nav .contacts').removeClass('active');
		$('.burger, .header .nav .menu').toggleClass('active');
	});

	$('.header .info-btn').on('click', function () {
		$('.burger, .header .nav .menu').removeClass('active');
		$('.header .nav .contacts').toggleClass('active');
	});

	$(window).on('scroll', function () {
		var scrollTop = $(this).scrollTop();
		$('.view-all-events-mb').toggleClass('inactive', scrollTop >= 670);
		$('.header').toggleClass('scrolled', scrollTop >= 90);
	});

	/* высота заголовков карточек, как раньше */
	$('.view_cell .event .title').each(function () {
		if ($(this).height() >= 40) { $(this).addClass('shortened'); }
	});
	$('.view_cell .set_height').css({ 'height': '50px' });

	/* календарь */
	var calLoaded = false;
	function loadCalendar(month, year) {
		$.get(KZ.ajax, { action: 'kz_calendar', month: month, year: year }).done(function (data) {
			$('.calendar-picker-content').html(data);
			calLoaded = true;
		});
	}
	$(document).on('click', '.calendar-toggler', function (e) {
		e.preventDefault();
		if (!calLoaded) { var d = new Date(); loadCalendar(d.getMonth() + 1, d.getFullYear()); }
		$('.block-modal--datepicker').fadeIn();
	});
	$(document).on('click', '.datepicker_nav-item', function () {
		loadCalendar($(this).data('set-month'), $(this).data('set-year'));
	});
	$(document).on('click', '.block-modal-close, .block-modal-overlay', function () {
		$(this).parents('.block-modal').fadeOut();
	});
});
