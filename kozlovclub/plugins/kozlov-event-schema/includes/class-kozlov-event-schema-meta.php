<?php
/**
 * Title и meta description страниц концертов для поисковиков и соцсетей.
 *
 * Yoast на страницах концертов берёт title и description из полей, которые редакторы
 * заполняют вручную набором ключевых слов. Этот модуль подменяет их через фильтры Yoast
 * на собранные автоматически из тех же данных, что и разметка:
 *   title:       «Анна Бутурлина, 11 ноября | Клуб Алексея Козлова»
 *   description: «Анна Бутурлина: концерт 11 ноября в 20:00, Сцена на Мясницкой, 15.
 *                 Клуб Алексея Козлова. Билеты от 1500 ₽. Джаз, вокал.»
 *
 * Редактор может задать свой вариант в блоке «Для поисковиков» (поля плагина
 * _kozlov_event_seo_title и _kozlov_event_seo_desc); пустое поле = автоматически.
 *
 * Модуль только читает данные и ничего не пишет в базу. Любая ошибка внутри фильтра
 * возвращает исходное значение Yoast, страница работает как раньше.
 *
 * @package Kozlov_Event_Schema
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'Kozlov_Event_Schema_Meta' ) ) {
	return;
}

/**
 * SEO meta.
 */
class Kozlov_Event_Schema_Meta {

	/** Собственное поле плагина: title, вписанный редактором вручную. */
	const TITLE_META = '_kozlov_event_seo_title';

	/** Собственное поле плагина: description, вписанный редактором вручную. */
	const DESC_META = '_kozlov_event_seo_desc';

	/** Рекомендуемые пределы длины (как в проверке Beget и у Яндекса). */
	const TITLE_MAX = 65;
	const DESC_MAX  = 160;

	const SEPARATOR = ' | ';

	const BRAND       = 'Клуб Алексея Козлова';
	const BRAND_SHORT = 'Клуб Козлова';

	/** Короткий адрес для description (у сцены на Мясницкой адрес уже в названии сцены). */
	const ADDRESS_MAROSEJKA = 'Маросейка 9/2';

	/** Больше жанров в description не выводим. */
	const MAX_GENRES = 3;

	/**
	 * Слова, которые при переводе заголовка из КАПСА в обычный регистр остаются строчными
	 * (кроме первого слова).
	 */
	const SMALL_WORDS = array(
		'и', 'в', 'во', 'на', 'с', 'со', 'из', 'от', 'до', 'для', 'при', 'по', 'о', 'об', 'у', 'за',
		'над', 'под', 'без', 'не', 'к', 'ко', 'а', 'но', 'да', 'или', 'как', 'про',
		'the', 'of', 'and', 'in', 'at', 'on', 'for', 'to', 'with', 'a', 'an', 'feat', 'feat.', 'vs', 'vs.', 'x', '&',
	);

	/**
	 * Первая часть составных слов, после которой вторая часть пишется строчными: «Джаз-рок», «Мастер-класс».
	 * (Двойные фамилии и географические названия вроде «Санкт-Петербург», «Нью-Йорк» сюда не входят и остаются с заглавной.)
	 */
	const COMPOUND_FIRST = array(
		'джаз', 'джем', 'блюз', 'соул', 'фанк', 'рок', 'поп', 'хип', 'трип', 'гитар', 'мастер', 'арт', 'этно', 'нео',
		'латино', 'бас', 'фри', 'кантри', 'инди', 'пост', 'фолк', 'босса', 'пиано', 'вокал', 'диско', 'фьюжн', 'свинг',
		'бит', 'биг', 'электро', 'кул', 'хард', 'смут', 'эйсид', 'ритм', 'техно', 'хаус', 'дэнс', 'саунд',
		'видео', 'аудио', 'кино', 'фото', 'ток', 'стендап', 'квиз', 'пресс', 'онлайн', 'live',
	);

	/**
	 * Хуки (только на сайте и только если модуль включён).
	 */
	public static function init() {
		if ( is_admin() || ! kozlov_event_schema_is_enabled( 'seo_meta' ) ) {
			return;
		}
		add_filter( 'wpseo_title', array( __CLASS__, 'filter_title' ), 20 );
		add_filter( 'wpseo_opengraph_title', array( __CLASS__, 'filter_title' ), 20 );
		add_filter( 'wpseo_twitter_title', array( __CLASS__, 'filter_title' ), 20 );
		add_filter( 'wpseo_metadesc', array( __CLASS__, 'filter_description' ), 20 );
		add_filter( 'wpseo_opengraph_desc', array( __CLASS__, 'filter_description' ), 20 );
		add_filter( 'wpseo_twitter_description', array( __CLASS__, 'filter_description' ), 20 );
	}

	/**
	 * Фильтр title Yoast.
	 *
	 * @param mixed $value Значение Yoast.
	 * @return mixed
	 */
	public static function filter_title( $value ) {
		try {
			$post = self::current_event();
			if ( ! $post ) {
				return $value;
			}
			$meta = self::for_post( $post );
			return '' !== $meta['title'] ? $meta['title'] : $value;
		} catch ( Throwable $e ) {
			return $value;
		}
	}

	/**
	 * Фильтр description Yoast.
	 *
	 * @param mixed $value Значение Yoast.
	 * @return mixed
	 */
	public static function filter_description( $value ) {
		try {
			$post = self::current_event();
			if ( ! $post ) {
				return $value;
			}
			$meta = self::for_post( $post );
			return '' !== $meta['description'] ? $meta['description'] : $value;
		} catch ( Throwable $e ) {
			return $value;
		}
	}

	/**
	 * Анонс концерта, если открыта именно его страница.
	 *
	 * @return WP_Post|null
	 */
	private static function current_event() {
		if ( is_feed() || is_preview() || ! is_singular( Kozlov_Event_Schema_Builder::POST_TYPE ) ) {
			return null;
		}
		$post = get_queried_object();
		return ( $post instanceof WP_Post && Kozlov_Event_Schema_Builder::POST_TYPE === $post->post_type ) ? $post : null;
	}

	/**
	 * Title и description для анонса: вписанные редактором, иначе собранные автоматически.
	 *
	 * @param WP_Post $post Анонс.
	 * @return array {title: string, description: string, title_auto: bool, desc_auto: bool}
	 */
	public static function for_post( WP_Post $post ) {
		$manual_title = self::manual( $post->ID, self::TITLE_META );
		$manual_desc  = self::manual( $post->ID, self::DESC_META );
		$auto         = self::build( $post );
		return array(
			'title'       => '' !== $manual_title ? $manual_title : $auto['title'],
			'description' => '' !== $manual_desc ? $manual_desc : $auto['description'],
			'title_auto'  => '' === $manual_title,
			'desc_auto'   => '' === $manual_desc,
		);
	}

	/**
	 * Ручное значение поля плагина.
	 *
	 * @param int    $post_id ID записи.
	 * @param string $key     Ключ поля.
	 * @return string
	 */
	private static function manual( $post_id, $key ) {
		$value = get_post_meta( $post_id, $key, true );
		return is_scalar( $value ) ? Kozlov_Event_Schema_Builder::plain_text( $value ) : '';
	}

	/**
	 * Автоматические title и description.
	 *
	 * @param WP_Post $post Анонс.
	 * @return array {title: string, description: string}
	 */
	public static function build( WP_Post $post ) {
		$name = self::smart_case( Kozlov_Event_Schema_Builder::plain_text( $post->post_title ) );
		if ( '' === $name ) {
			return array(
				'title'       => '',
				'description' => '',
			);
		}
		// Короткое имя: часть до двоеточия («Артист: программа»), если она достаточно длинная, чтобы быть именем.
		$short = $name;
		if ( preg_match( '/^([^:]{6,}?)\s*:\s+\S/u', $name, $m ) ) {
			$short = trim( $m[1] );
		}
		$names = $short !== $name ? array( $name, $short ) : array( $name );

		$row   = Kozlov_Event_Schema_Builder::get_row( $post->ID );
		$times = $row ? Kozlov_Event_Schema_Builder::event_times( $row ) : null;
		$date  = $times ? self::date_phrase( $times ) : array(
			'date' => '',
			'time' => '',
		);

		// Сцена и адрес.
		$stage_keys = Kozlov_Event_Schema_Builder::stage_keys( $post->ID );
		$stages     = Kozlov_Event_Schema_Builder::stages();
		$stage      = '';
		$venue      = 'marosejka';
		if ( ! empty( $stage_keys ) && isset( $stages[ $stage_keys[0] ] ) ) {
			$stage = $stages[ $stage_keys[0] ]['label'];
			$venue = $stages[ $stage_keys[0] ]['venue'];
		}
		$club = 'marosejka' === $venue ? self::BRAND . ', ' . self::ADDRESS_MAROSEJKA : self::BRAND;

		// Состояние: отменён, отложен, уже прошёл или по расписанию (перенесённый на новую дату = по расписанию).
		$status = Kozlov_Event_Schema_Builder::status( $post->ID );
		$state  = in_array( $status, array( 'cancelled', 'postponed' ), true ) ? $status : 'scheduled';
		if ( 'scheduled' === $state && $times ) {
			$finish = null !== $times['end_utc'] ? $times['end_utc'] : $times['start_utc'] + Kozlov_Event_Schema_Builder::DEFAULT_LENGTH;
			if ( $finish < time() ) {
				$state = 'past';
			}
		}

		$genres = Kozlov_Event_Schema_Builder::genres( $post->ID );
		$kind   = self::event_kind( $post->post_title, $genres );
		$price  = 'scheduled' === $state ? self::price_phrase( $row ) : '';
		$genre  = 'scheduled' === $state ? self::genre_phrase( $genres ) : '';

		// Описание: с полным именем; если не влезает, с коротким; если и так не влезает, режется само имя,
		// а дата, сцена, клуб и пометка о состоянии остаются целиком.
		$description = '';
		foreach ( $names as $candidate ) {
			$description = self::compose_description( $candidate, $kind, $date, $stage, $club, $price, $genre, $state );
			if ( self::len( $description ) <= self::DESC_MAX ) {
				break;
			}
		}
		if ( self::len( $description ) > self::DESC_MAX ) {
			$rest    = (string) substr( $description, strlen( $short ) );
			$allowed = self::DESC_MAX - self::len( $rest );
			if ( $allowed >= 20 ) {
				$description = Kozlov_Event_Schema_Builder::truncate( $short, $allowed ) . $rest;
			} else {
				$description = Kozlov_Event_Schema_Builder::truncate( $description, self::DESC_MAX );
			}
		}

		// Title. Пометка «(отменён)» обязательна, дата важнее названия клуба (у одного артиста бывает несколько
		// концертов, и дата их различает), название клуба важнее программы после двоеточия. Порядок вариантов,
		// первый подходящий по длине: имя + дата + клуб; имя + дата + короткий клуб; имя + дата; то же для короткого
		// имени; в крайнем случае имя режется по слову, а дата и пометка остаются.
		$date_bit   = '' !== $date['date'] ? ', ' . $date['date'] : '';
		$status_bit = 'cancelled' === $state ? ' (отменён)' : '';
		$tail       = $date_bit . $status_bit;
		$title      = '';
		foreach ( $names as $candidate ) {
			$variants = array(
				$candidate . $tail . self::SEPARATOR . self::BRAND,
				$candidate . $tail . self::SEPARATOR . self::BRAND_SHORT,
				$candidate . $tail,
			);
			foreach ( $variants as $variant ) {
				if ( self::len( $variant ) <= self::TITLE_MAX ) {
					$title = $variant;
					break 2;
				}
			}
		}
		if ( '' === $title ) {
			$allowed = self::TITLE_MAX - self::len( $tail );
			$title   = $allowed >= 20 ? Kozlov_Event_Schema_Builder::truncate( $short, $allowed ) . $tail : Kozlov_Event_Schema_Builder::truncate( $short, self::TITLE_MAX );
		}

		return array(
			'title'       => $title,
			'description' => $description,
		);
	}

	/**
	 * Текст description из частей.
	 *
	 * @param string $name  Название.
	 * @param string $kind  Вид события («концерт», «лекция», …).
	 * @param array  $date  {date, time} из date_phrase().
	 * @param string $stage Сцена или пусто.
	 * @param string $club  Клуб с адресом или без.
	 * @param string $price Фраза о цене или пусто.
	 * @param string $genre Жанры или пусто.
	 * @param string $state scheduled | past | cancelled | postponed.
	 * @return string
	 */
	private static function compose_description( $name, $kind, array $date, $stage, $club, $price, $genre, $state = 'scheduled' ) {
		if ( '' === $date['date'] ) {
			$no_date = array(
				'cancelled' => ' Концерт отменён.',
				'postponed' => ' Концерт отложен, новая дата уточняется.',
			);
			return $name . ' в клубе Алексея Козлова, ' . self::ADDRESS_MAROSEJKA . '.' . ( isset( $no_date[ $state ] ) ? $no_date[ $state ] : '' ) . ' Афиша и билеты на сайте клуба.';
		}
		// Если вид события уже есть в названии («Лекция …», «… Jam Session»), второй раз не пишем.
		$has_kind = preg_match( '/лекци|мастер-класс|jam|джем|концерт/iu', $name );
		$kind_bit = $has_kind ? '' : $kind . ' ';
		$where    = ( '' !== $stage ? ', ' . $stage : '' ) . '. ' . $club . '.';
		$when     = $date['date'] . ( '' !== $date['time'] ? ' в ' . $date['time'] : '' );

		// Прошедший, отменённый, отложенный: без цены и жанров, с пометкой; хвост про афишу, если влезает.
		$notes = array(
			'past'      => 'Уже прошёл.',
			'cancelled' => 'Отменён.',
			'postponed' => 'Отложен, новая дата уточняется.',
		);
		if ( isset( $notes[ $state ] ) ) {
			$text = $name . ': ' . $kind_bit . $date['date'] . $where . ' ' . $notes[ $state ];
			$tail = ' Афиша ближайших концертов и билеты на сайте клуба.';
			return self::len( $text . $tail ) <= self::DESC_MAX ? $text . $tail : $text;
		}

		$text = $name . ': ' . $kind_bit . $when . $where;
		if ( '' !== $price ) {
			$text .= ' ' . $price . '.';
		}
		if ( '' !== $genre && self::len( $text . ' ' . $genre . '.' ) <= self::DESC_MAX ) {
			$text .= ' ' . $genre . '.';
		}
		return $text;
	}

	/**
	 * «11 ноября» (с годом, если концерт не в текущем году) и «20:00».
	 *
	 * @param array $times Результат Kozlov_Event_Schema_Builder::event_times().
	 * @return array {date: string, time: string}
	 */
	public static function date_phrase( array $times ) {
		$months = array( 'января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря' );
		try {
			$tz    = new DateTimeZone( Kozlov_Event_Schema_Builder::CLUB_TZ );
			$start = new DateTime( '@' . (int) $times['start_utc'] );
			$start->setTimezone( $tz );
			$now = new DateTime( 'now', $tz );
		} catch ( Exception $e ) {
			return array(
				'date' => '',
				'time' => '',
			);
		}
		$date = (int) $start->format( 'j' ) . ' ' . $months[ (int) $start->format( 'n' ) - 1 ];
		// Год: если концерт не в текущем году или уже прошёл (страницы прошедших концертов остаются в поиске).
		if ( $start->format( 'Y' ) !== $now->format( 'Y' ) || $start->getTimestamp() < $now->getTimestamp() - Kozlov_Event_Schema_Builder::DEFAULT_LENGTH ) {
			$date .= ' ' . $start->format( 'Y' );
		}
		$allday = strlen( (string) $times['start'] ) <= 10;
		$time   = $allday ? '' : $start->format( 'H:i' );
		// Ночной концерт (в афише 23:59, по факту 00:00): «в ночь на 30 ноября» без времени.
		if ( '00:00' === $time ) {
			return array(
				'date' => 'в ночь на ' . $date,
				'time' => '',
			);
		}
		return array(
			'date' => $date,
			'time' => $time,
		);
	}

	/**
	 * Что это за событие: концерт, джем-сешн, лекция, мастер-класс.
	 *
	 * @param string   $title  Заголовок анонса.
	 * @param string[] $genres Отметки жанров.
	 * @return string
	 */
	public static function event_kind( $title, array $genres ) {
		if ( in_array( 'Лекция', $genres, true ) ) {
			return 'лекция';
		}
		if ( in_array( 'Мастер-класс', $genres, true ) ) {
			return 'мастер-класс';
		}
		if ( preg_match( '/(?<![\p{L}])(jam|джем)(?![\p{L}])/iu', (string) $title ) ) {
			return 'джем-сешн';
		}
		return 'концерт';
	}

	/**
	 * «Билеты от 1500 ₽», «Билеты 1500 ₽», «Вход свободный», «Вход по депозиту» или пусто.
	 *
	 * @param array|null $row Строка календаря.
	 * @return string
	 */
	public static function price_phrase( $row ) {
		$cost = Kozlov_Event_Schema_Builder::parse_cost( is_array( $row ) && isset( $row['cost'] ) ? $row['cost'] : '' );
		switch ( $cost['kind'] ) {
			case 'free':
				return 'Вход свободный';
			case 'deposit':
				return 'Вход по депозиту';
			case 'from':
				return 'Билеты от ' . (int) $cost['price'] . ' ₽';
			case 'exact':
				return 'Билеты ' . (int) $cost['price'] . ' ₽';
		}
		return '';
	}

	/**
	 * «Джаз, фьюжн, вокал»: первые жанры, кириллические со второго в нижнем регистре.
	 *
	 * @param string[] $genres Отметки жанров.
	 * @return string
	 */
	public static function genre_phrase( array $genres ) {
		$list = array_values( array_diff( $genres, Kozlov_Event_Schema_Builder::NOT_KEYWORDS, Kozlov_Event_Schema_Builder::EDUCATION_TAGS ) );
		$list = array_slice( $list, 0, self::MAX_GENRES );
		if ( empty( $list ) ) {
			return '';
		}
		foreach ( $list as $i => $genre ) {
			if ( $i > 0 && preg_match( '/^[\p{Cyrillic}\s\-]+$/u', $genre ) && function_exists( 'mb_strtolower' ) ) {
				$list[ $i ] = mb_strtolower( $genre, 'UTF-8' );
			}
		}
		return implode( ', ', $list );
	}

	/**
	 * Заголовок анонса из КАПСА в обычный регистр: «АННА КОРОЛЕВА И «АРСЕНАЛ» АЛЕКСЕЯ КОЗЛОВА»
	 * -> «Анна Королева и «Арсенал» Алексея Козлова». Если заголовок и так в обычном регистре,
	 * не трогается. Короткие слова без гласных (БДО, DJ, MC) и слова с цифрами (US3) остаются как есть.
	 *
	 * @param string $text Заголовок.
	 * @return string
	 */
	public static function smart_case( $text ) {
		$text = trim( (string) $text );
		if ( '' === $text || ! function_exists( 'mb_convert_case' ) ) {
			return $text;
		}
		if ( ! preg_match_all( '/\p{L}/u', $text, $letters ) ) {
			return $text;
		}
		$total = count( $letters[0] );
		$upper = preg_match_all( '/\p{Lu}/u', $text );
		if ( $total < 4 || $upper / $total < 0.7 ) {
			return $text;
		}

		// Заголовок целиком в кавычках: это не название программы, кавычки снимаем.
		$unquoted = preg_replace( '/^\s*[«"\x{201C}\x{201E}]\s*(.*?)\s*[»"\x{201D}]\s*$/u', '$1', $text );
		if ( is_string( $unquoted ) && '' !== $unquoted && ! preg_match( '/[«»"\x{201C}\x{201D}\x{201E}]/u', $unquoted ) ) {
			$text = $unquoted;
		}

		$words = preg_split( '/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( ! is_array( $words ) ) {
			return $text;
		}
		$out      = '';
		$first    = true;  // Первое слово заголовка или первое слово внутри кавычек / после точки: с заглавной.
		$quoted   = false; // Внутри «…» (название программы): остальные слова строчными.
		// Открывающая кавычка: в начале слова (после скобки или тире); закрывающая: в конце (до знаков препинания).
		$open_re  = '/^[^\p{L}\p{N}"«]*[«"\x{201C}\x{201E}]/u';
		$close_re = '/[»"\x{201D}][^\p{L}\p{N}"»]*$/u';
		foreach ( $words as $word ) {
			if ( '' === $word || preg_match( '/^\s+$/u', $word ) ) {
				$out .= $word;
				continue;
			}
			$opens  = (bool) preg_match( $open_re, $word );
			$closes = (bool) preg_match( $close_re, $opens ? (string) preg_replace( $open_re, '', $word, 1 ) : $word );
			if ( $opens ) {
				$quoted = true;
				$first  = true;
			}
			$core        = (string) preg_replace( '/^[^\p{L}\p{N}]+|[^\p{L}\p{N}.]+$/u', '', $word );
			$lower       = mb_strtolower( $core, 'UTF-8' );
			$in_brackets = (bool) preg_match( '/^\(.*\)[^\p{L}\p{N}]*$/u', $word );
			if ( $in_brackets && self::len( $core ) <= 5 && preg_match( '/^\p{Lu}+$/u', $core ) ) {
				$out .= $word; // Аббревиатура в скобках: (БДО).
			} elseif ( ! $first && in_array( $lower, self::SMALL_WORDS, true ) ) {
				$out .= mb_strtolower( $word, 'UTF-8' );
			} elseif ( self::keep_upper( $core ) ) {
				$out .= $word; // DJ, MC, US3, II.
			} elseif ( ! $first && $quoted ) {
				$out .= mb_strtolower( $word, 'UTF-8' );
			} else {
				$out .= mb_convert_case( mb_strtolower( $word, 'UTF-8' ), MB_CASE_TITLE, 'UTF-8' );
			}
			$first = (bool) preg_match( '/[.!?][^\p{L}\p{N}]*$/u', $word );
			if ( $closes ) {
				$quoted = false;
			}
		}
		// «Джаз-Рок» -> «Джаз-рок» для известных составных слов; «50-Летию» -> «50-летию» после числа.
		$fixed = preg_replace_callback(
			'/([\p{L}\p{N}]+)-(\p{Lu})(\p{Ll}*)/u',
			function ( $m ) {
				if ( preg_match( '/^\p{N}+$/u', $m[1] ) || in_array( mb_strtolower( $m[1], 'UTF-8' ), self::COMPOUND_FIRST, true ) ) {
					return $m[1] . '-' . mb_strtolower( $m[2], 'UTF-8' ) . $m[3];
				}
				return $m[0];
			},
			$out
		);
		return null === $fixed ? $out : $fixed;
	}

	/**
	 * Слово остаётся заглавными: 1-4 буквы без гласных (DJ, MC, GQ), с цифрами (US3, MP3)
	 * или римские цифры (II, III, IV, XX).
	 *
	 * @param string $core Слово без знаков препинания по краям.
	 * @return bool
	 */
	private static function keep_upper( $core ) {
		if ( '' === $core ) {
			return false;
		}
		// Буквы с цифрами (US3, MP3, 3D), но не «50-летию»: букв не больше трёх.
		if ( preg_match( '/\p{N}/u', $core ) && preg_match( '/\p{L}/u', $core ) ) {
			return preg_match_all( '/\p{L}/u', $core ) <= 3;
		}
		if ( preg_match( '/^[IVXLC]{1,5}$/', $core ) ) {
			return true;
		}
		$len = self::len( $core );
		if ( $len > 4 ) {
			return false;
		}
		return ! preg_match( '/[аеёиоуыэюяaeiouy]/iu', $core );
	}

	/**
	 * Длина строки в символах (для страницы проверки).
	 *
	 * @param string $text Строка.
	 * @return int
	 */
	public static function length( $text ) {
		return self::len( $text );
	}

	/**
	 * Длина строки в символах.
	 *
	 * @param string $text Строка.
	 * @return int
	 */
	private static function len( $text ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $text, 'UTF-8' ) : strlen( (string) $text );
	}
}
