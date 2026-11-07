<?php
/**
 * Синхронизация истории StreamVi в таблицу плагина.
 *
 * Каждые 10 минут (WP-Cron): новые трансляции, ссылки для ещё не разобранных (до 25 за раз),
 * одна страница старой истории, пока она не перебрана целиком. Кнопка в админке делает то же пачками.
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
			self::run( 25 );
		} catch ( \Throwable $e ) {
			kzv_log_exception( $e );
		}
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

	/** Свежие трансляции: первая страница списка, новые сверху. */
	private static function fetch_new( array &$report ) {
		$res = KZV_StreamVi::list_broadcasts( 0, 20, 'desc' );
		if ( is_wp_error( $res ) ) {
			$report['errors'][] = $res->get_error_message();
			return;
		}
		$report['new'] = KZV_Store::upsert_list( $res['items'] );
		KZV_Settings::patch_state( array( 'total' => (int) $res['total'] ) );
	}

	/** Старая история: по 100 штук от самых первых, пока не дойдём до конца. */
	private static function fetch_old( array &$report ) {
		$st = KZV_Settings::state();
		if ( ! empty( $st['backfill_done'] ) || $report['errors'] ) {
			return;
		}
		$res = KZV_StreamVi::list_broadcasts( (int) $st['backfill'], 100, 'asc' );
		if ( is_wp_error( $res ) ) {
			$report['errors'][] = $res->get_error_message();
			return;
		}
		$report['old'] = KZV_Store::upsert_list( $res['items'] );
		$offset        = (int) $st['backfill'] + count( $res['items'] );
		KZV_Settings::patch_state(
			array(
				'backfill'      => $offset,
				'backfill_done' => ( ! $res['items'] || $offset >= (int) $res['total'] ),
			)
		);
	}

	/** Ссылки на VK и YouTube и концерт на сайте для ещё не разобранных трансляций. */
	private static function fetch_details( $limit, array &$report ) {
		if ( $limit <= 0 || $report['errors'] ) {
			return;
		}
		$rows = KZV_Store::pending( $limit );
		if ( ! $rows ) {
			return;
		}
		$owner = (int) KZV_Settings::get( 'vk_owner' );

		foreach ( $rows as $r ) {
			$bid = (int) $r['broadcast_id'];
			$d   = KZV_StreamVi::broadcast( $bid );
			if ( is_wp_error( $d ) ) {
				KZV_Store::bump_tries( $bid );
				$report['errors'][] = 'трансляция ' . $bid . ': ' . $d->get_error_message();
				if ( in_array( $d->get_error_code(), array( 'kzv_rate', 'kzv_net' ), true ) || (int) $d->get_error_data() >= 500 ) {
					break; // StreamVi просит подождать или недоступен: продолжим в следующий раз
				}
				continue;
			}
			$links = KZV_StreamVi::pick( $d['links'], $owner );
			$match = self::concert_for( (int) $r['started_at'] );
			KZV_Store::save_detail(
				$bid,
				array(
					'vk_oid'  => $links['vk_oid'],
					'vk_id'   => $links['vk_id'],
					'yt_id'   => $links['yt_id'],
					'post_id' => $match ? $match['post_id'] : 0,
					'title'   => $match ? KZV_Util::clean_title( $match['title'] ) : '',
				)
			);
			$report['detailed']++;
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
				KZV_Store::set_match( (int) $r['broadcast_id'], $m['post_id'], KZV_Util::clean_title( $m['title'] ) );
				$done++;
			}
		}
		return $done;
	}
}
