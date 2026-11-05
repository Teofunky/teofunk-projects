<?php
/**
 * Когда запускается проверка.
 *  1. Точно в момент переключения: одиночное событие WP-Cron на это время.
 *  2. Сразу после правки концерта (через 20 секунд).
 *  3. Страховка раз в 15 минут.
 *  4. Если WP-Cron на сайте не сработал (кэш страниц, отключён, защита хостинга): первый визит после срока,
 *     уже после того как посетитель получил страницу.
 *  5. Внешняя ссылка с ключом для cron хостинга (по желанию).
 */

defined( 'ABSPATH' ) || exit;

final class KZSV_Cron {

	const HEARTBEAT = 'kzsv_heartbeat';
	const EXACT     = 'kzsv_exact';
	const SOON      = 'kzsv_soon';
	const NS        = 'kozlov-streamvi/v1';

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		add_action( self::HEARTBEAT, array( __CLASS__, 'run_heartbeat' ) );
		add_action( self::EXACT, array( __CLASS__, 'run_exact' ) );
		add_action( self::SOON, array( __CLASS__, 'run_soon' ) );
		add_action( 'init', array( __CLASS__, 'ensure' ), 30 );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'save_post', array( __CLASS__, 'on_save' ), 99, 2 );
		add_action( 'shutdown', array( __CLASS__, 'catch_up' ), 1000 );
	}

	public static function schedules( $list ) {
		$list['kzsv_15min'] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => 'Раз в 15 минут (Kozlov StreamVi)',
		);
		return $list;
	}

	public static function run_heartbeat() {
		KZSV_Runner::tick( 'heartbeat' );
	}

	public static function run_exact() {
		KZSV_Runner::tick( 'exact' );
	}

	public static function run_soon() {
		KZSV_Runner::tick( 'edit' );
	}

	public static function activate() {
		try {
			add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
			self::ensure();
			KZSV_Settings::tick_key();
			self::soon();
			KZSV_Log::add( 'info', 'Плагин включён.' );
		} catch ( \Throwable $e ) {
			kzsv_log_exception( $e );
		}
	}

	public static function deactivate() {
		try {
			foreach ( array( self::HEARTBEAT, self::EXACT, self::SOON ) as $hook ) {
				wp_clear_scheduled_hook( $hook );
			}
			delete_option( KZSV_Settings::OPT_DUE );
			KZSV_Log::add( 'info', 'Плагин выключен: переименование остановлено.' );
		} catch ( \Throwable $e ) {
			kzsv_log_exception( $e );
		}
	}

	public static function ensure() {
		try {
			if ( ! wp_next_scheduled( self::HEARTBEAT ) ) {
				wp_schedule_event( time() + 60, 'kzsv_15min', self::HEARTBEAT );
			}
		} catch ( \Throwable $e ) {
			kzsv_log_exception( $e );
		}
	}

	/** Проверить через 20 секунд (после правки концерта, после смены настроек). */
	public static function soon() {
		if ( ! wp_next_scheduled( self::SOON ) ) {
			wp_schedule_single_event( time() + 20, self::SOON );
		}
		$due = (int) get_option( KZSV_Settings::OPT_DUE );
		if ( ! $due || $due > time() + 20 ) {
			update_option( KZSV_Settings::OPT_DUE, time() + 20, true );
		}
	}

	public static function on_save( $post_id, $post ) {
		try {
			if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! is_object( $post ) ) {
				return;
			}
			if ( in_array( $post->post_type, KZSV_Source::all_post_types(), true ) ) {
				self::soon();
			}
		} catch ( \Throwable $e ) {
			kzsv_log_exception( $e );
		}
	}

	/**
	 * Страховка от «спящего» WP-Cron: если срок прошёл больше полутора минут назад, проверку делает первый визит,
	 * уже отдав страницу посетителю (PHP-FPM / LiteSpeed). Обычных визитов это не касается: одно чтение опции.
	 */
	public static function catch_up() {
		try {
			if ( wp_doing_cron() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'WP_INSTALLING' ) && WP_INSTALLING ) ) {
				return;
			}
			$due = (int) get_option( KZSV_Settings::OPT_DUE );
			if ( ! $due || time() < $due + 90 ) {
				return;
			}
			$can_detach = function_exists( 'fastcgi_finish_request' ) || function_exists( 'litespeed_finish_request' );
			if ( ! $can_detach && ! is_admin() ) {
				return; // не заставляем посетителя ждать ответа StreamVi
			}
			if ( get_transient( 'kzsv_catchup' ) ) {
				return;
			}
			set_transient( 'kzsv_catchup', 1, 2 * MINUTE_IN_SECONDS );
			if ( function_exists( 'fastcgi_finish_request' ) ) {
				fastcgi_finish_request();
			} elseif ( function_exists( 'litespeed_finish_request' ) ) {
				litespeed_finish_request();
			}
			KZSV_Runner::tick( 'visit' );
		} catch ( \Throwable $e ) {
			kzsv_log_exception( $e );
		}
	}

	public static function routes() {
		register_rest_route(
			self::NS,
			'/tick',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_tick' ),
				'permission_callback' => array( __CLASS__, 'rest_key_ok' ),
			)
		);
		register_rest_route(
			self::NS,
			'/oauth',
			array(
				'methods'             => 'GET',
				'callback'            => array( 'KZSV_Admin', 'oauth_callback' ),
				'permission_callback' => '__return_true', // проверка по одноразовому state внутри
			)
		);
	}

	/** Ключ принимается только из заголовка X-Kzsv-Key или тела POST: в адресе (и журналах сервера) его нет. */
	public static function rest_key_ok( $request ) {
		$want = (string) KZSV_Settings::get( 'tick_key' );
		$got  = '';
		if ( is_object( $request ) ) {
			$got = (string) $request->get_header( 'x_kzsv_key' );
			if ( '' === $got && method_exists( $request, 'get_body_params' ) ) {
				$body = $request->get_body_params();
				$got  = isset( $body['key'] ) ? (string) $body['key'] : '';
			}
		}
		return strlen( $want ) >= 24 && '' !== $got && hash_equals( $want, $got );
	}

	public static function rest_tick( $request ) {
		if ( get_transient( 'kzsv_ext' ) ) {
			$result = 'throttled';
		} else {
			set_transient( 'kzsv_ext', 1, 50 );
			$result = KZSV_Runner::tick( 'external' );
		}
		$res    = new WP_REST_Response(
			array(
				'ok'     => 'error' !== $result,
				'result' => $result,
			)
		);
		$res->header( 'Cache-Control', 'no-store' );
		return $res;
	}
}
