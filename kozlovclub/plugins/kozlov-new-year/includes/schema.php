<?php
/**
 * Схема полей редактора. По ней строится форма в админке и проверяются данные при сохранении.
 *
 * Типы: text, lines (переносы строк), textarea, rich (абзацы + простые теги), url, image,
 * select, checkbox, datetime, repeater, sub (подзаголовок в форме, без данных).
 *
 * @package Kozlov_New_Year
 */

defined( 'ABSPATH' ) || exit;

/**
 * Короткий конструктор поля.
 *
 * @param string $type  Тип.
 * @param string $label Подпись.
 * @param array  $extra Доп. параметры.
 * @return array
 */
function kny_f( $type, $label, $extra = array() ) {
	return array_merge( array( 'type' => $type, 'label' => $label ), $extra );
}

/**
 * Схема всех разделов.
 *
 * @return array
 */
function kny_schema() {
	$rich_help = 'Пустая строка между абзацами = новый абзац. Можно <b>жирный</b>, <i>курсив</i>, <a href="...">ссылку</a>. {phone} = телефон клуба ссылкой.';
	$em_help   = 'Вторая строка заголовка, набирается курсивом с засечками.';

	return array(
		'program'   => array(
			'tab'    => 'Концерты',
			'fields' => array(
				'events'            => kny_f(
					'repeater',
					'Программа 31 декабря',
					array(
						'help'   => 'На сайте концерты сами сортируются по времени (ночные после 00:00 идут в конце). Порядок здесь влияет только на концерты с одинаковым временем.',
						'add'    => 'Добавить концерт',
						'title'  => array( 'time', 'title', 'stage' ),
						'max'    => 60,
						'fields' => array(
							'title'       => kny_f( 'text', 'Название', array( 'wide' => true ) ),
							'time'        => kny_f( 'text', 'Время начала', array( 'placeholder' => '19:00', 'help' => 'Формат ЧЧ:ММ.' ) ),
							'period'      => kny_f(
								'select',
								'Вкладка фильтра',
								array(
									'options' => array(
										'day'     => 'Бранч (день)',
										'evening' => 'Вечер',
										'night'   => 'Ночь',
									),
									'default' => 'evening',
								)
							),
							'stage'       => kny_f( 'text', 'Сцена', array( 'datalist' => 'kny-stages', 'help' => 'Выберите из подсказок или впишите новую: она сама появится в фильтре.' ) ),
							'address'     => kny_f( 'text', 'Адрес или вход', array( 'placeholder' => 'Маросейка, 9/2' ) ),
							'subtitle'    => kny_f( 'text', 'Подзаголовок (курсив)' ),
							'genre'       => kny_f( 'text', 'Жанры', array( 'placeholder' => 'Джаз · Соул' ) ),
							'price'       => kny_f( 'text', 'Цена', array( 'placeholder' => 'от 3 500 ₽' ) ),
							'price_note'  => kny_f( 'text', 'Подпись под ценой', array( 'placeholder' => 'билет на концерт' ) ),
							'action'      => kny_f(
								'select',
								'Кнопка',
								array(
									'options' => array(
										'ticket'     => '«Купить билет» → страница концерта',
										'book_page'  => '«Забронировать» → страница концерта',
										'book_phone' => '«Забронировать» → звонок в клуб',
									),
									'default' => 'ticket',
								)
							),
							'url'         => kny_f( 'url', 'Ссылка на концерт в афише', array( 'wide' => true, 'placeholder' => 'https://kozlovclub.ru/event/...', 'help' => 'Если пусто, кнопка звонит в клуб.' ) ),
							'image'       => kny_f( 'image', 'Фото', array( 'help' => 'Пропорция 4:3, от 800×600.' ) ),
							'description' => kny_f( 'textarea', 'Короткое описание', array( 'wide' => true ) ),
							'detail'      => kny_f( 'rich', 'Подробности (раскрываются по клику)', array( 'wide' => true ) ),
							'hidden'      => kny_f( 'checkbox', 'Скрыть с сайта', array( 'help' => 'Карточка останется здесь, но пропадёт со страницы.' ) ),
						),
					)
				),
				'_head'             => kny_f( 'sub', 'Заголовок блока' ),
				'eyebrow'           => kny_f( 'text', 'Надпись над заголовком' ),
				'title'             => kny_f( 'text', 'Заголовок' ),
				'title_em'          => kny_f( 'text', 'Заголовок, вторая строка', array( 'help' => $em_help ) ),
				'lead'              => kny_f( 'text', 'Текст справа от заголовка' ),
				'_filter'           => kny_f( 'sub', 'Фильтр' ),
				'stages'            => kny_f( 'lines', 'Сцены в фильтре', array( 'help' => 'По одной в строке. Сцены из карточек, которых нет в списке, добавятся сами.' ) ),
				'stage_placeholder' => kny_f( 'text', 'Пункт «все сцены»' ),
				'tab_day'           => kny_f( 'text', 'Вкладка «день»', array( 'help' => 'Пусто = вкладка скрыта.' ) ),
				'tab_evening'       => kny_f( 'text', 'Вкладка «вечер»', array( 'help' => 'Пусто = вкладка скрыта.' ) ),
				'tab_night'         => kny_f( 'text', 'Вкладка «ночь»', array( 'help' => 'Пусто = вкладка скрыта.' ) ),
				'tab_all'           => kny_f( 'text', 'Вкладка «все»' ),
				'count_suffix'      => kny_f( 'text', 'Подпись к счётчику', array( 'help' => 'Пример: «8 событий · 31 декабря».' ) ),
				'empty_title'       => kny_f( 'text', 'Текст, если ничего не найдено', array( 'wide' => true ) ),
				'empty_button'      => kny_f( 'text', 'Кнопка сброса фильтра' ),
				'_labels'           => kny_f( 'sub', 'Подписи в карточках' ),
				'btn_ticket'        => kny_f( 'text', 'Кнопка покупки' ),
				'btn_book'          => kny_f( 'text', 'Кнопка брони' ),
				'summary_ticket'    => kny_f( 'text', 'Раскрывашка у концерта с билетом' ),
				'summary_book'      => kny_f( 'text', 'Раскрывашка у концерта с депозитом' ),
				'start_line'        => kny_f( 'text', 'Строка о начале', array( 'wide' => true, 'help' => '{address} = адрес из карточки, {time} = время.' ) ),
				'more_link'         => kny_f( 'text', 'Ссылка «подробнее»' ),
			),
		),
		'hero'      => array(
			'tab'    => 'Первый экран',
			'fields' => array(
				'_top'            => kny_f( 'sub', 'Верх экрана и отсчёт' ),
				'topline_left'    => kny_f( 'text', 'Строка сверху слева' ),
				'topline_right'   => kny_f( 'text', 'Строка сверху справа' ),
				'countdown_title' => kny_f( 'text', 'Заголовок отсчёта' ),
				'countdown_done'  => kny_f( 'text', 'Текст после полуночи' ),
				'countdown_at'    => kny_f( 'datetime', 'До какого момента считать', array( 'help' => 'Московское время.' ) ),
				'countdown_note'  => kny_f( 'text', 'Подпись под цифрами' ),
				'year'            => kny_f( 'lines', 'Крупный год справа', array( 'help' => 'Каждая строка с новой строки.' ) ),
				'year_tagline'    => kny_f( 'lines', 'Подпись под годом' ),
				'h1'              => kny_f( 'text', 'Скрытый заголовок H1 (для поисковиков)', array( 'wide' => true ) ),
				'_images'         => kny_f( 'sub', 'Картинки' ),
				'tree'            => kny_f( 'image', 'Ёлка (PNG/WebP с прозрачностью)' ),
				'goat'            => kny_f( 'image', 'Игрушка-козёл (с прозрачностью)' ),
				'_intro'          => kny_f( 'sub', 'Текст первого экрана' ),
				'intro'           => kny_f( 'lines', 'Главная фраза' ),
				'award_note'      => kny_f( 'text', 'Сноска со звёздочкой', array( 'help' => 'Пусто = без звёздочки и сноски.' ) ),
				'intro_button'    => kny_f( 'text', 'Кнопка' ),
				'_second'         => kny_f( 'sub', 'Второй экран (появляется при прокрутке)' ),
				'second_title'    => kny_f( 'text', 'Заголовок' ),
				'second_title_em' => kny_f( 'text', 'Заголовок, вторая строка', array( 'help' => $em_help ) ),
				'second_text'     => kny_f( 'rich', 'Текст', array( 'wide' => true, 'help' => $rich_help ) ),
				'second_artists'  => kny_f( 'rich', 'Строка с артистами', array( 'wide' => true ) ),
				'second_button'   => kny_f( 'text', 'Кнопка' ),
				'scroll_cue'      => kny_f( 'text', 'Кнопка внизу экрана' ),
				'bottom_right'    => kny_f( 'text', 'Строка внизу справа' ),
			),
		),
		'night'     => array(
			'tab'    => 'Новогодняя ночь',
			'fields' => array(
				'eyebrow'             => kny_f( 'text', 'Надпись над заголовком' ),
				'title'               => kny_f( 'text', 'Заголовок' ),
				'title_em'            => kny_f( 'text', 'Заголовок, вторая строка', array( 'help' => $em_help ) ),
				'text'                => kny_f( 'rich', 'Текст', array( 'wide' => true, 'help' => $rich_help ) ),
				'djs'                 => kny_f(
					'repeater',
					'Диджеи',
					array(
						'add'    => 'Добавить диджея',
						'title'  => array( 'name', 'stage' ),
						'max'    => 6,
						'fields' => array(
							'name'  => kny_f( 'text', 'Имя' ),
							'stage' => kny_f( 'text', 'Сцена (надпись над именем)' ),
							'text'  => kny_f( 'rich', 'Описание', array( 'wide' => true ) ),
						),
					)
				),
				'_dinner'             => kny_f( 'sub', 'Новогодний ужин' ),
				'dinner_eyebrow'      => kny_f( 'text', 'Надпись над заголовком' ),
				'dinner_title'        => kny_f( 'text', 'Заголовок' ),
				'dinner_title_em'     => kny_f( 'text', 'Заголовок, вторая строка' ),
				'dinner_text'         => kny_f( 'rich', 'Текст', array( 'wide' => true ) ),
				'dinner_more'         => kny_f( 'text', 'Раскрывашка «что входит»', array( 'wide' => true ) ),
				'dinner_more_text'    => kny_f( 'rich', 'Состав сетов', array( 'wide' => true ) ),
				'_dishes'             => kny_f( 'sub', 'Тарелки на столе (вылетают при прокрутке)' ),
				'dish_fish'           => kny_f( 'image', 'Тарелка 1 (сверху слева)' ),
				'dish_fish_alt'       => kny_f( 'text', 'Подпись к тарелке 1 (для незрячих)' ),
				'dish_meat'           => kny_f( 'image', 'Тарелка 2 (снизу справа)' ),
				'dish_meat_alt'       => kny_f( 'text', 'Подпись к тарелке 2' ),
				'dish_olivier'        => kny_f( 'image', 'Тарелка 3 (сверху справа)' ),
				'dish_olivier_alt'    => kny_f( 'text', 'Подпись к тарелке 3' ),
				'dish_assortment'     => kny_f( 'image', 'Тарелка 4 (снизу слева)' ),
				'dish_assortment_alt' => kny_f( 'text', 'Подпись к тарелке 4' ),
				'dishes_note'         => kny_f( 'text', 'Подпись под тарелками', array( 'wide' => true ) ),
				'sets'                => kny_f(
					'repeater',
					'Фото сетов',
					array(
						'add'    => 'Добавить фото',
						'title'  => array( 'caption' ),
						'max'    => 6,
						'fields' => array(
							'image'   => kny_f( 'image', 'Фото', array( 'help' => 'Вертикальное, примерно 2:3.' ) ),
							'caption' => kny_f( 'text', 'Подпись' ),
							'alt'     => kny_f( 'text', 'Описание фото (для незрячих)', array( 'wide' => true ) ),
						),
					)
				),
				'_booking'            => kny_f( 'sub', 'Бронирование' ),
				'booking_price'       => kny_f( 'text', 'Цена' ),
				'booking_unit'        => kny_f( 'text', 'Единица' ),
				'booking_text'        => kny_f( 'text', 'Текст', array( 'wide' => true ) ),
			),
		),
		'locations' => array(
			'tab'    => 'Адреса',
			'fields' => array(
				'image'         => kny_f( 'image', 'Фото слева', array( 'help' => 'Вертикальное.' ) ),
				'image_alt'     => kny_f( 'text', 'Описание фото (для незрячих)' ),
				'image_caption' => kny_f( 'text', 'Плашка на фото', array( 'wide' => true ) ),
				'eyebrow'       => kny_f( 'text', 'Надпись над заголовком' ),
				'title'         => kny_f( 'text', 'Заголовок' ),
				'title_em'      => kny_f( 'text', 'Заголовок, вторая строка' ),
				'places'        => kny_f(
					'repeater',
					'Адреса',
					array(
						'add'    => 'Добавить адрес',
						'title'  => array( 'name' ),
						'max'    => 6,
						'fields' => array(
							'name' => kny_f( 'text', 'Адрес' ),
							'info' => kny_f( 'lines', 'Сцены и как пройти' ),
							'map'  => kny_f( 'url', 'Ссылка на карту', array( 'wide' => true ) ),
						),
					)
				),
				'map_label'     => kny_f( 'text', 'Подпись ссылки на карту' ),
				'contact_label' => kny_f( 'text', 'Подпись над телефоном' ),
			),
		),
		'faq'       => array(
			'tab'    => 'Вопросы',
			'fields' => array(
				'eyebrow'  => kny_f( 'text', 'Надпись над заголовком' ),
				'title'    => kny_f( 'text', 'Заголовок' ),
				'title_em' => kny_f( 'text', 'Заголовок, вторая строка' ),
				'items'    => kny_f(
					'repeater',
					'Вопросы и ответы',
					array(
						'add'    => 'Добавить вопрос',
						'title'  => array( 'q' ),
						'max'    => 30,
						'fields' => array(
							'q' => kny_f( 'text', 'Вопрос', array( 'wide' => true ) ),
							'a' => kny_f( 'rich', 'Ответ', array( 'wide' => true, 'help' => $rich_help ) ),
						),
					)
				),
			),
		),
		'final'     => array(
			'tab'    => 'Финал',
			'fields' => array(
				'eyebrow'  => kny_f( 'text', 'Надпись над заголовком', array( 'wide' => true ) ),
				'title'    => kny_f( 'text', 'Заголовок' ),
				'title_em' => kny_f( 'text', 'Заголовок, вторая строка' ),
				'button'   => kny_f( 'text', 'Кнопка' ),
			),
		),
		'common'    => array(
			'tab'    => 'Общее и SEO',
			'fields' => array(
				'phone'           => kny_f( 'text', 'Телефон клуба', array( 'help' => 'Подставляется во все кнопки звонка и в {phone}.' ) ),
				'header_logo'     => kny_f( 'image', 'Логотип в шапке', array( 'help' => 'Как на сайте. Ведёт на главную kozlovclub.ru.' ) ),
				'logo'            => kny_f( 'image', 'Логотип в подвале', array( 'help' => 'Тёмный на прозрачном фоне: в подвале сам становится светлым.' ) ),
				'_seo'            => kny_f( 'sub', 'Поисковики и соцсети' ),
				'seo_title'       => kny_f( 'text', 'Title страницы', array( 'wide' => true, 'help' => 'До 70 знаков.', 'counter' => 70 ) ),
				'seo_description' => kny_f( 'textarea', 'Description', array( 'wide' => true, 'help' => 'До 160 знаков.', 'counter' => 160 ) ),
				'og_title'        => kny_f( 'text', 'Заголовок при отправке ссылки', array( 'wide' => true ) ),
				'og_description'  => kny_f( 'textarea', 'Описание при отправке ссылки', array( 'wide' => true ) ),
				'og_image'        => kny_f( 'image', 'Картинка при отправке ссылки', array( 'help' => '1200×630.' ) ),
				'_menu'           => kny_f( 'sub', 'Шапка, подвал, мобильная панель' ),
				'nav_program'     => kny_f( 'text', 'Меню: программа' ),
				'nav_night'       => kny_f( 'text', 'Меню: ночь' ),
				'nav_faq'         => kny_f( 'text', 'Меню: вопросы' ),
				'header_button'   => kny_f( 'text', 'Кнопка в шапке' ),
				'footer_site'     => kny_f( 'text', 'Подвал: ссылка на сайт' ),
				'footer_privacy'  => kny_f( 'text', 'Подвал: конфиденциальность' ),
				'privacy_url'     => kny_f( 'url', 'Ссылка на политику конфиденциальности', array( 'wide' => true ) ),
				'copyright'       => kny_f( 'text', 'Копирайт' ),
				'mobile_call'     => kny_f( 'text', 'Телефон: кнопка «позвонить»' ),
				'mobile_cta'      => kny_f( 'text', 'Телефон: главная кнопка' ),
			),
		),
	);
}
