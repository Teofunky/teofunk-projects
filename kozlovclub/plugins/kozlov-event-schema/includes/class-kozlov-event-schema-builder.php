<?php
/**
 * Сборка данных schema.org Event из анонса концерта.
 *
 * Источники (проверены на живом сайте):
 * - дата и время, цена: таблица All-in-One Event Calendar `{prefix}ai1ec_events`;
 * - сцена: поля Magic Fields location_main_scene / location_mansarda / location_the_beat / location_myasnitskaya;
 * - описание: поле Magic Fields textblock_1_description;
 * - продажа билетов: поле ticketscloud_link;
 * - жанры: поле Magic Fields genre_categories (значения вида «Джаз|Jazz»);
 * - картинка: изображение записи;
 * - исполнители: строка состава «На сцену выйдут: Имя (инструмент), …» в текстовых блоках анонса
 *   или собственное поле плагина _kozlov_event_performers (если редактор вписал вручную);
 * - начало продаж (offers.validFrom): дата публикации анонса;
 * - отмена или перенос: собственное поле плагина _kozlov_event_status (по умолчанию пусто).
 *
 * Класс только читает данные. Никаких записей в базу.
 *
 * @package Kozlov_Event_Schema
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'Kozlov_Event_Schema_Builder' ) ) {
	return;
}

/**
 * Builder.
 */
class Kozlov_Event_Schema_Builder {

	const POST_TYPE = 'ai1ec_event';

	/** Часовой пояс клуба: время в анонсах введено по Москве. */
	const CLUB_TZ = 'Europe/Moscow';

	const CLUB_NAME = 'Клуб Алексея Козлова';

	/** Событие длиннее недели считаем ошибкой ввода и не выводим endDate. */
	const MAX_DURATION = 604800;

	/** Если нет времени окончания, считаем концерт прошедшим через 6 часов после начала. */
	const DEFAULT_LENGTH = 21600;

	/** Минимальная ширина картинки по требованиям Google. */
	const MIN_IMAGE_WIDTH = 720;

	const DESCRIPTION_MAX = 500;

	/** Собственное поле плагина: статус концерта для поисковиков. */
	const STATUS_META = '_kozlov_event_status';

	/** Собственное поле плагина: прежняя дата перенесённого концерта («Y-m-d H:i» по Москве). */
	const PREVIOUS_META = '_kozlov_event_previous_start';

	/** Поле Magic Fields с жанрами. */
	const GENRE_META = 'genre_categories';

	/** Собственное поле плагина: исполнители, вписанные редактором вручную (по одному на строке; «-» значит не выводить). */
	const PERFORMERS_META = '_kozlov_event_performers';

	/** Поля Magic Fields с текстом анонса, в которых ищется состав («На сцену выйдут: Имя (инструмент), …»). */
	const PERFORMER_FIELDS = array( 'textblock_2_description', 'textblock_1_description', 'additional_text_description', 'artist_description' );

	/** Больше исполнителей в разметку не выводим. */
	const MAX_PERFORMERS = 15;

	/**
	 * Начало строки состава: «на сцену выйдут:», «в составе:», «примут участие:», «специальный гость:», «ведущий джема:» и т. п.
	 * Двоеточие обязательно и должно стоять в том же предложении (до 80 знаков после слова).
	 */
	const LINEUP_MARKER = '/(?<!\p{L})(?:выйд(?:ут|ет)|прим(?:ут|ет)\s+участие|в\s+состав[еа]|состав|участники|исполнители|сыграют|играют|выступ(?:ят|ают|ит)|гост(?:ь|ья|и)|ведущ(?:ий|ая|ие))(?!\p{L})[^:.!?]{0,80}:/iu';

	/** Признаки коллектива для вписанных вручную исполнителей (тогда тип PerformingGroup, иначе Person). */
	const GROUP_WORDS = '/(?<!\p{L})(?:трио|квартет|квинтет|секстет|септет|октет|нонет|оркестр|бэнд|бенд|ансамбль|группа|хор|проект|band|trio|quartet|quintet|sextet|orchestra|ensemble|project|choir|collective)(?!\p{L})|[«»"]/iu';

	/** Отметки из списка жанров, которые не являются жанрами и не идут в keywords. */
	const NOT_KEYWORDS = array( 'Вход свободный' );

	/** Отметки, при которых событие не концерт, а обучающее (тип EducationEvent). */
	const EDUCATION_TAGS = array( 'Лекция', 'Мастер-класс' );

	/** Проблемы, при которых разметка на сайте не выводится. */
	const BLOCKING = array( 'no_calendar_row', 'bad_start', 'no_title', 'no_url', 'recurring', 'password', 'not_published', 'ended' );

	/** Статусы, при которых разметка выводится и после исходной даты. */
	const KEEP_AFTER_DATE = array( 'postponed', 'cancelled' );

	/**
	 * Кэш строк календаря на время одного запроса.
	 *
	 * @var array
	 */
	private static $rows = array();

	/**
	 * Сцены: поле Magic Fields => название и площадка.
	 *
	 * @return array
	 */
	public static function stages() {
		return array(
			'location_main_scene'   => array(
				'label' => 'Главная сцена',
				'venue' => 'marosejka',
				'id'    => 'stage-main',
			),
			'location_mansarda'     => array(
				'label' => 'Мансарда',
				'venue' => 'marosejka',
				'id'    => 'stage-mansarda',
			),
			'location_the_beat'     => array(
				'label' => 'The Beat',
				'venue' => 'marosejka',
				'id'    => 'stage-the-beat',
			),
			'location_myasnitskaya' => array(
				'label' => 'Сцена на Мясницкой, 15',
				'venue' => 'myasnitskaya',
				'id'    => 'stage-myasnitskaya',
			),
		);
	}

	/**
	 * Адреса площадок.
	 *
	 * @return array
	 */
	public static function venues() {
		return array(
			'marosejka'    => array(
				'streetAddress'   => 'ул. Маросейка, 9/2',
				'addressLocality' => 'Москва',
				'addressRegion'   => 'Москва',
				'addressCountry'  => 'RU',
			),
			'myasnitskaya' => array(
				'streetAddress'   => 'ул. Мясницкая, 15',
				'addressLocality' => 'Москва',
				'addressRegion'   => 'Москва',
				'addressCountry'  => 'RU',
			),
		);
	}

	/**
	 * Статусы концерта: значение поля => [подпись, eventStatus].
	 *
	 * По правилам Google:
	 * - отложен (новой даты нет): EventPostponed, в календаре остаётся прежняя дата;
	 * - перенесён на новую дату: EventRescheduled, в календаре новая дата, прежняя в previousStartDate;
	 * - отменён: EventCancelled, дата и место остаются.
	 *
	 * @return array
	 */
	public static function statuses() {
		return array(
			''            => array( 'По расписанию', 'https://schema.org/EventScheduled' ),
			'postponed'   => array( 'Отложен, новая дата неизвестна', 'https://schema.org/EventPostponed' ),
			'rescheduled' => array( 'Перенесён на новую дату', 'https://schema.org/EventRescheduled' ),
			'cancelled'   => array( 'Отменён', 'https://schema.org/EventCancelled' ),
		);
	}

	/**
	 * Прежняя дата перенесённого концерта: «Y-m-d H:i» (по Москве) -> ISO 8601 с +03:00.
	 *
	 * @param string $value Значение поля.
	 * @return string|null
	 */
	public static function previous_start_iso( $value ) {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}$/', $value ) ) {
			return null;
		}
		try {
			$date = DateTime::createFromFormat( 'Y-m-d H:i', str_replace( 'T', ' ', $value ), new DateTimeZone( self::CLUB_TZ ) );
		} catch ( Exception $e ) {
			return null;
		}
		if ( ! $date || $date->format( 'Y-m-d H:i' ) !== str_replace( 'T', ' ', $value ) ) {
			return null;
		}
		return $date->format( 'c' );
	}

	/**
	 * Текущий статус концерта (ключ из statuses()).
	 *
	 * @param int $post_id ID записи.
	 * @return string
	 */
	public static function status( $post_id ) {
		$value = get_post_meta( $post_id, self::STATUS_META, true );
		$value = is_scalar( $value ) ? (string) $value : '';
		return array_key_exists( $value, self::statuses() ) ? $value : '';
	}

	/**
	 * Отметки из «Жанров» (русская часть до «|»).
	 *
	 * @param int $post_id ID записи.
	 * @return string[]
	 */
	public static function genres( $post_id ) {
		$raw  = get_post_meta( $post_id, self::GENRE_META, false );
		$flat = array();
		$walk = function ( $value ) use ( &$walk, &$flat ) {
			$value = maybe_unserialize( $value );
			if ( is_array( $value ) ) {
				foreach ( $value as $item ) {
					$walk( $item );
				}
			} elseif ( is_scalar( $value ) ) {
				$flat[] = (string) $value;
			}
		};
		$walk( $raw );

		$out = array();
		foreach ( $flat as $value ) {
			$parts = explode( '|', $value );
			$name  = self::plain_text( $parts[0] );
			if ( '' !== $name && ! in_array( $name, $out, true ) ) {
				$out[] = $name;
			}
		}
		return $out;
	}

	/**
	 * Исполнители концерта для свойства performer.
	 *
	 * Порядок: вписанные редактором вручную (поле плагина), иначе состав из текста анонса.
	 * «-» в ручном поле: не выводить исполнителей совсем.
	 *
	 * @param int $post_id ID записи.
	 * @return array {source: manual|text|off|'', list: array[]} list: [['@type' => Person|PerformingGroup, 'name' => ...]]
	 */
	public static function performers( $post_id ) {
		$manual = get_post_meta( $post_id, self::PERFORMERS_META, true );
		$manual = is_scalar( $manual ) ? trim( (string) $manual ) : '';
		if ( '-' === $manual ) {
			return array(
				'source' => 'off',
				'list'   => array(),
			);
		}
		if ( '' !== $manual ) {
			$list = self::manual_performers( $manual );
			if ( ! empty( $list ) ) {
				return array(
					'source' => 'manual',
					'list'   => $list,
				);
			}
		}

		$names = array();
		foreach ( self::PERFORMER_FIELDS as $field ) {
			foreach ( self::flat_values( get_post_meta( $post_id, $field, false ) ) as $value ) {
				foreach ( self::parse_performers( $value ) as $name ) {
					if ( ! in_array( $name, $names, true ) ) {
						$names[] = $name;
					}
				}
			}
		}

		$list = array();
		foreach ( array_slice( $names, 0, self::MAX_PERFORMERS ) as $name ) {
			$list[] = array(
				'@type' => 'Person',
				'name'  => $name,
			);
		}
		return array(
			'source' => empty( $list ) ? '' : 'text',
			'list'   => $list,
		);
	}

	/**
	 * Состав из текста: имена из строки вида «На сцену выйдут: Имя Фамилия (инструмент), …».
	 *
	 * Берутся только пары «Имя (роль)» после слова-маркера с двоеточием. Варианты «X или Y» пропускаются.
	 *
	 * @param string $html Текст поля (HTML).
	 * @return string[]
	 */
	public static function parse_performers( $html ) {
		$names = array();
		foreach ( self::text_lines( $html ) as $line ) {
			if ( ! preg_match( self::LINEUP_MARKER, $line, $m, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}
			$rest = (string) substr( $line, $m[0][1] + strlen( $m[0][0] ) );
			if ( ! preg_match_all( '/([^()]*?)\(([^()]{1,160})\)/u', $rest, $pairs, PREG_SET_ORDER ) ) {
				continue;
			}
			foreach ( $pairs as $pair ) {
				$chunks = preg_split( '/[,;:]|\s[\x{2013}\x{2014}-]\s/u', $pair[1] );
				$chunk  = is_array( $chunks ) ? trim( (string) end( $chunks ) ) : '';
				if ( '' === $chunk || preg_match( '/(?<!\p{L})или(?!\p{L})/iu', $chunk ) ) {
					continue;
				}
				$name = self::trailing_name( $chunk );
				if ( '' !== $name && ! in_array( $name, $names, true ) ) {
					$names[] = $name;
				}
			}
		}
		return $names;
	}

	/**
	 * Имя в конце фрагмента: подряд идущие слова с заглавной буквы (1-4 слова).
	 * «и специальный гость концерта Пётр Дольский» -> «Пётр Дольский».
	 *
	 * @param string $chunk Фрагмент текста перед скобкой.
	 * @return string
	 */
	public static function trailing_name( $chunk ) {
		$words = preg_split( '/\s+/u', trim( (string) $chunk ) );
		if ( ! is_array( $words ) ) {
			return '';
		}
		$taken = array();
		for ( $i = count( $words ) - 1; $i >= 0; $i-- ) {
			// Кавычки снимаем регулярным выражением: trim() работает по байтам и портит кириллицу (у «л» и «»» общий байт).
			$word = (string) preg_replace( '/^[«»"\'\x{201C}\x{201D}\x{201E}]+|[«»"\'\x{201C}\x{201D}\x{201E}]+$/u', '', $words[ $i ] );
			if ( '' === $word || ! preg_match( '/^\p{Lu}[\p{L}\p{M}\'\x{2019}.\-]*$/u', $word ) ) {
				break;
			}
			array_unshift( $taken, $word );
		}
		$count = count( $taken );
		if ( 0 === $count || $count > 4 ) {
			return '';
		}
		$name = implode( ' ', $taken );
		$len  = function_exists( 'mb_strlen' ) ? mb_strlen( $name, 'UTF-8' ) : strlen( $name );
		if ( $len < 2 || $len > 60 ) {
			return '';
		}
		return $name;
	}

	/**
	 * Исполнители, вписанные вручную: по одному на строке (или через запятую в одной строке),
	 * роль в скобках отбрасывается. Коллективы (трио, оркестр, «…») получают тип PerformingGroup.
	 *
	 * @param string $text Значение поля.
	 * @return array[]
	 */
	public static function manual_performers( $text ) {
		$text  = str_replace( "\r", '', (string) $text );
		$items = false !== strpos( $text, "\n" ) ? explode( "\n", $text ) : explode( ',', $text );
		$list  = array();
		$seen  = array();
		foreach ( $items as $item ) {
			$name = preg_replace( '/\([^()]*\)/u', '', wp_strip_all_tags( (string) $item ) );
			$name = trim( (string) preg_replace( '/\s+/u', ' ', (string) $name ), " \t,;" );
			$len  = function_exists( 'mb_strlen' ) ? mb_strlen( $name, 'UTF-8' ) : strlen( $name );
			if ( $len < 2 || $len > 100 || in_array( $name, $seen, true ) ) {
				continue;
			}
			$seen[] = $name;
			$list[] = array(
				'@type' => preg_match( self::GROUP_WORDS, $name ) ? 'PerformingGroup' : 'Person',
				'name'  => $name,
			);
			if ( count( $list ) >= self::MAX_PERFORMERS ) {
				break;
			}
		}
		return $list;
	}

	/**
	 * Значения поля (одно или несколько, в том числе сериализованные массивы) плоским списком строк.
	 *
	 * @param mixed $raw Результат get_post_meta( ..., false ).
	 * @return string[]
	 */
	private static function flat_values( $raw ) {
		$flat = array();
		$walk = function ( $value ) use ( &$walk, &$flat ) {
			$value = maybe_unserialize( $value );
			if ( is_array( $value ) ) {
				foreach ( $value as $item ) {
					$walk( $item );
				}
			} elseif ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				$flat[] = (string) $value;
			}
		};
		$walk( $raw );
		return $flat;
	}

	/**
	 * HTML -> строки текста (абзацы и переносы сохраняются).
	 *
	 * @param string $html Текст.
	 * @return string[]
	 */
	public static function text_lines( $html ) {
		$text = self::ru_text( $html );
		if ( '' === $text ) {
			return array();
		}
		if ( function_exists( 'strip_shortcodes' ) ) {
			$text = strip_shortcodes( $text );
		}
		$broken = preg_replace( '#<(?:br|/p|/div|/li|/h[1-6]|/tr)\b[^>]*>#i', "\n", $text );
		$text   = null === $broken ? $text : $broken;
		$text   = wp_strip_all_tags( $text );
		$text   = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text   = str_replace( array( "\xC2\xA0", "\xE2\x80\xAF", "\xE2\x80\x89", "\r" ), array( ' ', ' ', ' ', '' ), $text );
		$lines  = array();
		foreach ( explode( "\n", $text ) as $line ) {
			$line = preg_replace( '/[ \t]+/u', ' ', $line );
			$line = trim( (string) $line );
			if ( '' !== $line ) {
				$lines[] = $line;
			}
		}
		return $lines;
	}

	/**
	 * Дата начала продаж для offers.validFrom: дата публикации анонса (по Москве).
	 * Не выводится, если дата неизвестна или не раньше начала концерта.
	 *
	 * @param WP_Post $post      Анонс.
	 * @param int     $start_utc Начало концерта (unix).
	 * @return string|null
	 */
	public static function valid_from( WP_Post $post, $start_utc ) {
		$gmt = trim( (string) $post->post_date_gmt );
		if ( '' === $gmt || 0 === strpos( $gmt, '0000' ) ) {
			return null;
		}
		try {
			$date = new DateTime( $gmt, new DateTimeZone( 'UTC' ) );
			if ( $date->getTimestamp() >= (int) $start_utc ) {
				return null;
			}
			$date->setTimezone( new DateTimeZone( self::CLUB_TZ ) );
		} catch ( Exception $e ) {
			return null;
		}
		return $date->format( 'c' );
	}

	/**
	 * Человекочитаемые описания проблем (для страницы проверки).
	 *
	 * @return array
	 */
	public static function issue_labels() {
		return array(
			'no_calendar_row'    => 'Нет даты в календаре: разметка не выводится',
			'bad_start'          => 'Не читается дата начала: разметка не выводится',
			'no_title'           => 'Нет заголовка: разметка не выводится',
			'no_url'             => 'Нет адреса страницы: разметка не выводится',
			'recurring'          => 'Повторяющееся событие: разметка не выводится',
			'password'           => 'Защищён паролем: разметка не выводится',
			'not_published'      => 'Не опубликован: разметка появится после публикации',
			'ended'              => 'Уже прошёл',
			'no_stage'           => 'Не отмечена сцена (в разметке будет адрес Маросейка, 9/2)',
			'multi_stage'        => 'Отмечено несколько сцен (взята первая)',
			'price_empty'        => 'Не указана стоимость: блок цены не выводится',
			'price_unknown'      => 'Стоимость не распознана: блок цены не выводится',
			'paid_no_tickets'    => 'Платный, но нет ссылки Ticketscloud: блок цены не выводится',
			'no_image'           => 'Нет изображения записи',
			'small_image'        => 'Ширина изображения меньше 720 px: в разметку не включено',
			'no_description'     => 'Пустой «Текстовый блок №1»: описание не выводится',
			'long_end'           => 'Окончание позже чем через 7 дней или раньше начала: endDate не выводится',
			'no_genres'          => 'Не отмечены жанры: keywords не выводятся',
			'status_postponed'   => 'Отмечен как отложенный: в разметке EventPostponed. Проверьте, что это написано в тексте анонса',
			'status_rescheduled' => 'Отмечен как перенесённый на новую дату: в разметке EventRescheduled',
			'status_cancelled'   => 'Отмечен как отменённый: в разметке EventCancelled. Проверьте, что это написано в тексте анонса',
			'no_previous_start'  => 'Перенесён, но не указана прежняя дата (или она совпадает с новой): previousStartDate не выводится',
			'no_performer'       => 'Состав не найден в тексте анонса: исполнители (performer) не выводятся. Их можно вписать в блоке «Для поисковиков» в редакторе анонса',
		);
	}

	/**
	 * Имя таблицы календаря.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'ai1ec_events';
	}

	/**
	 * Читает строки календаря пачкой (для страницы проверки).
	 *
	 * @param array $ids ID записей.
	 */
	public static function prefetch_rows( array $ids ) {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		$ids = array_values( array_diff( $ids, array_keys( self::$rows ) ) );
		if ( empty( $ids ) ) {
			return;
		}
		foreach ( $ids as $id ) {
			self::$rows[ $id ] = null;
		}

		$table = self::table();
		foreach ( array_chunk( $ids, 200 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- имя таблицы из $wpdb->prefix, значения через %d.
			$sql      = $wpdb->prepare( "SELECT `post_id`, `start`, `end`, `timezone_name`, `allday`, `instant_event`, `recurrence_rules`, `cost` FROM `{$table}` WHERE `post_id` IN ({$placeholders})", $chunk );
			$suppress = $wpdb->suppress_errors( true );
			$results  = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
			$wpdb->suppress_errors( $suppress );
			if ( is_array( $results ) ) {
				foreach ( $results as $row ) {
					self::$rows[ (int) $row['post_id'] ] = $row;
				}
			}
		}
	}

	/**
	 * Строка календаря для одной записи.
	 *
	 * @param int $post_id ID записи.
	 * @return array|null
	 */
	public static function get_row( $post_id ) {
		$post_id = (int) $post_id;
		if ( ! array_key_exists( $post_id, self::$rows ) ) {
			self::prefetch_rows( array( $post_id ) );
		}
		return isset( self::$rows[ $post_id ] ) ? self::$rows[ $post_id ] : null;
	}

	/**
	 * Разметка для вывода на сайте: только опубликованные, без пароля, ещё не прошедшие.
	 *
	 * @param WP_Post $post Анонс.
	 * @return array {data: array|null, issues: string[], info: array}
	 */
	public static function build_for_front( WP_Post $post ) {
		$result = self::analyze( $post );
		if ( self::is_blocked( $result['issues'] ) ) {
			$result['data'] = null;
		}
		return $result;
	}

	/**
	 * Есть ли среди проблем такая, при которой разметка не выводится.
	 *
	 * @param string[] $issues Коды проблем.
	 * @return bool
	 */
	public static function is_blocked( array $issues ) {
		return (bool) array_intersect( $issues, self::BLOCKING );
	}

	/**
	 * Полный разбор анонса: данные разметки и список проблем.
	 *
	 * @param WP_Post $post Анонс.
	 * @param int     $now  Текущее время (unix), для тестов.
	 * @return array {data: array|null, issues: string[], info: array}
	 */
	public static function analyze( WP_Post $post, $now = null ) {
		$now    = null === $now ? time() : (int) $now;
		$issues = array();
		$info   = array(
			'start'      => '',
			'stage'      => '',
			'price'      => '',
			'status'     => $post->post_status,
			'schedule'   => '',
			'performers' => '',
			'perf_from'  => '',
		);
		$empty  = array(
			'data'   => null,
			'issues' => array(),
			'info'   => $info,
		);

		if ( self::POST_TYPE !== $post->post_type ) {
			return $empty;
		}

		if ( 'publish' !== $post->post_status ) {
			$issues[] = 'not_published';
		}
		if ( '' !== (string) $post->post_password ) {
			$issues[] = 'password';
		}

		$name = self::plain_text( $post->post_title );
		if ( '' === $name ) {
			$issues[] = 'no_title';
		}

		$row = self::get_row( $post->ID );
		if ( empty( $row ) ) {
			$issues[]        = 'no_calendar_row';
			$empty['issues'] = $issues;
			$empty['info']   = $info;
			return $empty;
		}

		if ( '' !== trim( (string) ( isset( $row['recurrence_rules'] ) ? $row['recurrence_rules'] : '' ) ) ) {
			$issues[] = 'recurring';
		}

		$times = self::event_times( $row );
		if ( null === $times ) {
			$issues[]        = 'bad_start';
			$empty['issues'] = $issues;
			return $empty;
		}
		$info['start'] = $times['display'];
		if ( $times['long_end'] ) {
			$issues[] = 'long_end';
		}
		// Статус нужен до проверки «прошёл»: отложенный и отменённый концерт остаются в разметке
		// и после исходной даты (Google: startDate не трогать, менять только eventStatus).
		$status_key       = self::status( $post->ID );
		$statuses         = self::statuses();
		$info['schedule'] = $statuses[ $status_key ][0];
		if ( '' !== $status_key ) {
			$issues[] = 'status_' . $status_key;
		}

		$finish = null !== $times['end_utc'] ? $times['end_utc'] : $times['start_utc'] + self::DEFAULT_LENGTH;
		if ( $finish < $now && ! in_array( $status_key, self::KEEP_AFTER_DATE, true ) ) {
			$issues[] = 'ended';
		}

		$url = self::encode_url( (string) get_permalink( $post ) );
		if ( '' === $url ) {
			$issues[]        = 'no_url';
			$empty['issues'] = $issues;
			return $empty;
		}

		// Место.
		$stage_keys = self::stage_keys( $post->ID );
		$stages     = self::stages();
		$venues     = self::venues();
		$home       = self::encode_url( home_url( '/' ) );
		if ( empty( $stage_keys ) ) {
			$issues[]   = 'no_stage';
			$venue_key  = 'marosejka';
			$place_name = self::CLUB_NAME;
			$place_id   = $home . '#venue-marosejka';
		} else {
			if ( count( $stage_keys ) > 1 ) {
				$issues[] = 'multi_stage';
			}
			$stage         = $stages[ $stage_keys[0] ];
			$venue_key     = $stage['venue'];
			$place_name    = self::CLUB_NAME . ', ' . $stage['label'];
			$place_id      = $home . '#' . $stage['id'];
			$info['stage'] = $stage['label'];
		}
		$address = array_merge( array( '@type' => 'PostalAddress' ), $venues[ $venue_key ] );

		// Жанры: keywords и тип события.
		$genres   = self::genres( $post->ID );
		$keywords = array_values( array_diff( $genres, self::NOT_KEYWORDS ) );
		$type     = array_intersect( $genres, self::EDUCATION_TAGS ) ? 'EducationEvent' : 'MusicEvent';
		if ( empty( $keywords ) ) {
			$issues[] = 'no_genres';
		}

		// Организатор: та же сущность, что Organization в разметке Yoast (если Yoast включён).
		$organizer = array(
			'@type' => 'Organization',
			'name'  => self::CLUB_NAME,
			'url'   => $home,
		);
		if ( defined( 'WPSEO_VERSION' ) ) {
			$organizer = array_merge( array( '@id' => $home . '#organization' ), $organizer );
		}

		$data = array(
			'@context'            => 'https://schema.org',
			'@type'               => $type,
			'@id'                 => $url . '#event',
			'name'                => $name,
			'url'                 => $url,
			'startDate'           => $times['start'],
			'eventStatus'         => $statuses[ $status_key ][1],
			'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
			'location'            => array(
				// Place указан явно, как в документации Google; MusicVenue уточняет вид площадки.
				'@type'   => array( 'Place', 'MusicVenue' ),
				'@id'     => $place_id,
				'name'    => $place_name,
				'address' => $address,
			),
			'organizer'           => $organizer,
		);
		if ( null !== $times['end'] ) {
			$data['endDate'] = $times['end'];
		}
		if ( ! empty( $keywords ) ) {
			$data['keywords'] = implode( ', ', $keywords );
		}
		// Исполнители: вписанные вручную или состав из текста анонса.
		$performers = self::performers( $post->ID );
		if ( ! empty( $performers['list'] ) ) {
			$data['performer'] = $performers['list'];
			$names             = array();
			foreach ( $performers['list'] as $performer ) {
				$names[] = $performer['name'];
			}
			$info['performers'] = implode( ', ', $names );
			$info['perf_from']  = $performers['source'];
		} elseif ( 'off' === $performers['source'] ) {
			$info['perf_from'] = 'off';
		} else {
			$issues[] = 'no_performer';
		}

		if ( 'rescheduled' === $status_key ) {
			$previous = self::previous_start_iso( get_post_meta( $post->ID, self::PREVIOUS_META, true ) );
			if ( null !== $previous && $previous !== $times['start'] ) {
				$data['previousStartDate'] = $previous;
			} else {
				$issues[] = 'no_previous_start';
			}
		}

		// Описание.
		$description = self::truncate( self::plain_text( get_post_meta( $post->ID, 'textblock_1_description', true ) ), self::DESCRIPTION_MAX );
		if ( '' === $description ) {
			$description = self::truncate( self::plain_text( $post->post_excerpt ), self::DESCRIPTION_MAX );
		}
		if ( '' === $description ) {
			$issues[] = 'no_description';
		} else {
			$data['description'] = $description;
		}

		// Картинка.
		$thumb_id = (int) get_post_thumbnail_id( $post );
		$image    = $thumb_id ? wp_get_attachment_image_src( $thumb_id, 'full' ) : false;
		if ( is_array( $image ) && ! empty( $image[0] ) ) {
			$width = isset( $image[1] ) ? (int) $image[1] : 0;
			if ( 0 === $width || $width >= self::MIN_IMAGE_WIDTH ) {
				$data['image'] = array( self::encode_url( $image[0] ) );
			} else {
				$issues[] = 'small_image';
			}
		} else {
			$issues[] = 'no_image';
		}

		// Цена и билеты.
		$cost          = self::parse_cost( isset( $row['cost'] ) ? $row['cost'] : '' );
		$info['price'] = $cost['text'];
		$tickets_link  = trim( (string) get_post_meta( $post->ID, 'ticketscloud_link', true ) );
		$valid_from    = self::valid_from( $post, $times['start_utc'] );
		// Цена выводится при любом статусе: по правилам Google при отмене, отсрочке и переносе
		// меняется только eventStatus (и startDate с previousStartDate при переносе), остальные свойства не трогают.
		switch ( $cost['kind'] ) {
			case 'free':
				$data['isAccessibleForFree'] = true;
				$data['offers']              = self::offer( 0, $url, $valid_from );
				break;
			case 'from':
			case 'exact':
				if ( '' !== $tickets_link ) {
					$data['offers'] = self::offer( $cost['price'], $url, $valid_from );
				} else {
					$issues[] = 'paid_no_tickets';
				}
				break;
			case 'deposit':
				// Депозит не билет: цену не выводим.
				break;
			case 'empty':
				$issues[] = 'price_empty';
				break;
			default:
				$issues[] = 'price_unknown';
		}

		if ( in_array( 'no_title', $issues, true ) ) {
			$data = null;
		}

		return array(
			'data'   => $data,
			'issues' => array_values( array_unique( $issues ) ),
			'info'   => $info,
		);
	}

	/**
	 * Offer одного билета.
	 *
	 * Наличие билетов (availability) не выводим: ссылка Ticketscloud не означает,
	 * что билеты не распроданы, а неверный InStock хуже, чем отсутствие поля.
	 *
	 * @param int         $price      Цена в рублях.
	 * @param string      $url        Страница с покупкой.
	 * @param string|null $valid_from Начало продаж (дата публикации анонса) или null.
	 * @return array
	 */
	private static function offer( $price, $url, $valid_from = null ) {
		$offer = array(
			'@type'         => 'Offer',
			'price'         => (int) $price,
			'priceCurrency' => 'RUB',
			'url'           => $url,
		);
		if ( null !== $valid_from ) {
			$offer['validFrom'] = $valid_from;
		}
		return $offer;
	}

	/**
	 * Отмеченные сцены (поля Magic Fields со значением 1).
	 *
	 * @param int $post_id ID записи.
	 * @return string[]
	 */
	public static function stage_keys( $post_id ) {
		$found = array();
		foreach ( array_keys( self::stages() ) as $key ) {
			$value = get_post_meta( $post_id, $key, true );
			if ( is_scalar( $value ) && '1' === trim( (string) $value ) ) {
				$found[] = $key;
			}
		}
		return $found;
	}

	/**
	 * Время начала и окончания.
	 *
	 * Календарь хранит unix-время, посчитанное из введённого времени в поясе события
	 * (на сайте это «UTC»). Восстанавливаем введённое «настенное» время в поясе события
	 * и считаем его московским, потому что именно так оно показывается на сайте.
	 *
	 * @param array $row Строка календаря.
	 * @return array|null
	 */
	public static function event_times( array $row ) {
		$start_ts = isset( $row['start'] ) && is_numeric( $row['start'] ) ? (int) $row['start'] : 0;
		if ( $start_ts <= 0 ) {
			return null;
		}
		$end_ts  = isset( $row['end'] ) && is_numeric( $row['end'] ) ? (int) $row['end'] : 0;
		$allday  = ! empty( $row['allday'] );
		$instant = ! empty( $row['instant_event'] );

		try {
			$source = self::source_timezone( isset( $row['timezone_name'] ) ? $row['timezone_name'] : '' );
			$club   = new DateTimeZone( self::CLUB_TZ );
			$start  = self::wall_clock( $start_ts, $source, $club );
			// Ночной концерт хранится в афише как 23:59, чтобы остаться в своём вечере; реальное начало 00:00.
			if ( ! $allday && '23:59' === $start->format( 'H:i' ) ) {
				$start->modify( '+1 minute' );
			}
			$end    = null;
			$long   = false;
			if ( ! $instant && $end_ts > 0 ) {
				if ( $end_ts > $start_ts && ( $end_ts - $start_ts ) <= self::MAX_DURATION ) {
					$end = self::wall_clock( $end_ts, $source, $club );
				} elseif ( $end_ts !== $start_ts ) {
					$long = true;
				}
			}
		} catch ( Exception $e ) {
			return null;
		}

		if ( $allday ) {
			$start_str = $start->format( 'Y-m-d' );
			$end_str   = null;
			if ( null !== $end ) {
				$last = clone $end;
				$last->modify( '-1 second' );
				if ( $last->format( 'Y-m-d' ) > $start_str ) {
					$end_str = $last->format( 'Y-m-d' );
				}
			}
		} else {
			$start_str = $start->format( 'c' );
			$end_str   = null !== $end ? $end->format( 'c' ) : null;
		}

		return array(
			'start'     => $start_str,
			'end'       => $end_str,
			'start_utc' => $start->getTimestamp(),
			'end_utc'   => null !== $end ? $end->getTimestamp() : null,
			'display'   => $start->format( $allday ? 'd.m.Y' : 'd.m.Y H:i' ),
			'long_end'  => $long,
		);
	}

	/**
	 * Unix-время -> то же «настенное» время в поясе клуба.
	 *
	 * @param int          $ts     Unix-время из календаря.
	 * @param DateTimeZone $source Пояс события.
	 * @param DateTimeZone $club   Пояс клуба.
	 * @return DateTime
	 */
	private static function wall_clock( $ts, DateTimeZone $source, DateTimeZone $club ) {
		$dt = new DateTime( '@' . (int) $ts );
		$dt->setTimezone( $source );
		return new DateTime( $dt->format( 'Y-m-d H:i:s' ), $club );
	}

	/**
	 * Пояс события; если пустой или неизвестный: пояс сайта, затем UTC (как в самом календаре).
	 *
	 * @param string $name Имя пояса из календаря.
	 * @return DateTimeZone
	 */
	private static function source_timezone( $name ) {
		$candidates = array( trim( (string) $name ), trim( (string) get_option( 'timezone_string' ) ), 'UTC' );
		foreach ( $candidates as $candidate ) {
			if ( '' === $candidate ) {
				continue;
			}
			try {
				return new DateTimeZone( $candidate );
			} catch ( Exception $e ) {
				continue;
			}
		}
		return new DateTimeZone( 'UTC' );
	}

	/**
	 * Разбор текста стоимости.
	 *
	 * @param string $raw Значение поля «Стоимость» (может содержать языковые метки WPGlobus).
	 * @return array {kind: free|from|exact|deposit|empty|unknown, price: int|null, text: string}
	 */
	public static function parse_cost( $raw ) {
		$text = self::plain_text( $raw );
		$text = trim( str_replace( array( "\xC2\xA0", "\xE2\x80\xAF", "\xE2\x80\x89", "\xE2\x80\x8A" ), ' ', $text ) );

		$result = array(
			'kind'  => 'unknown',
			'price' => null,
			'text'  => $text,
		);

		if ( '' === $text ) {
			$result['kind'] = 'empty';
			return $result;
		}
		if ( preg_match( '/депозит/iu', $text ) ) {
			$result['kind'] = 'deposit';
			return $result;
		}
		if ( preg_match( '/(вход\s*свободн|свободн\S*\s+вход|бесплатн|free\s+entry|вход\s+free)/iu', $text ) ) {
			$result['kind']  = 'free';
			$result['price'] = 0;
			return $result;
		}

		$number = '(\d{1,3}(?:[ .]\d{3})+|\d+)';
		if ( preg_match( '/(?:^|[^\p{L}])от\s*' . $number . '/iu', $text, $m ) ) {
			$price = self::to_int( $m[1] );
			if ( null !== $price ) {
				$result['kind']  = 'from';
				$result['price'] = $price;
			}
			return $result;
		}

		if ( preg_match( '/(руб|₽|(?<!\p{L})р\.|rub)/iu', $text ) && preg_match_all( '/' . $number . '/u', $text, $all ) ) {
			$prices = array_values( array_unique( array_filter( array_map( array( __CLASS__, 'to_int' ), $all[1] ), 'is_int' ) ) );
			if ( 1 === count( $prices ) ) {
				$result['kind']  = 'exact';
				$result['price'] = $prices[0];
			}
		}

		return $result;
	}

	/**
	 * «2 000» / «2.000» / «2000» -> 2000; вне диапазона 1..1 000 000 -> null.
	 *
	 * @param string $value Число из текста.
	 * @return int|null
	 */
	public static function to_int( $value ) {
		$digits = preg_replace( '/\D/', '', (string) $value );
		if ( '' === $digits || strlen( $digits ) > 7 ) {
			return null;
		}
		$int = (int) $digits;
		return ( $int >= 1 && $int <= 1000000 ) ? $int : null;
	}

	/**
	 * Русская часть текста с языковыми метками WPGlobus / qTranslate.
	 *
	 * @param string $text Текст.
	 * @return string
	 */
	public static function ru_text( $text ) {
		$text = is_scalar( $text ) ? (string) $text : '';
		if ( '' === $text ) {
			return '';
		}
		if ( false !== strpos( $text, '{:' ) && preg_match( '/\{:ru\}(.*?)\{:\}/su', $text, $m ) ) {
			return $m[1];
		}
		if ( false !== strpos( $text, '[:' ) && preg_match( '/\[:ru\](.*?)(?=\[:[a-z]{0,2}\]|$)/su', $text, $m ) ) {
			return $m[1];
		}
		if ( false !== strpos( $text, '<!--:' ) && preg_match( '/<!--:ru-->(.*?)<!--:-->/su', $text, $m ) ) {
			return $m[1];
		}
		$clean = preg_replace( '/\{:[a-z]{0,2}\}|\[:[a-z]{0,2}\]|<!--:[a-z]{0,2}-->/u', '', $text );
		return null === $clean ? $text : $clean;
	}

	/**
	 * HTML -> чистый текст одной строкой.
	 *
	 * @param string $html Текст.
	 * @return string
	 */
	public static function plain_text( $html ) {
		$text = self::ru_text( $html );
		if ( '' === $text ) {
			return '';
		}
		if ( function_exists( 'strip_shortcodes' ) ) {
			$text = strip_shortcodes( $text );
		}
		$spaced = preg_replace( '#<(?:br|/p|/div|/li|/h[1-6]|/tr|/td)\b[^>]*>#i', ' ', $text );
		$text   = null === $spaced ? $text : $spaced;
		$text   = wp_strip_all_tags( $text );
		$text   = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$single = preg_replace( '/\s+/u', ' ', $text );
		$text   = null === $single ? preg_replace( '/\s+/', ' ', $text ) : $single;
		return trim( (string) $text );
	}

	/**
	 * Обрезка по границе слова.
	 *
	 * @param string $text Текст.
	 * @param int    $max  Максимум символов.
	 * @return string
	 */
	public static function truncate( $text, $max ) {
		$text = (string) $text;
		if ( '' === $text ) {
			return '';
		}
		if ( function_exists( 'mb_strlen' ) ) {
			if ( mb_strlen( $text, 'UTF-8' ) <= $max ) {
				return $text;
			}
			$cut = mb_substr( $text, 0, $max - 1, 'UTF-8' );
		} else {
			if ( ! preg_match( '/^.{' . (int) ( $max - 1 ) . '}/su', $text, $m ) ) {
				return $text;
			}
			$cut = $m[0];
		}
		$space = strrpos( $cut, ' ' );
		if ( false !== $space && $space > 0 ) {
			$cut = substr( $cut, 0, $space );
		}
		return rtrim( $cut, " \t\n\r\0\x0B,.;:-" ) . '…';
	}

	/**
	 * Кодирует кириллицу и пробелы в пути URL (имена файлов картинок).
	 *
	 * @param string $url Адрес.
	 * @return string
	 */
	public static function encode_url( $url ) {
		$url = (string) $url;
		if ( '' === $url || ! preg_match( '/[^\x21-\x7E]/', $url ) ) {
			return $url;
		}
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) ) {
			return $url;
		}
		$path = isset( $parts['path'] ) ? $parts['path'] : '';
		$path = implode( '/', array_map( 'rawurlencode', array_map( 'rawurldecode', explode( '/', $path ) ) ) );
		$out  = ( isset( $parts['scheme'] ) ? $parts['scheme'] : 'https' ) . '://' . $parts['host'];
		$out .= isset( $parts['port'] ) ? ':' . $parts['port'] : '';
		$out .= $path;
		$out .= isset( $parts['query'] ) ? '?' . $parts['query'] : '';
		return $out;
	}

	/**
	 * Массив -> JSON для <script type="application/ld+json">.
	 *
	 * @param array $data   Данные.
	 * @param bool  $pretty С отступами (для страницы проверки).
	 * @return string
	 */
	public static function to_json( $data, $pretty = false ) {
		$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP;
		if ( $pretty ) {
			$flags |= JSON_PRETTY_PRINT;
		}
		$json = wp_json_encode( $data, $flags );
		return is_string( $json ) ? $json : '';
	}
}
