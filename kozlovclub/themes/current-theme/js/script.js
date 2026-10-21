$(document).ready(function () {


	// var max_number = 14893;
	// var max_number = parseInt($('.slots').data('max'));

/*
  // $('#start-campaign').click(function () {
	setTimeout(() => {
		$('.slots').animateNumber({
      number: max_number,
      numberStep: numberStep
    }, 3000);
	}, 600);
  // });
*/


	var slider_slides = $('.slider .slides');
	slider_slides.owlCarousel({
		loop: true,
		// KZ-FIX-AUTOPLAY: автопрокрутка только на десктопе, с паузой при наведении (Baymard)
		autoplay: window.matchMedia('(min-width: 1025px)').matches,
		autoplayHoverPause: true,
		// KZ-FIX-AUTOHEIGHT: на мобильном высота слайдера по текущему слайду, без пустоты под кнопкой
		autoHeight: !window.matchMedia('(min-width: 1025px)').matches,
		items: 1,
		navElement: 'span',
		navText: '',
		nav: true,
		dots: false,
		onInitialized: function () {
			$('.slider').addClass('initialized');
		}
	});

	slider_slides.on('changed.owl.carousel', function (event) {
		// KZ-FIX-TABSYNC: индекс считаем средствами Owl, а не вычитанием числа клонов
		var idx = event.relatedTarget.relative(event.item.index);
		$('.slider .titles .nav').removeClass('active');
		$('.slider .titles .nav[data-pos="' + idx + '"]').addClass('active');
	});

	$(document).on('click', '.slider .titles .nav', function () {
		$('.slider .titles .nav').removeClass('active');
		$(this).addClass('active');
		$slide_pos = $(this).data('pos');
		slider_slides.trigger('to.owl.carousel', $slide_pos);
	});

	$(document).on('click', '.menu-item-has-children', function (e) {
		e.preventDefault();
		var $sub_menu = $(this).find('.sub-menu');
		if ($sub_menu.is(':visible')) $sub_menu.hide();
		else $sub_menu.show();
	});

	$(document).on('click', '.menu-item-has-children .sub-menu .menu-item', function (e) {
		e.preventDefault();
		var href = $(this).find('a').attr('href');
		if (href) location.href = href;
	});

	// $(document).on('click', '.view-all-events-mb > a', function () {
	// 	$('.view-all-events-mb').addClass('inactive');
	// });

	$('.search-btn').on('click', function () {
		$('.search_block').toggleClass('active');
	});

	$('.header .burger').on('click', function () {
		$('.header .nav .contacts').removeClass('active');
		if ($('.burger').hasClass('active')) {
			$('.burger, .header .nav .menu').removeClass('active');
		} else {
			$('.burger, .header .nav .menu').addClass('active');
		}
	});

	$('.header .info-btn').on('click', function () {
		$('.burger, .header .nav .menu').removeClass('active');
		if ($('.header .nav .contacts').hasClass('active')) {
			$('.header .nav .contacts').removeClass('active')
		} else {
			$('.header .nav .contacts').addClass('active');
		}
	});

	$(window).on('scroll', function () {
		scrollTop = $(this).scrollTop();
		// console.log(scrollTop);

		// _offsetTop = $('.banners').height();
		//
		// if (scrollTop >= _offsetTop) {
		// 	$('.header').addClass('scrolled');
		// 	$('.page').addClass('scrolled');
		// } else {
		// 	$('.header').removeClass('scrolled');
		// 	$('.page').removeClass('scrolled');
		// }

		if (scrollTop >= 670) {
			$('.view-all-events-mb').addClass('inactive');
		} else {
			$('.view-all-events-mb').removeClass('inactive');
		}

		if (scrollTop >= 90) {
			$('.header').addClass('scrolled');
		} else {
			$('.header').removeClass('scrolled');
		}

	
	});
	
var padding_zeros = '';
	if ($('.slots').length > 0 && max_number > 0) {
		for (let i = 0, l = max_number.toString().length; i < l; i++) {
			padding_zeros += '0';
		}
	}

	var padded_now, numberStep = function (now, tween) {
		var target = $(tween.elem);
		var rounded_now = Math.round(now);
		var rounded_now_string = rounded_now.toString();

		padded_now = padding_zeros + rounded_now_string;
		padded_now = padded_now.substring(rounded_now_string.length);

		var result = padded_now.split("").map(char => `<span class="slot">${char}</span>`).join("");
		target.html(result);

		// target.prop('number', rounded_now).text(padded_now);
	};

});

// document.addEventListener('DOMContentLoaded', () => {
//     const slots = document.querySelectorAll('.slot');
//     let currentNumber = 0;
//     const maxNumber = 6756; // Заканчиваем на 06756
//     const totalDuration = 5000; // Общая длительность анимации в мс
    
//     function updateCounter(timestamp) {
//         if (currentNumber > maxNumber) return;

//         // Рассчитываем процент выполнения
//         const progress = currentNumber / maxNumber;
        
//         // Формула для создания кривой ускорения/замедления
//         const easing = 1 - Math.pow(1 - progress, 2);
        
//         // Рассчитываем интервал на основе easing
//         const interval = 50 + (200 * easing); // от 50 до 250 мс
        
//         const numberStr = currentNumber.toString().padStart(5, '0');
        
//         slots.forEach((slot, index) => {
//             const digit = parseInt(numberStr[index]);
//             const faces = slot.querySelectorAll('.slot-face');
            
//             // Анимация вращения
//             faces.forEach((face, faceIndex) => {
//                 if (faceIndex === digit) {
//                     face.style.transform = 'translateY(-100%)';
//                     face.style.opacity = 0;
//                 }
//             });
            
//             // Возвращаем правильное значение через анимацию
//             setTimeout(() => {
//                 faces[digit].style.transform = 'translateY(0)';
//                 faces[digit].style.opacity = 1;
//             }, 300);
//         });

//         currentNumber++;
//         requestAnimationFrame(updateCounter);
        
//         // Задержка между обновлениями
//         setTimeout(() => {
//             updateCounter();
//         }, interval);
//     }

//     // Запускаем счетчик
//     updateCounter();
// });
