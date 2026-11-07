<?php
/**
 * Синхронизация истории StreamVi в таблицу плагина.
 *
 * Каждые 10 минут (WP-Cron): новые трансляции, ссылки для ещё не разобранных (до 25 за раз, не дольше 20 с),
 * одна страница старой истории, пока она не перебрана целиком. Кнопка в админке делает то же пачками.
 * На сайте WP-Cron может быть отключён (DISABLE_WP_CRON): тогда работает внешний cron хостинга.
 */

defined( 'ABSPATH' ) || exit;

final class KZV_Sync {

	const HOOK     = 'kzv_sync';
	const SCHEDULE = 'kzv_10min';
	const LOCK     = 'kzv_sync_lock';

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval
		add_action( self::HOOK, array( __CLASS__, 'cron' ) );
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 120, self::SCHEDULE, self::HOOK );
		}
	}

	public static function schedules( $s ) {
		$s[ self::SCHEDULE ] = array(
			'interval' => 10 * MINUTE_IN_SECONDS,
			'display'  => 'Kozlov Video: каждые 10 минут',
		);
		return $s;
	}

	public static function activate() {
		try {
			KZV_Store::install();
		} catch ( \Throwable $e ) {
			kzv_log_exception( $e );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::HOOK );
		delete_transient( self::LOCK );
	}

	public static function cron() {
		try {
			$report = self::run( 25 );
			if ( $report['detailed'] > 0 ) {
				KZV_Front::purge(); // новые записи сразу видны посетителям, без ожидания кэша
			}
		} catch ( \Throwable $e ) {
			kzv_log_exception( $e );
		}
	}

	/**
	 * Синхронизация «по пути» для сайтов, где WP-Cron не срабатывает сам (DISABLE_WP_CRON без cron хостинга):
	 * не чаще раза в 10 минут, уже после того, как посетитель получил ответ (PHP-FPM).
	 */
	public static function maybe_run_later() {
		if ( ! function_exists( 'fastcgi_finish_request' ) || ! KZV_Store::ready() || ! KZV_StreamVi::ready() ) {
			return;
		}
		$st = KZV_Settings::state();
		if ( time() - (int) $st['last_run'] < 10 * MINUTE_IN_SECONDS || get_transient( self::LOCK ) || get_transient( 'kzv_sync_soon' ) ) {
			return;
		}
		set_transient( 'kzv_sync_soon', 1, 5 * MINUTE_IN_SECONDS );
		register_shutdown_function(
			function () {
				try {
					fastcgi_finish_request();
					KZV_Sync::cron();
				} catch ( \Throwable $e ) {
					kzv_log_exception( $e );
				}
			}
		);
	}

	/**
	 * Один проход. Не запускается параллельно.
	 *
	 * @param int $details Сколько трансляций разобрать (запрос на каждую).
	 * @return array Отчёт для админки.
	 */
	public static function run( $details ) {
		$report = array(
			'new'      => 0,
			'old'      => 0,
			'detailed' => 0,
			'errors'   => array(),
			'locked'   => false,
		);
		if ( ! KZV_StreamVi::ready() ) {
			$report['errors'][] = 'StreamVi не подключён: подключите его в «Трансляции» (плагин Kozlov StreamVi).';
			return $report;
		}
		if ( ! KZV_Store::ready() ) {
			KZV_Store::install();
			if ( ! KZV_Store::ready() ) {
				$report['errors'][] = 'таблица записей не создана, подробности в «Последние ошибки»';
				return $report;
			}
		}
		if ( get_transient( self::LOCK ) ) {
			$report['locked'] = true;
			return $report;
		}
		set_transient( self::LOCK, 1, 5 * MINUTE_IN_SECONDS );
		try {
			KZV_Settings::patch_state( array( 'last_run' => time() ) );
			self::fetch_new( $report );
			self::fetch_old( $report );
			self::fetch_details( (int) $details, $report );
			self::fill_genres( 200 );
			if ( ! $report['errors'] ) {
				KZV_Settings::patch_state( array( 'last_ok' => time() ) );
			}
		} finally {
			delete_transient( self::LOCK );
		}
		foreach ( $report['errors'] as $err ) {
			KZV_Settings::note_error( $err );
		}
		return $report;
	}

	/**
	 * Свежие трансляции. Листаем список от новых к старым, пока на страницах есть незнакомые.
	 * За проход до 8 страниц по 50. Если и этого мало (долгий простой), место остановки запоминается
	 * в state[gap] и следующий проход продолжает оттуда (с поправкой на появившиеся сверху новые).
	 */
	private static function fetch_new( array &$report ) {
		$st      = KZV_Settings::state();
		$gap     = (int) ( $st['gap'] ?? 0 );
		$offset  = 0;
		$top_new = 0;      // новых сверху до первой знакомой (на столько сдвинулся старый разрыв)
		$at_top  = true;
		for ( $page = 0; $page < 8; $page++ ) {
			$res = KZV_StreamVi::list_broadcasts( $offset, 50, 'desc' );
			if ( is_wp_error( $res ) ) {
				$report['errors'][] = $res->get_error_message();
				return;
			}
			$total = (int) $res['total'];
			KZV_Settings::patch_state( array( 'total' => $total ) );
			$items = $res['items'];
			if ( ! $items ) {
				KZV_Settings::patch_state( array( 'gap' => 0 ) );
				return;
			}
			$new            = KZV_Store::upsert_list( $items );
			$report['new'] += $new;
			$offset        += count( $items );
			if ( $at_top ) {
				$top_new += $new;
			}
			if ( $offset >= $total ) {
				KZV_Settings::patch_state( array( 'gap' => 0 ) );
				return;
			}
			if ( $new < count( $items ) ) {
				// дошли до знакомых: если раньше остался разрыв, переходим к нему
				$at_top = false;
				if ( $gap > 0 ) {
					$offset = $gap + $top_new;
					$gap    = 0;
					continue;
				}
				KZV_Settings::patch_state( array( 'gap' => 0 ) );
				return;
			}
		}
		KZV_Settings::patch_state( array( 'gap' => $offset ) ); // не успели: продолжим со следующим проходом
	}

	/**
	 * Старая история: по 100 штук от самых первых, пока не дойдём до конца.
	 * Раз в неделю история перебирается заново (страховка от пропусков).
	 */
	private static function fetch_old( array &$report ) {
		$st = KZV_Settings::state();
		if ( $report['errors'] ) {
			return;
		}
		if ( ! empty( $st['backfill_done'] ) ) {
			if ( time() - (int) ( $st['backfill_at'] ?? 0 ) < WEEK_IN_SECONDS ) {
				return;
			}
			KZV_Settings::patch_state(
				array(
					'backfill'      => 0,
					'backfill_done' => false,
				)
			);
			$st['backfill'] = 0;
		}
		$res = KZV_StreamVi::list_broadcasts( (int) $st['backfill'], 100, 'asc' );
		if ( is_wp_error( $res ) ) {
			$report['errors'][] = $res->get_error_message();
			return;
		}
		$report['old'] = KZV_Store::upsert_list( $res['items'] );
		$offset        = (int) $st['backfill'] + count( $res['items'] );
		$done          = ( ! $res['items'] || $offset >= (int) $res['total'] );
		KZV_Settings::patch_state(
			array(
				'backfill'      => $offset,
				'backfill_done' => $done,
				'backfill_at'   => $done ? time() : (int) ( $st['backfill_at'] ?? 0 ),
			)
		);
	}

	/** Сколько ждать до следующей попытки после $tries сбоев: 10 мин, 20, 40… не больше суток. */
	public static function backoff( $tries ) {
		return (int) min( DAY_IN_SECONDS, 10 * MINUTE_IN_SECONDS * pow( 2, max( 0, min( 10, (int) $tries ) ) ) );
	}

	/**
	 * Ссылки на VK и YouTube, концерт и жанры для трансляций из очереди.
	 * VK часто появляется не сразу: первые 3 дня после эфира без VK-ссылки проверяем каждый час.
	 * Работаем не дольше 20 секунд за проход.
	 */
	private static function fetch_details( $limit, array &$report ) {
		if ( $limit <= 0 || $report['errors'] ) {
			return;
		}
		$rows = KZV_Store::pending( $limit );
		if ( ! $rows ) {
			return;
		}
		$owner = (int) KZV_Settings::get( 'vk_owner' );
		$t0    = microtime( true );

		foreach ( $rows as $r ) {
			if ( microtime( true ) - $t0 > 20 ) {
				break;
			}
			$bid = (int) $r['broadcast_id'];
			$d   = KZV_StreamVi::broadcast( $bid );
			if ( is_wp_error( $d ) ) {
				KZV_Store::defer( $bid, self::backoff( (int) $r['tries'] ), true );
				$report['errors'][] = 'трансляция ' . $bid . ': ' . $d->get_error_message();
				if ( in_array( $d->get_error_code(), array( 'kzv_rate', 'kzv_net' ), true ) || (int) $d->get_error_data() >= 500 ) {
					break; // StreamVi просит подождать или недоступен: продолжим в следующий раз
				}
				continue;
			}
			$links = KZV_StreamVi::pick( $d['links'], $owner );
			$match = self::concert_for( (int) $r['started_at'] );
			$final = $links['vk_id'] > 0 || ( (int) $r['finished_at'] > 0 && time() - (int) $r['finished_at'] > 3 * DAY_IN_SECONDS );
			KZV_Store::save_detail(
				$bid,
				array(
					'vk_oid'   => $links['vk_oid'],
					'vk_id'    => $links['vk_id'],
					'yt_id'    => $links['yt_id'],
					'post_id'  => $match ? $match['post_id'] : 0,
					'title'    => $match ? KZV_Util::clean_title( $match['title'] ) : '',
					'sv_title' => (string) ( $d['name'] ?? '' ),
					'genres'   => $match ? KZV_Concerts::genres( $match['post_id'] ) : '',
				),
				$final
			);
			if ( ! $final ) {
				KZV_Store::defer( $bid, HOUR_IN_SECONDS, false );
			}
			$report['detailed']++;
		}
	}

	/** Жанры для записей, привязанных к концерту до версии 1.1 (читаются из афиши, без запросов к StreamVi). */
	private static function fill_genres( $limit ) {
		foreach ( KZV_Store::missing_genres( $limit ) as $r ) {
			KZV_Store::set_genres( (int) $r['broadcast_id'], KZV_Concerts::genres( (int) $r['post_id'] ) );
		}
	}

	/** Концерт главной сцены, к которому относится эфир с этим началом. */
	private static function concert_for( $started ) {
		if ( $started <= 0 ) {
			return null;
		}
		$events = KZV_Concerts::events( $started - KZV_Concerts::AFTER - HOUR_IN_SECONDS, $started + KZV_Concerts::BEFORE + HOUR_IN_SECONDS );
		return KZV_Concerts::match( $started, $events );
	}

	/** Заново привязать к афише записи без концерта (кнопка в админке). */
	public static function rematch( $limit ) {
		$rows = KZV_Store::unmatched( $limit );
		$done = 0;
		foreach ( $rows as $r ) {
			$m = self::concert_for( (int) $r['started_at'] );
			if ( $m ) {
				KZV_Store::set_match( (int) $r['broadcast_id'], $m['post_id'], KZV_Util::clean_title( $m['title'] ), KZV_Concerts::genres( $m['post_id'] ) );
				$done++;
			}
		}
		return $done;
	}
}
