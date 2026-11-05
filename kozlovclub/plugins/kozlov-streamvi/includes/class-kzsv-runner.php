<?php
/**
 * Проверка: какой концерт должен стоять в StreamVi сейчас, и отправка, если пора.
 * StreamVi вызывается только в момент переключения (2-4 раза в день), остальное время это один запрос к базе.
 */

defined( 'ABSPATH' ) || exit;

final class KZSV_Runner {

	const LOCK      = 'kzsv_lock';
	const STEP_WAIT = 180; // пока ждём окончания эфира, статус проверяется раз в 3 минуты

	/** @var int|null Подмена часов для тестов. */
	public static $clock = null;

	public static function now() {
		return ( null !== self::$clock ) ? (int) self::$clock : time();
	}

	/**
	 * Расписание концертов главной сцены с моментами переключения.
	 *
	 * @return array|WP_Error [ source, timeline ]
	 */
	public static function plan( $now = null ) {
		$now = $now ? (int) $now : self::now();
		$s   = KZSV_Settings::get();
		$src = KZSV_Source::get();
		if ( ! $src ) {
			return new WP_Error( 'kzsv_source', 'Не найден календарь концертов: нет ни All-in-One Event Calendar, ни Kozlov Core.' );
		}
		try {
			$events = $src->events( $now - 2 * DAY_IN_SECONDS, $now + 8 * DAY_IN_SECONDS );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'kzsv_source', 'Не удалось прочитать афишу: ' . $e->getMessage() );
		}
		return array(
			'source'   => $src,
			'timeline' => KZSV_Engine::timeline( $events, (int) $s['lead_min'] * 60, (int) $s['hold_min'] * 60 ),
		);
	}

	/** Одна проверка. Параллельные запуски (WP-Cron, визит, внешняя ссылка) не мешают друг другу. */
	public static function tick( $reason = 'cron' ) {
		if ( ! self::lock() ) {
			return 'busy';
		}
		$result = 'idle';
		try {
			$result = self::run( (string) $reason );
			KZSV_Notify::resolve( 'runtime' );
		} catch ( \Throwable $e ) {
			kzsv_log_exception( $e );
			KZSV_Notify::problem( 'runtime', 'Внутренняя ошибка плагина при проверке: ' . $e->getMessage() . '. Название трансляции могло не смениться.' );
			self::schedule( self::now() + 600 );
			$result = 'error';
		} finally {
			self::unlock();
		}
		return $result;
	}

	private static function run( $reason ) {
		$now   = self::now();
		$s     = KZSV_Settings::get();
		$state = KZSV_Settings::state();
		$upd   = array(
			'last_tick'   => $now,
			'last_reason' => $reason,
		);

		if ( ! $s['enabled'] ) {
			KZSV_Settings::patch_state( $upd );
			self::schedule( 0 );
			return 'disabled';
		}

		$plan = self::plan( $now );
		if ( is_wp_error( $plan ) ) {
			self::note( $upd, $state, 'source', $plan->get_error_message(), 'error', $plan->get_error_message() );
			KZSV_Notify::problem( 'source', $plan->get_error_message() );
			KZSV_Settings::patch_state( $upd );
			self::schedule( $now + 900 );
			return 'source';
		}
		KZSV_Notify::resolve( 'source' );
		$timeline = $plan['timeline'];

		list( $cur, $next ) = KZSV_Engine::desired( $timeline, $now );
		$due                = $next ? (int) $next['act'] : 0;

		// ручная отправка из админки держится до следующего планового переключения
		$ov = isset( $state['override'] ) && is_array( $state['override'] ) ? $state['override'] : null;
		if ( $ov ) {
			$found = ( $now < (int) $ov['until'] ) ? self::find( $timeline, (string) $ov['key'] ) : null;
			if ( $found ) {
				$cur = $found;
				$due = $due ? min( $due, (int) $ov['until'] ) : (int) $ov['until'];
			} else {
				$upd['override'] = null;
			}
		}

		if ( ! $cur ) {
			KZSV_Settings::patch_state( $upd );
			self::schedule( $due );
			return 'idle';
		}

		$dry    = (bool) $s['dry_run'];
		$slot   = $dry ? 'dry_last' : 'last';
		$last   = isset( $state[ $slot ] ) && is_array( $state[ $slot ] ) ? $state[ $slot ] : array();
		$hash   = KZSV_Engine::hash( $cur );
		$same   = isset( $last['key'] ) && $last['key'] === $cur['key'];
		$edited  = $same && ( ! isset( $last['hash'] ) || $last['hash'] !== $hash );
		$restore = false;
		if ( $same && ! ( $edited && $s['resend_on_edit'] ) ) {
			// раз в 15 минут (и по кнопке) сверяем, что в StreamVi действительно стоит наше название
			$drift = self::check_drift( $upd, $state, $last, $reason, $dry, $now );
			if ( $drift && $s['restore_drift'] ) {
				$restore = true;
			} else {
				KZSV_Settings::patch_state( $upd );
				self::schedule( $due );
				return $drift ? 'drift' : 'ok';
			}
		}

		$connected = KZSV_Settings::connected();
		if ( ! $dry && ! $connected ) {
			$msg = 'Пора поставить «' . $cur['title'] . '», но StreamVi не подключён.';
			self::note( $upd, $state, 'config', $cur['key'], 'warn', $msg );
			KZSV_Notify::problem( 'config', 'Пора переименовать трансляцию в «' . $cur['title'] . '», но плагин не подключён к StreamVi.' );
			KZSV_Settings::patch_state( $upd );
			self::schedule( $due ? min( $due, $now + 1800 ) : $now + 1800 );
			return 'config';
		}

		// защита эфира при смене концерта: переключаем, только когда StreamVi подтвердил, что эфира нет
		$why   = '';
		$state_live = '';
		if ( ! $same && $s['guard'] && $connected ) {
			$live = null;
			$st   = KZSV_Api::live_status();
			if ( is_wp_error( $st ) ) {
				self::note( $upd, $state, 'status', $st->get_error_code(), 'warn', 'Не удалось узнать, идёт ли эфир: ' . $st->get_error_message() . '.' );
			} else {
				$live       = $st;
				$state_live = (string) $st['state'];
			}
			$force                 = (int) $s['force_before_min'] * 60;
			list( $allowed, $why ) = KZSV_Engine::guard( $cur, $live, $now, $force, (bool) $s['force'] );
			if ( ! $allowed ) {
				$deadline = $s['force'] ? (int) $cur['start'] - $force : 0;
				$limit    = $deadline ? ', но не позже ' . self::fmt( $deadline ) : '';
				$text     = ( 'live' === $why )
					? 'Идёт эфир предыдущего концерта. «' . $cur['title'] . '» поставлю, как только эфир закончится' . $limit . '.'
					: 'Статус эфира неизвестен, название не трогаю. «' . $cur['title'] . '» поставлю, когда StreamVi подтвердит, что эфира нет' . $limit . '.';
				self::note( $upd, $state, 'wait', $cur['key'] . '|' . $why, 'info', $text );
				$upd['pending'] = array(
					'key'      => $cur['key'],
					'title'    => $cur['title'],
					'deadline' => $deadline,
					'why'      => $why,
				);
				KZSV_Settings::patch_state( $upd );
				self::schedule( $deadline ? min( $now + self::STEP_WAIT, max( $now + 60, $deadline ) ) : $now + self::STEP_WAIT );
				return 'waiting';
			}
		}

		$record = array(
			'key'   => $cur['key'],
			'hash'  => $hash,
			'title' => $cur['title'],
			'desc'  => $cur['desc'],
			'start' => (int) $cur['start'],
			'at'    => $now,
		);
		if ( $restore ) {
			$tail = ' (возвращено по расписанию после ручной правки в StreamVi)';
		} elseif ( $same ) {
			$tail = ' (поправили название или анонс)';
		} elseif ( 'deadline' === $why ) {
			$tail = ( 'live' === $state_live ) ? ' (эфир ещё шёл, но до начала концерта осталось ' . (int) $s['force_before_min'] . ' мин)' : ' (статус эфира неизвестен, до начала концерта осталось ' . (int) $s['force_before_min'] . ' мин)';
		} else {
			$tail = '';
		}

		if ( $dry ) {
			KZSV_Log::add( 'test', 'Тестовый режим: сейчас поставил бы «' . $cur['title'] . '»' . $tail . '. Описание: ' . KZSV_Text::clip( $cur['desc'], 160 ) );
			$upd['dry_last'] = $record;
			$upd['pending']  = null;
			KZSV_Settings::patch_state( $upd );
			self::schedule( $due );
			return 'dry';
		}

		$res = KZSV_Api::bulk_update( $cur['title'], $cur['desc'] );
		if ( is_wp_error( $res ) ) {
			$fails        = ( isset( $state['fails'] ) ? (int) $state['fails'] : 0 ) + 1;
			$upd['fails'] = $fails;
			KZSV_Log::add( 'error', 'Не удалось поставить «' . $cur['title'] . '»: ' . $res->get_error_message() . ' (попытка ' . $fails . ')' );
			if ( 'kzsv_auth' === $res->get_error_code() ) {
				KZSV_Notify::problem( 'auth', 'StreamVi не принимает доступ плагина (доступ отозван или устарел). Нажмите «Подключить StreamVi» на странице плагина.' );
			} elseif ( $fails >= 3 ) {
				KZSV_Notify::problem( 'fail', 'Трансляция не переименована в «' . $cur['title'] . '»: ' . $res->get_error_message() );
			}
			KZSV_Settings::patch_state( $upd );
			self::schedule( $now + min( 1800, 120 * $fails ) );
			return 'error';
		}

		$upd['fails']   = 0;
		$upd['pending'] = null;
		$upd['drift']   = null;
		KZSV_Log::add( 'ok', 'Поставил в StreamVi: «' . $cur['title'] . '»' . $tail . '.' );
		KZSV_Notify::resolve( 'fail' );
		KZSV_Notify::resolve( 'auth' );
		KZSV_Notify::resolve( 'config' );

		// сверка: что на самом деле стоит в StreamVi (так StreamVi хранит название; с этим сравниваем потом)
		if ( KZSV_Settings::has_scope( 'broadcast:read' ) ) {
			$tpl = KZSV_Api::template();
			if ( ! is_wp_error( $tpl ) && '' !== trim( $tpl['title'] ) ) {
				$record['seen'] = trim( $tpl['title'] );
				if ( trim( $tpl['title'] ) !== trim( $cur['title'] ) ) {
					KZSV_Log::add( 'warn', 'StreamVi после отправки показывает другое название: «' . $tpl['title'] . '».' );
				}
			}
		}
		$upd['last'] = $record;
		KZSV_Settings::patch_state( $upd );
		self::schedule( $due );
		return 'sent';
	}

	/**
	 * Отправить концерт вручную из админки. Держится до следующего планового переключения.
	 *
	 * @return true|WP_Error
	 */
	public static function send_now( $key ) {
		if ( KZSV_Settings::get( 'dry_run' ) ) {
			return new WP_Error( 'kzsv_dry', 'Включён тестовый режим: в StreamVi ничего не отправляется, в том числе вручную. Выключите его в «Правилах».' );
		}
		if ( ! KZSV_Settings::connected() ) {
			return new WP_Error( 'kzsv_auth', 'StreamVi не подключён.' );
		}
		if ( ! self::lock() ) {
			return new WP_Error( 'kzsv_busy', 'Как раз идёт проверка, повторите через минуту.' );
		}
		try {
			$now  = self::now();
			$plan = self::plan( $now );
			if ( is_wp_error( $plan ) ) {
				return $plan;
			}
			$e = self::find( $plan['timeline'], (string) $key );
			if ( ! $e ) {
				return new WP_Error( 'kzsv_key', 'Концерт не найден в расписании главной сцены.' );
			}
			$res = KZSV_Api::bulk_update( $e['title'], $e['desc'] );
			if ( is_wp_error( $res ) ) {
				KZSV_Log::add( 'error', 'Вручную не удалось поставить «' . $e['title'] . '»: ' . $res->get_error_message() );
				return $res;
			}
			list( $cur ) = KZSV_Engine::desired( $plan['timeline'], $now );
			$upd         = array(
				'fails'   => 0,
				'pending' => null,
				'drift'   => null,
				'last'    => array(
					'key'   => $e['key'],
					'hash'  => KZSV_Engine::hash( $e ),
					'title' => $e['title'],
					'desc'  => $e['desc'],
					'start' => (int) $e['start'],
					'at'    => $now,
				),
			);
			if ( KZSV_Settings::has_scope( 'broadcast:read' ) ) {
				$tpl = KZSV_Api::template();
				if ( ! is_wp_error( $tpl ) && '' !== trim( $tpl['title'] ) ) {
					$upd['last']['seen'] = trim( $tpl['title'] );
				}
			}
			if ( $cur && $cur['key'] === $e['key'] ) {
				$upd['override'] = null;
				$until           = 0;
			} else {
				$until           = self::next_act_after( $plan['timeline'], $now, $e['key'] );
				$upd['override'] = array(
					'key'   => $e['key'],
					'until' => $until,
				);
			}
			KZSV_Settings::patch_state( $upd );
			$user = wp_get_current_user();
			KZSV_Log::add( 'ok', 'Вручную поставлено «' . $e['title'] . '»' . ( $user && $user->exists() ? ' (' . $user->display_name . ')' : '' ) . ( $until ? '. Держится до ' . self::fmt( $until ) . '.' : '.' ) );
			KZSV_Notify::resolve( 'fail' );
			KZSV_Notify::resolve( 'auth' );
			KZSV_Notify::resolve( 'config' );
			return true;
		} finally {
			self::unlock();
		}
	}

	/**
	 * Совпадает ли название в StreamVi с тем, что поставил плагин. Только при страховочной проверке и по кнопке,
	 * не чаще раза в 15 минут: один лёгкий запрос.
	 *
	 * @return bool Есть расхождение.
	 */
	private static function check_drift( array &$upd, array $state, array $last, $reason, $dry, $now ) {
		if ( $dry || empty( $last['title'] ) || ! in_array( $reason, array( 'heartbeat', 'manual', 'external' ), true ) ) {
			return ! empty( $state['drift'] );
		}
		if ( ! KZSV_Settings::connected() || ! KZSV_Settings::has_scope( 'broadcast:read' ) ) {
			return false;
		}
		$tpl = KZSV_Api::template();
		if ( is_wp_error( $tpl ) || '' === trim( $tpl['title'] ) ) {
			return ! empty( $state['drift'] );
		}
		$expected = isset( $last['seen'] ) ? (string) $last['seen'] : (string) $last['title'];
		if ( trim( $tpl['title'] ) === trim( $expected ) || trim( $tpl['title'] ) === trim( (string) $last['title'] ) ) {
			$upd['drift'] = null;
			return false;
		}
		$upd['drift'] = array(
			'title' => trim( $tpl['title'] ),
			'at'    => $now,
		);
		$restore = KZSV_Settings::get( 'restore_drift' );
		self::note( $upd, $state, 'drift', md5( $tpl['title'] . '|' . $last['key'] ), 'warn', 'В StreamVi вручную стоит «' . trim( $tpl['title'] ) . '» вместо «' . $last['title'] . '». ' . ( $restore ? 'Возвращаю по расписанию.' : 'Оставляю до следующего переключения (так задано в правилах).' ) );
		return true;
	}

	public static function find( array $timeline, $key ) {
		foreach ( $timeline as $e ) {
			if ( $e['key'] === $key ) {
				return $e;
			}
		}
		return null;
	}

	private static function next_act_after( array $timeline, $now, $except ) {
		foreach ( $timeline as $e ) {
			if ( null !== $e['act'] && $e['act'] > $now && $e['key'] !== $except ) {
				return (int) $e['act'];
			}
		}
		return $now + 12 * HOUR_IN_SECONDS;
	}

	/** Следующая проверка ровно в нужный момент (плюс страховка раз в 15 минут). */
	public static function schedule( $ts ) {
		$ts = (int) $ts;
		update_option( KZSV_Settings::OPT_DUE, $ts, true );
		wp_clear_scheduled_hook( KZSV_Cron::EXACT );
		if ( $ts > 0 ) {
			wp_schedule_single_event( max( time() + 15, $ts ), KZSV_Cron::EXACT );
		}
	}

	public static function fmt( $ts ) {
		$tz    = KZSV_Settings::tz();
		$today = wp_date( 'Y-m-d', self::now(), $tz );
		$day   = wp_date( 'Y-m-d', (int) $ts, $tz );
		return ( $day === $today ) ? wp_date( 'H:i', (int) $ts, $tz ) : wp_date( 'd.m H:i', (int) $ts, $tz );
	}

	/** Запись в журнал один раз на одно и то же событие, чтобы не повторять её каждую проверку. */
	private static function note( array &$upd, array $state, $group, $id, $level, $msg ) {
		$notes = isset( $upd['notes'] ) ? $upd['notes'] : ( isset( $state['notes'] ) && is_array( $state['notes'] ) ? $state['notes'] : array() );
		if ( ! isset( $notes[ $group ] ) || $notes[ $group ] !== $id ) {
			KZSV_Log::add( $level, $msg );
			$notes[ $group ] = $id;
			$upd['notes']    = $notes;
		}
	}

	/* ------------------------------------------------------------ блокировка */

	/** @var string Метка владельца блокировки: снять её может только тот, кто поставил. */
	private static $lock_token = '';

	private static function lock() {
		global $wpdb;
		$now = time();
		$tok = $now . ':' . wp_generate_password( 12, false, false );
		$ins = "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')";
		if ( 1 === (int) $wpdb->query( $wpdb->prepare( $ins, self::LOCK, $tok ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			self::$lock_token = $tok;
			return true;
		}
		$old = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK ) );
		$at  = (int) strtok( $old, ':' );
		if ( '' !== $old && $at && $now - $at > 300 ) {
			// зависшая блокировка (процесс упал): снимаем ровно её, а не чужую новую
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::LOCK, $old ) );
			if ( 1 === (int) $wpdb->query( $wpdb->prepare( $ins, self::LOCK, $tok ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				self::$lock_token = $tok;
				return true;
			}
		}
		return false;
	}

	private static function unlock() {
		global $wpdb;
		if ( '' === self::$lock_token ) {
			return;
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::LOCK, self::$lock_token ) );
		self::$lock_token = '';
		wp_cache_delete( self::LOCK, 'options' );
	}
}
