<?php
/**
 * Сборка поискового индекса. Только чтение: один SELECT по таблицам календаря и чтение полей событий.
 * Концерты, закрытые паролем, в индекс не попадают.
 * Результат хранится в кэше и не строится на каждый запрос посетителя.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'KSS_Index' ) ) :

	class KSS_Index {

		const SCHEMA     = 1;
		const KEY_INDEX  = 'kss_index_v1';
		const KEY_TEXTS  = 'kss_texts_v1';
		const KEY_LOCK   = 'kss_lock_v1';   // запасная блокировка, когда файловая недоступна
		const KEY_DIRTY  = 'kss_dirty_v1';  // когда в последний раз сохраняли событие или страницу
		const KEY_FAIL   = 'kss_fail_v1';   // сборка только что не удалась
		const FRESH      = 600;    // столько секунд кэш отдаётся без пересборки
		const KEEP       = 86400;  // столько секунд хранится запасная копия
		const MIN_GAP    = 20;     // между двумя пересборками проходит не меньше стольких секунд
		const FAIL_PAUSE = 30;     // после неудачной сборки столько секунд новых попыток нет
		const WAIT_MS    = 4000;   // столько запрос ждёт при пустом кэше, пока индекс собирает другой запрос
		const MAX_EVENTS = 1500;
		const MAX_DOC    = 700;    // слов на один анонс
		const MAX_VOCAB  = 40000;

		protected static $dirty_planned = false;

		public static function init() {
			// После сохранения события или страницы индекс помечается устаревшим в конце запроса, когда все поля уже записаны.
			// Прежняя копия при этом остаётся в кэше: посетители получают её, пока один запрос собирает новую.
			add_action( 'save_post', array( __CLASS__, 'on_save' ), 99, 2 );
			add_action( 'deleted_post', array( __CLASS__, 'plan_dirty' ), 99, 0 );
			add_action( 'trashed_post', array( __CLASS__, 'plan_dirty' ), 99, 0 );
			add_action( 'untrashed_post', array( __CLASS__, 'plan_dirty' ), 99, 0 );
		}

		/** Сохранение записи: индекс зависит только от концертов и страниц; черновики-ревизии и прочие типы записей его не трогают. */
		public static function on_save( $post_id = 0, $post = null ) {
			try {
				if ( is_object( $post ) && isset( $post->post_type ) && ! in_array( $post->post_type, array( 'ai1ec_event', 'page' ), true ) ) {
					return;
				}
				self::plan_dirty();
			} catch ( \Throwable $e ) {
				kss_log( $e );
			}
		}

		public static function plan_dirty() {
			try {
				if ( self::$dirty_planned ) {
					return;
				}
				self::$dirty_planned = true;
				add_action( 'shutdown', array( __CLASS__, 'mark_dirty' ), 1, 0 );
			} catch ( \Throwable $e ) {
				kss_log( $e );
			}
		}

		public static function mark_dirty() {
			try {
				set_transient( self::KEY_DIRTY, time(), self::KEEP );
			} catch ( \Throwable $e ) {
				kss_log( $e );
			}
		}

		/** Полный сброс кэша: после смены настроек и по кнопке «Пересобрать индекс». */
		public static function flush() {
			try {
				delete_transient( self::KEY_INDEX );
				delete_transient( self::KEY_TEXTS );
				delete_transient( self::KEY_LOCK );
				delete_transient( self::KEY_DIRTY );
				delete_transient( self::KEY_FAIL );
			} catch ( \Throwable $e ) {
				kss_log( $e );
			}
		}

		/** Поля сцен в том же виде, в каком их читает тема. */
		public static function stage_keys() {
			return array(
				'g' => 'location_main_scene',
				'm' => 'location_mansarda',
				'b' => 'location_the_beat',
				'u' => 'location_myasnitskaya',
			);
		}

		/** Поля, из которых тема собирает текст анонса на странице события. */
		public static function text_keys() {
			return array( 'textblock_1_description', 'artist_description', 'textblock_2_description', 'additional_text_description', 'quote_text', 'quote_author' );
		}

		/**
		 * Страницы сайта, которые показываются в поиске, и слова, по которым они находятся.
		 * Ключ: адрес страницы (slug). Страница попадает в поиск, только если она опубликована и не закрыта паролем.
		 */
		public static function page_defs() {
			$defs = array(
				'menu'                    => array( 'бар', 'блюда', 'винная', 'вино', 'выпить', 'еда', 'закуски', 'карта', 'коктейли', 'кофе', 'кухня', 'меню', 'напитки', 'пиво', 'поесть', 'ресторан', 'ужин', 'цены' ),
				'contacts'                => array( 'email', 'адрес', 'бронь', 'вход', 'где', 'добраться', 'доехать', 'дойти', 'ехать', 'забронировать', 'идти', 'как', 'карта', 'клуб', 'маросейка', 'маршрут', 'местоположение', 'метро', 'мясницкая', 'найти', 'находитесь', 'находится', 'номер', 'открыто', 'парковка', 'позвонить', 'почта', 'проезд', 'проезда', 'проехать', 'пройти', 'работает', 'работы', 'расположение', 'расположены', 'режим', 'столик', 'схема', 'телефон', 'часы' ),
				'pravila'                 => array( 'билетов', 'возврат', 'возраст', 'возрастное', 'вход', 'дети', 'детьми', 'детям', 'дресс', 'животные', 'код', 'ограничение', 'одежда', 'опоздал', 'опоздание', 'правила', 'ребенком', 'ребенок', 'съемка', 'фото' ),
				'faq'                     => array( 'вопросы', 'ответы', 'faq', 'помощь' ),
				'sxemy-zalov'             => array( 'где', 'зал', 'зала', 'залов', 'места', 'посадка', 'рассадка', 'сидеть', 'столик', 'столики', 'столы', 'схема', 'схемы' ),
				'interiorstage'           => array( 'интерьер', 'сцены', 'фото', 'зал', 'фотографии' ),
				'abonementy'              => array( 'абонемент', 'подписка' ),
				'vip-card'                => array( 'vip', 'вип', 'карта', 'скидка' ),
				'videoarxiv'              => array( 'видео', 'записи', 'концертов' ),
				'live'                    => array( 'live', 'трансляция', 'онлайн', 'эфир', 'лайв' ),
				'dlya-pressy'             => array( 'пресса', 'сми', 'аккредитация', 'журналистам' ),
				'partners'                => array( 'партнеры', 'спонсоры' ),
				'pravila-dlya-muzykantov' => array( 'правила', 'музыкантам', 'артистам', 'саундчек' ),
				'technicalrider'          => array( 'райдер', 'звук', 'оборудование', 'аппарат' ),
				'jazzevent'               => array( 'мероприятие', 'банкет', 'корпоратив', 'аренда', 'event' ),
				'privacy'                 => array( 'политика', 'конфиденциальность', 'персональные', 'данные' ),
			);
			$filtered = apply_filters( 'kss_pages', $defs );
			return is_array( $filtered ) ? $filtered : $defs;
		}

		/* ------------------------------------------------------------------ кэш */

		protected static function signature() {
			return self::SCHEMA . '|' . KSS_VERSION . '|' . ( KSS_Settings::content() ? 1 : 0 );
		}

		/**
		 * Копия из кэша и её состояние.
		 *
		 * @param string $part  'index' или 'texts'.
		 * @param string $state Сюда пишется 'fresh' (свежая), 'stale' (устарела) или 'none' (копии нет).
		 * @param int    $built Сюда пишется время сборки копии.
		 * @return array|null
		 */
		protected static function read( $part, &$state, &$built ) {
			$state = 'none';
			$built = 0;
			$index = get_transient( self::KEY_INDEX );
			if ( ! is_array( $index ) || ! isset( $index['sig'], $index['built'] ) || $index['sig'] !== self::signature() ) {
				return null;
			}
			$data = $index;
			if ( 'texts' === $part ) {
				$texts = get_transient( self::KEY_TEXTS );
				if ( ! is_array( $texts ) || ! isset( $texts['built'] ) || (int) $texts['built'] !== (int) $index['built'] ) {
					return null;
				}
				$data = $texts;
			}
			$built = (int) $index['built'];
			$age   = time() - $built;
			$dirty = (int) get_transient( self::KEY_DIRTY );
			$state = ( $age >= 0 && $age < self::FRESH && $built > $dirty ) ? 'fresh' : 'stale';
			return $data;
		}

		/**
		 * Сбрасывает память текущего запроса о записях кэша плагина,
		 * чтобы следующее чтение увидело то, что успел записать другой запрос.
		 */
		protected static function forget_local() {
			try {
				$keys = array( self::KEY_INDEX, self::KEY_TEXTS, self::KEY_DIRTY, self::KEY_FAIL, self::KEY_LOCK );
				if ( wp_using_ext_object_cache() ) {
					foreach ( $keys as $key ) {
						wp_cache_get( $key, 'transient', true );
					}
					return;
				}
				$no = wp_cache_get( 'notoptions', 'options' );
				foreach ( $keys as $key ) {
					foreach ( array( '_transient_' . $key, '_transient_timeout_' . $key ) as $option ) {
						wp_cache_delete( $option, 'options' );
						if ( is_array( $no ) ) {
							unset( $no[ $option ] );
						}
					}
				}
				if ( is_array( $no ) ) {
					wp_cache_set( 'notoptions', $no, 'options' );
				}
			} catch ( \Throwable $e ) {
				kss_log( $e );
			}
		}

		/** Файл блокировки во временной папке сервера. В нём ничего не хранится. */
		public static function lock_file() {
			global $wpdb;
			$dir = function_exists( 'get_temp_dir' ) ? get_temp_dir() : sys_get_temp_dir();
			return rtrim( (string) $dir, '/\\' ) . '/kss-' . substr( md5( ABSPATH . '|' . $wpdb->prefix . '|' . ( defined( 'DB_NAME' ) ? DB_NAME : '' ) ), 0, 16 ) . '.lock';
		}

		/**
		 * Блокировка пересборки: одновременно индекс собирает только один запрос.
		 * Основной способ: flock на файле. Он атомарный и снимается сам, если процесс оборвался.
		 * Если файловая блокировка на сервере недоступна, используется запись в кэше.
		 *
		 * @param int    $wait_ms Сколько миллисекунд ждать, если блокировку держит другой запрос. 0: не ждать.
		 * @param string $kind    Сюда пишется способ: 'file' или 'cache'.
		 * @return resource|bool Блокировка или false, если её держит другой запрос.
		 */
		protected static function lock( $wait_ms, &$kind = '' ) {
			$deadline = microtime( true ) + max( 0, (int) $wait_ms ) / 1000;
			$kind     = 'file';
			$handle   = false;
			try {
				$handle = @fopen( self::lock_file(), 'c' ); // phpcs:ignore
			} catch ( \Throwable $e ) {
				$handle = false;
			}
			if ( is_resource( $handle ) ) {
				while ( true ) {
					$would_block = 0;
					if ( @flock( $handle, LOCK_EX | LOCK_NB, $would_block ) ) { // phpcs:ignore
						return $handle;
					}
					if ( ! $would_block ) {
						break; // flock на этом сервере не работает: переходим к запасному способу
					}
					if ( microtime( true ) >= $deadline ) {
						@fclose( $handle ); // phpcs:ignore
						return false;
					}
					usleep( 100000 );
				}
				@fclose( $handle ); // phpcs:ignore
			}
			$kind = 'cache';
			while ( true ) {
				if ( wp_using_ext_object_cache() ) {
					if ( wp_cache_add( self::KEY_LOCK, 1, 'transient', 60 ) ) {
						return true;
					}
				} else {
					self::forget_local();
					if ( ! get_transient( self::KEY_LOCK ) ) {
						set_transient( self::KEY_LOCK, 1, 60 );
						return true;
					}
				}
				if ( microtime( true ) >= $deadline ) {
					return false;
				}
				usleep( 100000 );
			}
		}

		protected static function unlock( $lock ) {
			try {
				if ( is_resource( $lock ) ) {
					@flock( $lock, LOCK_UN ); // phpcs:ignore
					@fclose( $lock ); // phpcs:ignore
				} elseif ( true === $lock ) {
					delete_transient( self::KEY_LOCK );
				}
			} catch ( \Throwable $e ) {
				kss_log( $e );
			}
		}

		/** Какой способ блокировки работает на этом сервере: 'file' или 'cache'. Для страницы настроек. */
		public static function lock_kind() {
			$kind = '';
			$lock = self::lock( 0, $kind );
			if ( false !== $lock ) {
				self::unlock( $lock );
			}
			return $kind;
		}

		/**
		 * Возвращает индекс ('index') или тексты анонсов ('texts') из кэша; при необходимости пересобирает.
		 *
		 * Нагрузка ограничена так:
		 *  - свежая копия отдаётся без обращения к таблицам календаря;
		 *  - собирает индекс всегда только один запрос (блокировка), остальные получают прежнюю копию;
		 *  - при пустом кэше остальные запросы ждут готовый индекс, а не собирают его сами;
		 *  - между двумя пересборками проходит не меньше MIN_GAP секунд, после неудачи пауза FAIL_PAUSE секунд.
		 *
		 * @param string $part  'index' или 'texts'.
		 * @param bool   $force Пересобрать независимо от возраста кэша (кнопка в настройках).
		 * @return array|null
		 */
		public static function get( $part = 'index', $force = false ) {
			$state  = 'none';
			$built  = 0;
			$cached = self::read( $part, $state, $built );
			if ( ! $force ) {
				if ( 'fresh' === $state ) {
					return $cached;
				}
				if ( get_transient( self::KEY_FAIL ) ) {
					return $cached;
				}
				if ( null !== $cached && abs( time() - $built ) < self::MIN_GAP ) {
					return $cached;
				}
			}

			// Есть прежняя копия: не ждём, её и отдаём. Копии нет: ждём, пока индекс соберёт другой запрос.
			$lock = self::lock( ( null === $cached || $force ) ? self::WAIT_MS : 0 );
			if ( false === $lock ) {
				if ( null !== $cached ) {
					return $cached;
				}
				self::forget_local();
				return self::read( $part, $state, $built );
			}

			try {
				if ( ! $force ) {
					// пока этот запрос ждал блокировку, индекс мог собрать другой
					self::forget_local();
					$again = self::read( $part, $state, $built );
					if ( 'fresh' === $state || ( null !== $again && abs( time() - $built ) < self::MIN_GAP ) ) {
						return $again;
					}
					if ( get_transient( self::KEY_FAIL ) ) {
						return ( null !== $again ) ? $again : $cached;
					}
				}
				$result                 = self::build();
				$result['index']['sig'] = self::signature();
				set_transient( self::KEY_TEXTS, $result['texts'], self::KEEP );
				set_transient( self::KEY_INDEX, $result['index'], self::KEEP );
				delete_transient( self::KEY_FAIL );
				try {
					do_action( 'kss_index_built', $result['index']['stats'] );
				} catch ( \Throwable $e ) {
					kss_log( $e );
				}
				return 'texts' === $part ? $result['texts'] : $result['index'];
			} catch ( \Throwable $e ) {
				kss_log( $e );
				try {
					set_transient( self::KEY_FAIL, 1, self::FAIL_PAUSE );
				} catch ( \Throwable $ignored ) {
					return $cached;
				}
				return $cached;
			} finally {
				self::unlock( $lock );
			}
		}

		/* ------------------------------------------------------------ помощники */

		public static function timezone() {
			try {
				if ( function_exists( 'wp_timezone' ) ) {
					return wp_timezone();
				}
				$name = (string) get_option( 'timezone_string' );
				if ( '' !== $name ) {
					return new DateTimeZone( $name );
				}
			} catch ( \Throwable $e ) {
				kss_log( $e );
			}
			return new DateTimeZone( 'Europe/Moscow' );
		}

		/**
		 * Дата и время концерта в том виде, в каком их печатает афиша сайта.
		 *
		 * Тема выводит время вызовом date() от значения start, без пересчёта в часовой пояс сайта:
		 * в базе лежит время «как на часах клуба». Поэтому здесь тот же вызов, а не wp_date()
		 * и не перевод в пояс сайта: иначе поиск покажет время со сдвигом (в 1.0.1 было +3 часа).
		 *
		 * @param int $start Значение start из таблицы календаря.
		 * @return array [ 'Y-m-d', 'H:i' ]
		 */
		public static function when( $start ) {
			$start = (int) $start;
			// phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
			return array( date( 'Y-m-d', $start ), date( 'H:i', $start ) );
		}

		/**
		 * Смещение «часов афиши» от UTC в секундах: афиша показывает концерт, пока start > time(),
		 * а время печатает через date(). Сравнивая с этими часами, поиск убирает концерт в ту же минуту, что и афиша.
		 */
		public static function afisha_offset( $now ) {
			// phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
			return (int) date( 'Z', (int) $now );
		}

		public static function tables_ok() {
			global $wpdb;
			foreach ( array( 'ai1ec_event_instances', 'ai1ec_events' ) as $name ) {
				$table = $wpdb->prefix . $name;
				$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
				if ( $found !== $table ) {
					return false;
				}
			}
			return true;
		}

		/**
		 * Есть ли в таблице дат календаря колонка окончания (end).
		 * Проверяется перед запросом: если колонки нет, индекс строится как раньше, без окончания.
		 */
		public static function has_end() {
			global $wpdb;
			try {
				$col = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$wpdb->prefix}ai1ec_event_instances LIKE %s", 'end' ) );
				return 'end' === $col;
			} catch ( \Throwable $e ) {
				kss_log( $e );
				return false;
			}
		}

		/**
		 * Длительность концерта в минутах по началу и окончанию из календаря.
		 * Оба значения хранятся в одних и тех же «часах клуба», поэтому разница точная.
		 * 0: окончание не указано или выглядит ошибкой (раньше начала или дольше трёх суток).
		 */
		public static function duration( $start, $end ) {
			$start = (int) $start;
			$end   = (int) $end;
			if ( $start <= 0 || $end <= $start ) {
				return 0;
			}
			$min = (int) round( ( $end - $start ) / 60 );
			return ( $min > 0 && $min <= 4320 ) ? $min : 0;
		}

		/** Убирает байты, которые не являются корректным UTF-8. */
		public static function utf8( $s ) {
			$s = (string) $s;
			if ( '' === $s || preg_match( '//u', $s ) ) {
				return $s;
			}
			if ( function_exists( 'mb_convert_encoding' ) ) {
				return (string) mb_convert_encoding( $s, 'UTF-8', 'UTF-8' );
			}
			return (string) preg_replace( '/[^\x09\x0A\x0D\x20-\x7E]/', '', $s );
		}

		/**
		 * Русская часть строки с разметкой WPGlobus: {:ru}текст{:}{:en}text{:} или [:ru]текст[:en]text[:].
		 * Строка без разметки возвращается как есть.
		 */
		public static function ru( $s ) {
			$s = (string) $s;
			if ( false === strpos( $s, '{:' ) && false === strpos( $s, '[:' ) ) {
				return $s;
			}
			if ( false !== strpos( $s, '{:' ) ) {
				if ( preg_match_all( '/\{:ru\}(.*?)(?:\{:\}|$)/s', $s, $m ) && ! empty( $m[1] ) ) {
					return implode( ' ', $m[1] );
				}
				$stripped = preg_replace( '/\{:[a-z]{2}\}.*?(?:\{:\}|$)/s', '', $s );
				if ( is_string( $stripped ) && $stripped !== $s ) {
					return $stripped;
				}
			}
			if ( false !== strpos( $s, '[:' ) ) {
				if ( preg_match_all( '/\[:ru\](.*?)(?=\[:[a-z]{2}\]|\[:\]|$)/s', $s, $m ) && ! empty( $m[1] ) ) {
					return implode( ' ', $m[1] );
				}
				$stripped = preg_replace( '/\[:[a-z]{2}\].*?(?=\[:[a-z]{2}\]|\[:\]|$)/s', '', $s );
				if ( is_string( $stripped ) && $stripped !== $s ) {
					return str_replace( '[:]', '', $stripped );
				}
			}
			return $s;
		}

		/** Текст без тегов, скриптов и HTML-сущностей, в одну строку. */
		public static function plain( $html ) {
			$s = self::utf8( $html );
			if ( '' === $s ) {
				return '';
			}
			$s = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', ' ', $s );
			$s = preg_replace( '/<[^>]*>/', ' ', (string) $s );
			$s = html_entity_decode( (string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$s = str_replace( array( "\xC2\xA0", "\xE2\x80\x8B", "\xC2\xAD" ), array( ' ', '', '' ), $s );
			$s = preg_replace( '/\s+/u', ' ', $s );
			return trim( (string) $s );
		}

		/** Безопасное чтение сериализованного значения: объекты не создаются. */
		public static function unser( $raw ) {
			if ( ! is_string( $raw ) || ! is_serialized( $raw ) ) {
				return $raw;
			}
			$v = @unserialize( $raw, array( 'allowed_classes' => false ) ); // phpcs:ignore
			return ( false === $v && 'b:0;' !== $raw ) ? '' : $v;
		}

		public static function title( $raw ) {
			$t = self::plain( self::ru( $raw ) );
			if ( '' === $t ) {
				return '';
			}
			try {
				// те же типографские замены, что делает WordPress при выводе заголовка на афише
				$tx = wptexturize( $t );
				$tx = html_entity_decode( (string) $tx, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				if ( '' !== trim( $tx ) ) {
					$t = trim( $tx );
				}
			} catch ( \Throwable $e ) {
				kss_log( $e );
			}
			return $t;
		}

		/** Цена так же, как её показывает афиша: русская часть поля cost. */
		public static function price( $raw ) {
			$v = self::unser( $raw );
			if ( is_array( $v ) ) {
				$v = isset( $v['cost'] ) ? $v['cost'] : '';
			}
			if ( ! is_string( $v ) && ! is_numeric( $v ) ) {
				return '';
			}
			return self::plain( self::ru( (string) $v ) );
		}

		protected static function meta_first( $meta, $key ) {
			return ( isset( $meta[ $key ][0] ) && is_scalar( $meta[ $key ][0] ) ) ? (string) $meta[ $key ][0] : '';
		}

		/** Слова, которые в текстах анонсов ничего не различают. */
		protected static function stop_words() {
			static $stop = null;
			if ( null === $stop ) {
				$stop = array_flip(
					array(
						'что', 'это', 'как', 'так', 'его', 'она', 'они', 'оно', 'для', 'при', 'или', 'уже', 'еще', 'был', 'была', 'были', 'было', 'быть', 'есть', 'все', 'всех', 'всего', 'вся', 'том', 'тем', 'тот', 'эта', 'эти', 'этой', 'этого', 'этом', 'этот', 'этих', 'этим', 'также', 'только',
						'которые', 'который', 'которая', 'которых', 'которой', 'котором', 'которого', 'которым', 'которое', 'где', 'когда', 'над', 'под', 'без', 'про', 'между', 'через', 'после', 'перед', 'более', 'менее', 'очень', 'свой', 'своя', 'свои', 'своей', 'своего', 'своих', 'своим', 'свою', 'своем',
						'наш', 'наша', 'наши', 'ваш', 'ваша', 'ваши', 'них', 'ним', 'ней', 'нее', 'него', 'ему', 'чем', 'чтобы', 'если', 'там', 'тут', 'здесь', 'себя', 'себе', 'кто', 'даже', 'ведь', 'вот', 'лишь', 'раз', 'нас', 'вас', 'вам', 'нам', 'мне', 'мой', 'моя', 'мои', 'ими', 'один', 'одна', 'одно', 'одни', 'одного', 'одной',
						'два', 'три', 'сам', 'сама', 'сами', 'может', 'могут', 'можно', 'будет', 'будут', 'году', 'года', 'год', 'лет', 'годы', 'годов', 'время', 'времени', 'других', 'другие', 'другой', 'многих', 'многие', 'много', 'такой', 'такие', 'таких', 'том', 'тех', 'той', 'того', 'тому',
						'http', 'https', 'www', 'com', 'the', 'and', 'for', 'with', 'from', 'that', 'this', 'are', 'was', 'his', 'her', 'you', 'not', 'but', 'all', 'has', 'have', 'nbsp',
					)
				);
			}
			return $stop;
		}

		/**
		 * Слова текста в том же виде, в каком браузер нормализует запрос:
		 * нижний регистр, «ё» как «е», без кавычек и диакритики, только буквы и цифры.
		 *
		 * @return string[] Уникальные слова.
		 */
		public static function words( $text ) {
			$s = self::utf8( $text );
			if ( '' === $s ) {
				return array();
			}
			$s = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
			$s = str_replace( 'ё', 'е', $s );
			if ( preg_match( '/[\x{00c0}-\x{024f}]/u', $s ) ) {
				$s = remove_accents( $s );
				$s = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
			}
			$s = preg_replace( '/[«»"\'`’‘“”]/u', '', $s );
			$s = preg_replace( '/[^a-zа-я0-9]+/u', ' ', (string) $s );
			$stop = self::stop_words();
			$out  = array();
			foreach ( explode( ' ', trim( (string) $s ) ) as $w ) {
				$len = function_exists( 'mb_strlen' ) ? mb_strlen( $w, 'UTF-8' ) : strlen( $w );
				if ( $len < 3 || $len > 30 || isset( $stop[ $w ] ) || isset( $out[ $w ] ) || ! preg_match( '/[a-zа-я]/u', $w ) ) {
					continue;
				}
				$out[ $w ] = true;
			}
			return array_map( 'strval', array_keys( $out ) );
		}

		/** Путь адреса сайта без домена: '' для сайта в корне. */
		protected static function home_path() {
			$path = wp_parse_url( (string) get_option( 'home' ), PHP_URL_PATH );
			return is_string( $path ) ? rtrim( $path, '/' ) : '';
		}

		/** Делает адрес относительным, если он ведёт на этот же сайт. */
		public static function relative( $url ) {
			$url  = (string) $url;
			$host = wp_parse_url( (string) get_option( 'home' ), PHP_URL_HOST );
			$mine = wp_parse_url( $url, PHP_URL_HOST );
			if ( ! $mine || ! $host ) {
				return $url;
			}
			$strip = function ( $h ) {
				return preg_replace( '/^www\./i', '', strtolower( (string) $h ) );
			};
			if ( $strip( $mine ) !== $strip( $host ) ) {
				return $url;
			}
			$rel = preg_replace( '|^(https?:)?//[^/]+|i', '', $url );
			return ( is_string( $rel ) && '' !== $rel ) ? $rel : '/';
		}

		/**
		 * Миниатюра события: путь относительно папки загрузок и вид WebP-копии.
		 * 0: копии нет, 1: рядом лежит файл.jpg.webp, 2: рядом лежит файл.webp,
		 * 3: файл.jpg.webp в папке WebP Express, 4: файл.webp в папке WebP Express.
		 *
		 * @return array [ путь, вид копии ]
		 */
		protected static function thumb( $thumb_id, $up, $webp_dir ) {
			$thumb_id = (int) $thumb_id;
			if ( $thumb_id <= 0 ) {
				return array( '', 0 );
			}
			$data = wp_get_attachment_metadata( $thumb_id, true );
			$file = ( is_array( $data ) && ! empty( $data['file'] ) && is_string( $data['file'] ) ) ? $data['file'] : (string) get_post_meta( $thumb_id, '_wp_attached_file', true );
			$file = ltrim( str_replace( '\\', '/', $file ), '/' );
			if ( '' === $file || false !== strpos( $file, '..' ) || ! preg_match( '/\.(jpe?g|png|gif|webp)$/i', $file ) ) {
				return array( '', 0 );
			}
			$rel   = $file;
			$sizes = ( is_array( $data ) && ! empty( $data['sizes'] ) && is_array( $data['sizes'] ) ) ? $data['sizes'] : array();
			$pick  = '';
			// строке результата нужна картинка шириной 112 px, для чётких экранов берём около 300 px
			foreach ( array( 'medium', 'category-thumb', 'category-thumb2' ) as $name ) {
				if ( ! empty( $sizes[ $name ]['file'] ) && is_string( $sizes[ $name ]['file'] ) ) {
					$pick = $sizes[ $name ]['file'];
					break;
				}
			}
			if ( '' === $pick ) {
				$best = 0;
				foreach ( $sizes as $size ) {
					$w = isset( $size['width'] ) ? (int) $size['width'] : 0;
					if ( $w >= 200 && ( 0 === $best || $w < $best ) && ! empty( $size['file'] ) && is_string( $size['file'] ) ) {
						$best = $w;
						$pick = $size['file'];
					}
				}
			}
			if ( '' !== $pick && false === strpos( $pick, '/' ) && false === strpos( $pick, '..' ) && preg_match( '/\.(jpe?g|png|gif|webp)$/i', $pick ) ) {
				$dir = dirname( $file );
				$rel = ( '.' === $dir ? '' : $dir . '/' ) . $pick;
			}

			$flag = 0;
			if ( ! preg_match( '/\.webp$/i', $rel ) && ! empty( $up['basedir'] ) ) {
				$abs = $up['basedir'] . '/' . $rel;
				if ( @is_file( $abs . '.webp' ) ) { // phpcs:ignore
					$flag = 1;
				} elseif ( @is_file( preg_replace( '/\.[a-z]+$/i', '.webp', $abs ) ) ) { // phpcs:ignore
					$flag = 2;
				} elseif ( '' !== $webp_dir && @is_file( $webp_dir . '/' . $rel . '.webp' ) ) { // phpcs:ignore
					$flag = 3;
				} elseif ( '' !== $webp_dir && @is_file( $webp_dir . '/' . preg_replace( '/\.[a-z]+$/i', '.webp', $rel ) ) ) { // phpcs:ignore
					$flag = 4;
				}
			}
			return array( $rel, $flag );
		}

		/* --------------------------------------------------------------- сборка */

		/**
		 * Собирает индекс заново. Запросы только на чтение.
		 *
		 * @return array [ 'index' => array, 'texts' => array ]
		 */
		public static function build() {
			global $wpdb;

			$t0      = microtime( true );
			$now     = time();
			$tz      = self::timezone();
			$content = KSS_Settings::content();
			$home    = self::home_path();
			$up      = wp_upload_dir( null, false );
			$up      = is_array( $up ) ? $up : array();

			$webp_dir = '';
			$webp_url = '';
			if ( ! empty( $up['basedir'] ) && defined( 'WP_CONTENT_DIR' ) ) {
				$root     = wp_normalize_path( untrailingslashit( ABSPATH ) );
				$base     = wp_normalize_path( $up['basedir'] );
				$rel_root = ( 0 === strpos( $base, $root . '/' ) ) ? substr( $base, strlen( $root ) + 1 ) : '';
				if ( '' !== $rel_root ) {
					$webp_dir = WP_CONTENT_DIR . '/webp-express/webp-images/doc-root/' . $rel_root;
					$webp_url = content_url( '/webp-express/webp-images/doc-root/' . $rel_root );
				}
			}

			$index = array(
				'v'        => self::SCHEMA,
				'built'    => $now,
				// off: часы клуба (для «сегодня» и «завтра»); cut: часы афиши (когда концерт пропадает из списка)
				'off'      => (int) $tz->getOffset( new DateTime( '@' . $now ) ),
				'cut'      => self::afisha_offset( $now ),
				'evBase'   => $home . '/event/',
				'imgBase'  => isset( $up['baseurl'] ) ? self::relative( $up['baseurl'] ) . '/' : '',
				'webpBase' => '',
				'genres'   => array(),
				'ev'       => array(),
				'pages'    => array(),
				'texts'    => 0,
			);
			$stats = array(
				'tables'  => false,
				'events'  => 0,
				'posts'   => 0,
				'pages'   => 0,
				'texts'   => 0,
				'vocab'   => 0,
				'thumbs'  => 0,
				'webp'    => 0,
				'tickets' => 0,
				'ends'    => 0,
				'ms'      => 0,
				'sample'  => array(),
			);

			$rows = array();
			if ( self::tables_ok() ) {
				$stats['tables'] = true;
				// окончание концерта (1.0.3): читается только если колонка есть, иначе запрос прежний
				$end_sql         = self::has_end() ? ', i.`end` AS end_ts' : '';
				$rows            = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT i.post_id AS post_id, i.start AS start{$end_sql}, p.post_title AS post_title, p.post_name AS post_name, e.cost AS cost
						 FROM {$wpdb->prefix}ai1ec_event_instances AS i
						 INNER JOIN {$wpdb->posts} AS p ON p.ID = i.post_id
						 LEFT JOIN {$wpdb->prefix}ai1ec_events AS e ON e.post_id = i.post_id
						 WHERE i.start > %d AND p.post_status = 'publish' AND p.post_password = ''
						 ORDER BY i.start ASC, i.post_id ASC
						 LIMIT %d",
						$now,
						self::MAX_EVENTS
					)
				);
				$rows            = is_array( $rows ) ? $rows : array();
			}

			$ids = array();
			foreach ( $rows as $row ) {
				$ids[ (int) $row->post_id ] = true;
			}
			$ids = array_keys( $ids );
			if ( $ids ) {
				update_meta_cache( 'post', $ids );
			}
			$stats['posts'] = count( $ids );

			// поля каждого события читаются один раз, даже если у него несколько дат
			$per_post  = array();
			$thumb_ids = array();
			foreach ( $ids as $id ) {
				$meta = get_post_meta( $id );
				$meta = is_array( $meta ) ? $meta : array();

				$stages = '';
				foreach ( self::stage_keys() as $code => $key ) {
					if ( ! empty( $meta[ $key ][0] ) ) {
						$stages .= $code;
					}
				}

				$genres = array();
				$gc     = self::unser( self::meta_first( $meta, 'genre_categories' ) );
				if ( is_array( $gc ) ) {
					foreach ( $gc as $one ) {
						if ( ! is_string( $one ) || '' === trim( $one ) ) {
							continue;
						}
						$parts = explode( '|', $one );
						$name  = self::plain( $parts[0] );
						$code  = isset( $parts[1] ) ? $parts[1] : $parts[0];
						$code  = trim( function_exists( 'mb_strtolower' ) ? mb_strtolower( self::plain( $code ), 'UTF-8' ) : strtolower( self::plain( $code ) ) );
						// «бесплатно» определяется по цене, а не по жанру
						if ( '' === $code || 'free' === $code || strlen( $code ) > 40 ) {
							continue;
						}
						$genres[ $code ] = ( '' !== $name ) ? $name : $code;
					}
				}

				// кнопка «Купить билет» показывается по тому же условию, что и в теме
				$tc    = self::meta_first( $meta, 'ticketscloud_link' );
				$tc_id = self::meta_first( $meta, 'ticketcloudid' );
				$btn   = 0;
				if ( '' !== $tc && '' !== $tc_id && '0' !== $tc_id && preg_match( '/^[A-Za-z0-9_-]{6,80}$/', $tc ) ) {
					$btn = ( '' !== self::meta_first( $meta, 'btn_text_register' ) && '0' !== self::meta_first( $meta, 'btn_text_register' ) ) ? 2 : 1;
				} else {
					$tc = '';
				}

				$words = array();
				if ( $content ) {
					$chunks = array();
					foreach ( self::text_keys() as $key ) {
						if ( empty( $meta[ $key ] ) || ! is_array( $meta[ $key ] ) ) {
							continue;
						}
						foreach ( $meta[ $key ] as $value ) {
							if ( is_string( $value ) && '' !== $value ) {
								$chunks[] = self::plain( self::ru( $value ) );
							}
						}
					}
					$artists = self::unser( self::meta_first( $meta, 'artist_list' ) );
					if ( is_array( $artists ) ) {
						foreach ( $artists as $artist ) {
							if ( is_string( $artist ) && ! is_numeric( $artist ) && 'Не выбран' !== trim( $artist ) ) {
								$chunks[] = self::plain( self::ru( $artist ) );
							}
						}
					}
					$words = self::words( implode( ' ', $chunks ) );
					if ( count( $words ) > self::MAX_DOC ) {
						$words = array_slice( $words, 0, self::MAX_DOC );
					}
				}

				$thumb_id = (int) self::meta_first( $meta, '_thumbnail_id' );
				if ( $thumb_id > 0 ) {
					$thumb_ids[ $thumb_id ] = true;
				}

				$per_post[ $id ] = array(
					'stages' => $stages,
					'genres' => $genres,
					'btn'    => $btn,
					'tc'     => $tc,
					'words'  => $words,
					'thumb'  => $thumb_id,
				);
			}

			if ( $thumb_ids ) {
				update_meta_cache( 'post', array_keys( $thumb_ids ) );
			}
			$thumbs = array();
			foreach ( array_keys( $thumb_ids ) as $thumb_id ) {
				$thumbs[ $thumb_id ] = self::thumb( $thumb_id, $up, $webp_dir );
			}

			// словарь анонсов: слово остаётся, если оно встречается не в каждом втором тексте
			$doc_of  = array(); // ключ текста => слова
			$post_tx = array(); // id события => ключ текста
			foreach ( $per_post as $id => $p ) {
				if ( ! $p['words'] ) {
					continue;
				}
				$sorted = $p['words'];
				sort( $sorted, SORT_STRING );
				$key = md5( implode( ' ', $sorted ) );
				if ( ! isset( $doc_of[ $key ] ) ) {
					$doc_of[ $key ] = $sorted;
				}
				$post_tx[ $id ] = $key;
			}
			$df = array();
			foreach ( $doc_of as $words ) {
				foreach ( $words as $w ) {
					$df[ $w ] = isset( $df[ $w ] ) ? $df[ $w ] + 1 : 1;
				}
			}
			$docs_n = count( $doc_of );
			$limit  = ( $docs_n >= 10 ) ? (int) floor( $docs_n * 0.4 ) : $docs_n;
			$vocab  = array();
			foreach ( $df as $w => $n ) {
				if ( $n <= $limit ) {
					$vocab[] = (string) $w;
				}
			}
			sort( $vocab, SORT_STRING );
			if ( count( $vocab ) > self::MAX_VOCAB ) {
				$vocab = array_slice( $vocab, 0, self::MAX_VOCAB );
			}
			$word_id = array_flip( $vocab );
			$text_id = array();
			$docs    = array();
			foreach ( $doc_of as $key => $words ) {
				$nums = array();
				foreach ( $words as $w ) {
					if ( isset( $word_id[ $w ] ) ) {
						$nums[] = $word_id[ $w ];
					}
				}
				if ( ! $nums ) {
					continue;
				}
				sort( $nums, SORT_NUMERIC );
				$prev   = 0;
				$deltas = array();
				foreach ( $nums as $n ) {
					$deltas[] = $n - $prev;
					$prev     = $n;
				}
				$text_id[ $key ] = count( $docs );
				$docs[]          = $deltas;
			}

			$genre_names = array();
			foreach ( $rows as $row ) {
				$id    = (int) $row->post_id;
				$start = (int) $row->start;
				$p     = isset( $per_post[ $id ] ) ? $per_post[ $id ] : null;
				$title = self::title( $row->post_title );
				if ( null === $p || $start <= 0 || '' === $title ) {
					continue;
				}
				$slug = (string) $row->post_name;
				if ( ! preg_match( '/^[A-Za-z0-9_%.-]+$/', $slug ) ) {
					continue;
				}
				$when = self::when( $start );

				foreach ( $p['genres'] as $code => $name ) {
					$genre_names[ $code ] = $name;
				}
				$thumb = ( $p['thumb'] > 0 && isset( $thumbs[ $p['thumb'] ] ) ) ? $thumbs[ $p['thumb'] ] : array( '', 0 );
				if ( '' !== $thumb[0] ) {
					$stats['thumbs']++;
					if ( $thumb[1] ) {
						$stats['webp']++;
					}
				}
				if ( $p['btn'] ) {
					$stats['tickets']++;
				}
				$tid = ( isset( $post_tx[ $id ], $text_id[ $post_tx[ $id ] ] ) ) ? $text_id[ $post_tx[ $id ] ] : -1;
				$dur = isset( $row->end_ts ) ? self::duration( $start, $row->end_ts ) : 0;
				if ( $dur > 0 ) {
					$stats['ends']++;
				}

				$index['ev'][] = array(
					$title,
					$when[0],
					$when[1],
					$p['stages'],
					self::price( $row->cost ),
					$slug,
					$thumb[0],
					$p['btn'],
					array_map( 'strval', array_keys( $p['genres'] ) ),
					$tid,
					$p['tc'],
					$thumb[1],
					$dur, // 12: длительность в минутах по календарю, 0 если окончание не указано
				);
			}
			$index['genres'] = $genre_names;
			$separate        = false;
			foreach ( $thumbs as $thumb ) {
				if ( 3 === $thumb[1] || 4 === $thumb[1] ) {
					$separate = true;
					break;
				}
			}
			if ( $separate && '' !== $webp_url ) {
				$index['webpBase'] = self::relative( $webp_url ) . '/';
			}

			// страницы верхнего уровня одним запросом: только опубликованные и без пароля
			$defs  = array();
			foreach ( self::page_defs() as $slug => $words ) {
				if ( is_string( $slug ) && '' !== $slug && is_array( $words ) ) {
					$defs[ $slug ] = $words;
				}
			}
			$found = array();
			if ( $defs ) {
				$pages = get_posts(
					array(
						'post_type'              => 'page',
						'post_status'            => 'publish',
						'post_name__in'          => array_keys( $defs ),
						'post_parent'            => 0,
						'has_password'           => false,
						'posts_per_page'         => 200,
						'orderby'                => 'ID',
						'order'                  => 'ASC',
						'no_found_rows'          => true,
						'update_post_meta_cache' => false,
						'update_post_term_cache' => false,
						'suppress_filters'       => true,
					)
				);
				foreach ( ( is_array( $pages ) ? $pages : array() ) as $page ) {
					if ( ! isset( $found[ $page->post_name ] ) ) {
						$found[ $page->post_name ] = $page;
					}
				}
			}
			foreach ( $defs as $slug => $words ) {
				$page = isset( $found[ $slug ] ) ? $found[ $slug ] : null;
				if ( ! $page || 'publish' !== $page->post_status || '' !== (string) $page->post_password ) {
					continue;
				}
				$title = self::plain( self::ru( $page->post_title ) );
				if ( '' === $title ) {
					continue;
				}
				$index['pages'][] = array(
					'title' => $title,
					'url'   => $home . '/' . $page->post_name . '/',
					'words' => array_values( array_filter( array_map( 'strval', $words ), 'strlen' ) ),
				);
			}

			$index['texts']  = count( $docs );
			$stats['events'] = count( $index['ev'] );
			$stats['pages']  = count( $index['pages'] );
			$stats['texts']  = count( $docs );
			$stats['vocab']  = count( $vocab );
			$stats['sample'] = array_slice( $index['ev'], 0, 5 );
			$stats['ms']     = (int) round( ( microtime( true ) - $t0 ) * 1000 );
			$index['stats']  = $stats;

			$texts = array(
				'v'     => self::SCHEMA,
				'built' => $now,
				'vocab' => implode( ' ', $vocab ),
				'docs'  => $docs,
			);

			return array(
				'index' => $index,
				'texts' => $texts,
			);
		}
	}

endif;
