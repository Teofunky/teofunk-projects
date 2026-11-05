<?php
/**
 * Страница «Трансляции» в админке: что стоит сейчас, расписание переключений, подключение StreamVi, правила, журнал.
 */

defined( 'ABSPATH' ) || exit;

final class KZSV_Admin {

	const CAP = 'manage_options';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( KZSV_FILE ), array( __CLASS__, 'links' ) );
		foreach ( array( 'settings', 'connect', 'disconnect', 'check', 'run', 'send', 'toggle', 'clear_log', 'new_key' ) as $action ) {
			add_action( 'admin_post_kzsv_' . $action, array( __CLASS__, 'do_' . $action ) );
		}
	}

	public static function menu() {
		add_menu_page( 'Трансляции StreamVi', 'Трансляции', self::CAP, KZSV_Settings::PAGE, array( __CLASS__, 'render' ), 'dashicons-video-alt2', 58 );
	}

	public static function links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( KZSV_Settings::admin_url() ) . '">Открыть</a>' );
		return $links;
	}

	public static function assets( $hook ) {
		if ( 'toplevel_page_' . KZSV_Settings::PAGE !== $hook ) {
			return;
		}
		wp_enqueue_style( 'kzsv-admin', KZSV_URL . 'assets/admin.css', array(), KZSV_VERSION );
		wp_enqueue_script( 'kzsv-admin', KZSV_URL . 'assets/admin.js', array(), KZSV_VERSION, true );
	}

	/** Проблема с переименованием видна на всех страницах админки, пока её не исправят. */
	public static function notices() {
		try {
			if ( ! current_user_can( self::CAP ) ) {
				return;
			}
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( $screen && 'toplevel_page_' . KZSV_Settings::PAGE === $screen->id ) {
				return;
			}
			$problems = KZSV_Notify::active();
			if ( ! $problems || ! KZSV_Settings::get( 'enabled' ) ) {
				return;
			}
			$first = reset( $problems );
			echo '<div class="notice notice-error"><p><strong>Трансляция StreamVi:</strong> ' . esc_html( $first['text'] ) . ' <a href="' . esc_url( KZSV_Settings::admin_url() ) . '">Открыть</a></p></div>';
		} catch ( \Throwable $e ) {
			kzsv_log_exception( $e );
		}
	}

	/* ------------------------------------------------------------ действия */

	private static function guard_action( $action ) {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Недостаточно прав.' );
		}
		check_admin_referer( 'kzsv_' . $action );
	}

	private static function flash( $type, $text, $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		set_transient( 'kzsv_flash_' . $user_id, array( (string) $type, (string) $text ), 120 );
	}

	private static function back( $anchor = '' ) {
		wp_safe_redirect( KZSV_Settings::admin_url() . ( $anchor ? '#' . $anchor : '' ) );
		exit;
	}

	private static function post( $key, $default = '' ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		return isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : $default;
	}

	public static function do_settings() {
		self::guard_action( 'settings' );
		$fields = array( 'lead_min', 'hold_min', 'force_before_min', 'title_max', 'desc_max', 'par_min', 'desc_fallback', 'desc_suffix', 'exclude', 'notify_email', 'source' );
		$values = array();
		foreach ( $fields as $f ) {
			$values[ $f ] = self::post( $f, KZSV_Settings::get( $f ) );
		}
		foreach ( array( 'guard', 'force', 'restore_drift', 'resend_on_edit', 'notify', 'dry_run' ) as $f ) {
			$values[ $f ] = self::post( $f ) ? 1 : 0;
		}
		$before = KZSV_Settings::get( 'dry_run' );
		KZSV_Settings::update( $values );
		KZSV_Log::add( 'info', 'Правила сохранены: за ' . (int) KZSV_Settings::get( 'lead_min' ) . ' мин до начала, концерт закончен через ' . (int) KZSV_Settings::get( 'hold_min' ) . ' мин после начала' . ( KZSV_Settings::get( 'dry_run' ) ? ', тестовый режим' : '' ) . '.' );
		if ( $before && ! KZSV_Settings::get( 'dry_run' ) ) {
			KZSV_Log::add( 'info', 'Тестовый режим выключен: дальше названия уходят в StreamVi.' );
		}
		KZSV_Cron::soon();
		self::flash( 'success', 'Правила сохранены.' );
		self::back( 'rules' );
	}

	public static function do_connect() {
		self::guard_action( 'connect' );
		$client_id = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) self::post( 'client_id' ) );
		$secret    = trim( (string) self::post( 'client_secret' ) );
		if ( '' === $client_id ) {
			self::flash( 'error', 'Вставьте «ID клиента» из настроек приложения в StreamVi.' );
			self::back( 'connect' );
		}
		KZSV_Settings::update( array( 'client_id' => $client_id ) );
		if ( '' !== $secret ) {
			KZSV_Settings::save_secrets( array( 'client_secret' => $secret ) );
		}
		list( $verifier, $challenge ) = KZSV_Api::pkce();
		$state                         = wp_generate_password( 40, false, false );
		set_transient(
			'kzsv_oauth_' . md5( $state ),
			array(
				'verifier' => $verifier,
				'user'     => get_current_user_id(),
			),
			20 * MINUTE_IN_SECONDS
		);
		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		wp_redirect( KZSV_Api::authorize_url( $client_id, KZSV_Settings::redirect_uri(), $state, $challenge ) );
		exit;
	}

	/** StreamVi возвращает сюда после «Разрешить». */
	public static function oauth_callback( $request ) {
		$back  = KZSV_Settings::admin_url();
		$state = (string) $request->get_param( 'state' );
		$data  = ( '' !== $state ) ? get_transient( 'kzsv_oauth_' . md5( $state ) ) : false;
		if ( $data ) {
			delete_transient( 'kzsv_oauth_' . md5( $state ) );
		}
		$user = is_array( $data ) && ! empty( $data['user'] ) ? (int) $data['user'] : 0;
		$who  = 0;
		if ( defined( 'LOGGED_IN_COOKIE' ) && ! empty( $_COOKIE[ LOGGED_IN_COOKIE ] ) && function_exists( 'wp_validate_auth_cookie' ) ) {
			$who = (int) wp_validate_auth_cookie( sanitize_text_field( wp_unslash( $_COOKIE[ LOGGED_IN_COOKIE ] ) ), 'logged_in' );
		}

		if ( ! is_array( $data ) ) {
			KZSV_Log::add( 'warn', 'Возврат из StreamVi без действующей ссылки подключения (устарела или открыта повторно).' );
			return self::redirect_response( add_query_arg( 'kzsv_oauth', 'stale', $back ) );
		}
		if ( ! $who || $who !== $user || ! user_can( $who, self::CAP ) ) {
			KZSV_Log::add( 'warn', 'Возврат из StreamVi не в сессии того администратора, который начал подключение. Отклонено.' );
			return self::redirect_response( add_query_arg( 'kzsv_oauth', 'session', $back ) );
		}
		$error = (string) $request->get_param( 'error' );
		$code  = (string) $request->get_param( 'code' );
		if ( '' !== $error || '' === $code ) {
			self::flash( 'error', 'StreamVi не выдал доступ' . ( '' !== $error ? ' (' . sanitize_text_field( $error ) . ')' : '' ) . '.', $user );
			return self::redirect_response( $back );
		}
		$sec = KZSV_Settings::secrets();
		$res = KZSV_Api::exchange_code( $code, (string) KZSV_Settings::get( 'client_id' ), $sec['client_secret'], KZSV_Settings::redirect_uri(), (string) $data['verifier'] );
		if ( is_wp_error( $res ) ) {
			KZSV_Log::add( 'error', 'Подключение StreamVi не удалось: ' . $res->get_error_message() );
			self::flash( 'error', 'Подключение не удалось: ' . $res->get_error_message(), $user );
			return self::redirect_response( $back );
		}
		KZSV_Settings::save_secrets(
			array(
				'access_token'        => (string) $res['access_token'],
				'project_id'          => (int) $res['project_id'],
				'project_external_id' => isset( $res['project_external_id'] ) ? (string) $res['project_external_id'] : '',
				'scope'               => isset( $res['scope'] ) ? (string) $res['scope'] : '',
				'connected_at'        => time(),
				'expires_at'          => 0,
			)
		);
		$ctx = KZSV_Api::token_context();
		if ( ! is_wp_error( $ctx ) && ! empty( $ctx['expires_at'] ) ) {
			KZSV_Settings::save_secrets( array( 'expires_at' => (int) floor( (float) $ctx['expires_at'] / 1000 ) ) );
		}
		if ( ! is_wp_error( $ctx ) && ! empty( $ctx['scopes'] ) && is_array( $ctx['scopes'] ) ) {
			KZSV_Settings::save_secrets( array( 'scope' => implode( ' ', array_map( 'strval', $ctx['scopes'] ) ) ) );
		}
		KZSV_Notify::resolve( 'auth' );
		KZSV_Notify::resolve( 'config' );
		$ext = isset( $res['project_external_id'] ) ? (string) $res['project_external_id'] : '';
		KZSV_Log::add( 'ok', 'StreamVi подключён: проект ' . ( '' !== $ext ? $ext : '#' . (int) $res['project_id'] ) . ' (обмен кода: ' . ( isset( $res['_variant'] ) ? $res['_variant'] : '' ) . ').' );
		$st = KZSV_Settings::state();
		unset( $st['last'] ); // после подключения сразу ставим актуальный концерт
		KZSV_Settings::save_state( $st );
		KZSV_Cron::soon();
		self::flash( 'success', 'StreamVi подключён. Актуальный концерт будет поставлен в течение минуты.', $user );
		delete_transient( 'kzsv_peek' );
		return self::redirect_response( $back );
	}

	private static function redirect_response( $url ) {
		$r = new WP_REST_Response( null, 302 );
		$r->header( 'Location', $url );
		$r->header( 'Cache-Control', 'no-store' );
		return $r;
	}

	public static function do_disconnect() {
		self::guard_action( 'disconnect' );
		KZSV_Settings::save_secrets(
			array(
				'access_token'        => '',
				'project_id'          => 0,
				'project_external_id' => '',
				'scope'               => '',
				'connected_at'        => 0,
				'expires_at'          => 0,
			)
		);
		delete_transient( 'kzsv_peek' );
		KZSV_Log::add( 'info', 'StreamVi отключён вручную.' );
		self::flash( 'success', 'StreamVi отключён. Ключи приложения сохранены, подключить снова можно одной кнопкой.' );
		self::back( 'connect' );
	}

	public static function do_check() {
		self::guard_action( 'check' );
		delete_transient( 'kzsv_peek' );
		$ctx = KZSV_Api::token_context();
		if ( is_wp_error( $ctx ) ) {
			self::flash( 'error', 'Проверка не прошла: ' . $ctx->get_error_message() );
			if ( 'kzsv_auth' === $ctx->get_error_code() ) {
				KZSV_Notify::problem( 'auth', 'StreamVi не принимает доступ плагина. Нажмите «Подключить StreamVi» заново.' );
			}
		} else {
			$scopes = isset( $ctx['scopes'] ) && is_array( $ctx['scopes'] ) ? implode( ', ', $ctx['scopes'] ) : '';
			self::flash( 'success', 'Доступ к StreamVi работает. Права: ' . ( '' !== $scopes ? $scopes : 'не указаны' ) . '.' );
			KZSV_Notify::resolve( 'auth' );
		}
		self::back( 'connect' );
	}

	public static function do_run() {
		self::guard_action( 'run' );
		$labels = array(
			'sent'     => 'Название отправлено в StreamVi.',
			'ok'       => 'Всё по расписанию: в StreamVi уже стоит нужный концерт.',
			'idle'     => 'Сейчас переключать нечего.',
			'waiting'  => 'Идёт эфир предыдущего концерта: переключу после его окончания.',
			'dry'      => 'Тестовый режим: результат в журнале.',
			'disabled' => 'Переименование на паузе.',
			'config'   => 'StreamVi не подключён.',
			'source'   => 'Не удалось прочитать афишу (подробности в журнале).',
			'error'    => 'StreamVi вернул ошибку (подробности в журнале).',
			'busy'     => 'Проверка уже идёт, обновите страницу через минуту.',
		);
		$result = KZSV_Runner::tick( 'manual' );
		$ok     = in_array( $result, array( 'sent', 'ok', 'idle', 'waiting', 'dry', 'disabled' ), true );
		delete_transient( 'kzsv_peek' );
		self::flash( $ok ? 'success' : 'error', isset( $labels[ $result ] ) ? $labels[ $result ] : $result );
		self::back();
	}

	public static function do_send() {
		self::guard_action( 'send' );
		$res = KZSV_Runner::send_now( sanitize_text_field( (string) self::post( 'key' ) ) );
		delete_transient( 'kzsv_peek' );
		if ( is_wp_error( $res ) ) {
			self::flash( 'error', $res->get_error_message() );
		} else {
			self::flash( 'success', 'Поставлено в StreamVi.' );
		}
		self::back( 'plan' );
	}

	public static function do_toggle() {
		self::guard_action( 'toggle' );
		$on = KZSV_Settings::get( 'enabled' ) ? 0 : 1;
		KZSV_Settings::update( array( 'enabled' => $on ) );
		KZSV_Log::add( 'info', $on ? 'Переименование включено.' : 'Переименование поставлено на паузу.' );
		if ( $on ) {
			KZSV_Cron::soon();
		} else {
			KZSV_Runner::schedule( 0 );
		}
		self::flash( 'success', $on ? 'Переименование включено.' : 'Переименование на паузе. Название в StreamVi меняйте вручную.' );
		self::back();
	}

	public static function do_clear_log() {
		self::guard_action( 'clear_log' );
		KZSV_Log::clear();
		self::back( 'log' );
	}

	public static function do_new_key() {
		self::guard_action( 'new_key' );
		KZSV_Settings::update( array( 'tick_key' => wp_generate_password( 32, false, false ) ) );
		KZSV_Log::add( 'info', 'Ключ внешней ссылки проверки заменён.' );
		self::flash( 'success', 'Новый ключ создан. Старая ссылка больше не работает.' );
		self::back( 'reliability' );
	}

	/* ------------------------------------------------------------ страница */

	private static function form( $action, $button, $class = 'button', $fields = array(), $confirm = '' ) {
		$out  = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="kzsv-inline"' . ( $confirm ? ' onsubmit="return confirm(\'' . esc_js( $confirm ) . '\')"' : '' ) . '>';
		$out .= '<input type="hidden" name="action" value="kzsv_' . esc_attr( $action ) . '">';
		$out .= wp_nonce_field( 'kzsv_' . $action, '_wpnonce', true, false );
		foreach ( $fields as $k => $v ) {
			$out .= '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
		}
		$out .= '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $button ) . '</button></form>';
		return $out;
	}

	/** Что сейчас стоит в StreamVi и идёт ли эфир (кэш на минуту, чтобы страница открывалась быстро). */
	private static function peek() {
		$cached = get_transient( 'kzsv_peek' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$out = array(
			'tpl'  => null,
			'live' => null,
			'err'  => '',
		);
		if ( KZSV_Settings::connected() ) {
			$tpl = KZSV_Settings::has_scope( 'broadcast:read' ) ? KZSV_Api::template() : array( 'title' => '', 'desc' => '' );
			if ( is_wp_error( $tpl ) ) {
				$out['err'] = $tpl->get_error_message();
			} else {
				$out['tpl'] = '' !== $tpl['title'] ? $tpl : null;
				$live       = KZSV_Api::live_status();
				if ( ! is_wp_error( $live ) ) {
					$out['live'] = $live;
				}
			}
		}
		set_transient( 'kzsv_peek', $out, MINUTE_IN_SECONDS );
		return $out;
	}

	private static function when( $ts ) {
		return KZSV_Runner::fmt( $ts );
	}

	private static function day_label( $ts ) {
		$tz    = KZSV_Settings::tz();
		$d     = wp_date( 'Y-m-d', (int) $ts, $tz );
		$today = wp_date( 'Y-m-d', KZSV_Runner::now(), $tz );
		$tom   = wp_date( 'Y-m-d', KZSV_Runner::now() + DAY_IN_SECONDS, $tz );
		$days  = array( 'вс', 'пн', 'вт', 'ср', 'чт', 'пт', 'сб' );
		$name  = ( $d === $today ) ? 'Сегодня' : ( ( $d === $tom ) ? 'Завтра' : wp_date( 'd.m', (int) $ts, $tz ) . ', ' . $days[ (int) wp_date( 'w', (int) $ts, $tz ) ] );
		return $name . ' ' . wp_date( 'H:i', (int) $ts, $tz );
	}

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		try {
			self::render_page();
		} catch ( \Throwable $e ) {
			kzsv_log_exception( $e );
			echo '<div class="wrap"><h1>Трансляции StreamVi</h1><div class="notice notice-error"><p>Страница не открылась: ' . esc_html( $e->getMessage() ) . '. Подробности в журнале PHP.</p></div></div>';
		}
	}

	private static function render_page() {
		$s     = KZSV_Settings::get();
		$sec   = KZSV_Settings::secrets();
		$state = KZSV_Settings::state();
		$now   = KZSV_Runner::now();
		$plan  = KZSV_Runner::plan( $now );
		$conn  = KZSV_Settings::connected();
		$peek  = $conn ? self::peek() : array( 'tpl' => null, 'live' => null, 'err' => '' );

		$cur  = null;
		$next = null;
		if ( ! is_wp_error( $plan ) ) {
			list( $cur, $next ) = KZSV_Engine::desired( $plan['timeline'], $now );
		}
		$last    = isset( $state['last'] ) && is_array( $state['last'] ) ? $state['last'] : array();
		$pending = isset( $state['pending'] ) && is_array( $state['pending'] ) ? $state['pending'] : array();
		$ov      = isset( $state['override'] ) && is_array( $state['override'] ) && (int) $state['override']['until'] > $now ? $state['override'] : array();

		$flash = get_transient( 'kzsv_flash_' . get_current_user_id() );
		if ( $flash ) {
			delete_transient( 'kzsv_flash_' . get_current_user_id() );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['kzsv_oauth'] ) && 'stale' === $_GET['kzsv_oauth'] ) {
			$flash = array( 'error', 'Ссылка подключения устарела. Нажмите «Подключить StreamVi» ещё раз.' );
		} elseif ( isset( $_GET['kzsv_oauth'] ) && 'session' === $_GET['kzsv_oauth'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$flash = array( 'error', 'Подключение отклонено: вернитесь из StreamVi в том же браузере, где вы вошли в админку сайта, и нажмите «Подключить StreamVi» ещё раз.' );
		}
		$drift = isset( $state['drift'] ) && is_array( $state['drift'] ) ? $state['drift'] : array();

		if ( ! $s['enabled'] ) {
			$status = array( 'off', 'На паузе' );
		} elseif ( $s['dry_run'] ) {
			$status = array( 'test', 'Тестовый режим' );
		} elseif ( ! $conn ) {
			$status = array( 'warn', 'Не подключено к StreamVi' );
		} elseif ( KZSV_Notify::active() ) {
			$status = array( 'warn', 'Есть проблема' );
		} elseif ( $drift ) {
			$status = array( 'warn', 'Расхождение со StreamVi' );
		} elseif ( $pending ) {
			$status = array( 'warn', 'Ждёт окончания эфира' );
		} else {
			$status = array( 'on', 'Работает' );
		}
		?>
		<div class="wrap kzsv-wrap">
			<h1 class="kzsv-title">Трансляции StreamVi <span class="kzsv-pill kzsv-pill-<?php echo esc_attr( $status[0] ); ?>"><?php echo esc_html( $status[1] ); ?></span></h1>
			<p class="kzsv-lead">Название и описание трансляции меняются сами под концерты главной сцены: за <?php echo (int) $s['lead_min']; ?> мин до начала, а после окончания концерта (через <?php echo (int) round( $s['hold_min'] / 60, 1 ); ?> ч после начала) сразу ставится следующий.<?php echo $s['guard'] ? ' Пока идёт эфир (или StreamVi не подтвердил, что его нет), название не меняется' . ( $s['force'] ? ', в крайнем случае меняется за ' . (int) $s['force_before_min'] . ' мин до начала следующего концерта.' : '.' ) : ' Защита эфира выключена: переключение строго по расписанию.'; ?></p>

			<?php if ( $flash ) : ?>
				<div class="notice notice-<?php echo 'error' === $flash[0] ? 'error' : 'success'; ?> is-dismissible"><p><?php echo esc_html( $flash[1] ); ?></p></div>
			<?php endif; ?>
			<?php foreach ( KZSV_Notify::active() as $p ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $p['text'] ); ?> <span class="kzsv-muted">(<?php echo esc_html( self::when( $p['at'] ) ); ?>)</span></p></div>
			<?php endforeach; ?>

			<div class="kzsv-grid">
				<section class="kzsv-card kzsv-now">
					<h2>Сейчас</h2>
					<?php if ( is_wp_error( $plan ) ) : ?>
						<p class="kzsv-bad"><?php echo esc_html( $plan->get_error_message() ); ?></p>
					<?php else : ?>
						<dl class="kzsv-dl">
							<dt>По расписанию</dt>
							<dd><?php echo $cur ? '«' . esc_html( $cur['title'] ) . '» <span class="kzsv-muted">' . esc_html( self::day_label( $cur['start'] ) ) . '</span>' : '<span class="kzsv-muted">концертов нет</span>'; ?>
								<?php if ( $ov ) : ?><br><span class="kzsv-muted">Вручную поставлено «<?php echo esc_html( isset( $last['title'] ) ? $last['title'] : '' ); ?>», держится до <?php echo esc_html( self::when( $ov['until'] ) ); ?></span><?php endif; ?>
							</dd>
							<dt>В StreamVi</dt>
							<dd>
								<?php
								if ( ! $conn ) {
									echo '<span class="kzsv-muted">не подключено</span>';
								} elseif ( $peek['tpl'] ) {
									$match = ( $cur && trim( $peek['tpl']['title'] ) === trim( $ov && isset( $last['title'] ) ? $last['title'] : $cur['title'] ) );
									echo '«' . esc_html( $peek['tpl']['title'] ) . '» ' . ( $match ? '<span class="kzsv-good">совпадает</span>' : '<span class="kzsv-warn">не совпадает с расписанием</span>' );
								} elseif ( '' !== $peek['err'] ) {
									echo '<span class="kzsv-bad">' . esc_html( $peek['err'] ) . '</span>';
								} elseif ( ! empty( $last['title'] ) ) {
									echo '«' . esc_html( $last['title'] ) . '» <span class="kzsv-muted">отправлено ' . esc_html( self::when( $last['at'] ) ) . '</span>';
								} else {
									echo '<span class="kzsv-muted">ещё ничего не отправлялось</span>';
								}
								?>
							</dd>
							<dt>Эфир</dt>
							<dd>
								<?php
								if ( is_array( $peek['live'] ) && 'live' === $peek['live']['state'] ) {
									echo '<span class="kzsv-live">идёт</span>' . ( $peek['live']['started'] ? ' с ' . esc_html( self::when( $peek['live']['started'] ) ) : '' );
								} elseif ( is_array( $peek['live'] ) && 'offline' === $peek['live']['state'] ) {
									echo 'не идёт';
								} elseif ( is_array( $peek['live'] ) ) {
									echo '<span class="kzsv-warn">неизвестно</span> <span class="kzsv-muted">(StreamVi ответил непонятно: ' . esc_html( $peek['live']['status'] ) . ')</span>';
								} else {
									echo '<span class="kzsv-muted">неизвестно</span>';
								}
								?>
							</dd>
							<?php if ( $pending ) : ?>
								<dt>Ждёт</dt>
								<dd>«<?php echo esc_html( $pending['title'] ); ?>»: <?php echo ( isset( $pending['why'] ) && 'unknown' === $pending['why'] ) ? 'статус эфира неизвестен, жду подтверждения' : 'идёт эфир, жду окончания'; ?><?php echo ! empty( $pending['deadline'] ) ? ', не позже ' . esc_html( self::when( $pending['deadline'] ) ) : ''; ?></dd>
							<?php endif; ?>
							<?php if ( $drift ) : ?>
								<dt>Расхождение</dt>
								<dd>в StreamVi вручную стоит «<?php echo esc_html( $drift['title'] ); ?>» (замечено <?php echo esc_html( self::when( $drift['at'] ) ); ?>). <?php echo $s['restore_drift'] ? 'Плагин вернёт название по расписанию.' : 'Останется до следующего переключения. Вернуть сразу: «Отправить заново» в расписании.'; ?></dd>
							<?php endif; ?>
							<dt>Дальше</dt>
							<dd><?php echo $next ? esc_html( self::when( $next['act'] ) ) . ' → «' . esc_html( $next['title'] ) . '»' : '<span class="kzsv-muted">в расписании пока ничего</span>'; ?></dd>
						</dl>
					<?php endif; ?>
					<div class="kzsv-actions">
						<?php echo self::form( 'run', 'Проверить сейчас', 'button button-primary' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<?php echo self::form( 'toggle', $s['enabled'] ? 'Поставить на паузу' : 'Включить', 'button' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					</div>
				</section>

				<section class="kzsv-card" id="connect">
					<h2>Подключение StreamVi</h2>
					<?php if ( $conn ) : ?>
						<p class="kzsv-good">Подключено к проекту <?php echo esc_html( '' !== $sec['project_external_id'] ? $sec['project_external_id'] : '#' . $sec['project_id'] ); ?><?php echo $sec['connected_at'] ? ', ' . esc_html( wp_date( 'd.m.Y', $sec['connected_at'], KZSV_Settings::tz() ) ) : ''; ?>.</p>
						<p class="kzsv-muted">Права: <?php echo esc_html( '' !== $sec['scope'] ? $sec['scope'] : 'не указаны' ); ?><?php echo $sec['expires_at'] ? '. Доступ действует до ' . esc_html( wp_date( 'd.m.Y', $sec['expires_at'], KZSV_Settings::tz() ) ) : ''; ?></p>
						<?php if ( ! KZSV_Settings::has_scope( 'broadcast:read' ) && ! KZSV_Settings::has_scope( 'project:read' ) ) : ?>
							<p class="kzsv-warn">Нет прав broadcast:read и project:read: плагин не видит, идёт ли эфир, поэтому при защите эфира ждёт до крайнего срока. Подключите заново и разрешите все права.</p>
						<?php endif; ?>
						<div class="kzsv-actions">
							<?php echo self::form( 'check', 'Проверить доступ', 'button' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<?php echo self::form( 'disconnect', 'Отключить', 'button-link kzsv-danger', array(), 'Отключить StreamVi? Название трансляции перестанет меняться. Полностью отозвать доступ можно в кабинете StreamVi.' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						</div>
						<details class="kzsv-details"><summary>Подключить заново или другим приложением</summary>
					<?php else : ?>
						<ol class="kzsv-steps">
							<li>В кабинете StreamVi: меню → «Для разработчиков» → приложение «Автоматизация стримов» → «Настройки».</li>
							<li>В «OAuth Redirect URL» нажмите «Добавить», вставьте адрес ниже и сохраните.</li>
							<li>Там же скопируйте «ID клиента» и вставьте сюда. Секретный ключ по инструкции StreamVi для подключения не нужен; если он всё же понадобится, вставьте и его (старый лучше перевыпустить: он хранился на удалённом сервере). Старый адрес kotirafiku.beget.app из OAuth Redirect URL можно удалить.</li>
							<li>Нажмите «Подключить StreamVi», выберите проект трансляций и разрешите доступ.</li>
						</ol>
					<?php endif; ?>
					<p><label>OAuth Redirect URL (для настроек приложения в StreamVi)</label>
						<span class="kzsv-copy"><code id="kzsv-redirect"><?php echo esc_html( KZSV_Settings::redirect_uri() ); ?></code> <button type="button" class="button button-small" data-kzsv-copy="kzsv-redirect">Копировать</button></span></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="kzsv-form">
						<input type="hidden" name="action" value="kzsv_connect">
						<?php wp_nonce_field( 'kzsv_connect' ); ?>
						<p><label for="kzsv-client">ID клиента</label><br><input id="kzsv-client" type="text" name="client_id" class="regular-text code" value="<?php echo esc_attr( (string) $s['client_id'] ); ?>" autocomplete="off"></p>
						<p><label for="kzsv-secret">Секретный ключ <span class="kzsv-muted">(необязательно)</span></label><br><input id="kzsv-secret" type="password" name="client_secret" class="regular-text code" value="" placeholder="<?php echo '' !== $sec['client_secret'] ? 'сохранён, пусто: оставить прежний' : ''; ?>" autocomplete="new-password"></p>
						<p><button type="submit" class="button button-primary">Подключить StreamVi</button></p>
					</form>
					<?php if ( $conn ) : ?>
						</details>
					<?php endif; ?>
				</section>
			</div>

			<section class="kzsv-card" id="plan">
				<h2>Расписание главной сцены</h2>
				<?php if ( is_wp_error( $plan ) ) : ?>
					<p class="kzsv-bad"><?php echo esc_html( $plan->get_error_message() ); ?></p>
				<?php else : ?>
					<p class="kzsv-muted">Источник: <?php echo esc_html( $plan['source']->label() ); ?>. Отметка «не транслируется», своё название и описание задаются в карточке концерта, блок «Трансляция StreamVi».</p>
					<table class="widefat striped kzsv-table">
						<thead><tr><th>Начало</th><th>Концерт</th><th>Переключение</th><th>Состояние</th><th></th></tr></thead>
						<tbody>
						<?php
						$shown = 0;
						foreach ( $plan['timeline'] as $e ) {
							if ( $e['start'] < $now - 12 * HOUR_IN_SECONDS || $e['start'] > $now + 4 * DAY_IN_SECONDS ) {
								continue;
							}
							$shown++;
							$is_last = isset( $last['key'] ) && $last['key'] === $e['key'];
							if ( null === $e['act'] ) {
								$label = '<span class="kzsv-muted">не транслируется' . ( ! empty( $e['rule'] ) ? ' (правило)' : ' (отмечено в концерте)' ) . '</span>';
							} elseif ( $is_last ) {
								$label = '<span class="kzsv-good">стоит в StreamVi</span>' . ( ! empty( $last['at'] ) ? ' <span class="kzsv-muted">с ' . esc_html( self::when( $last['at'] ) ) . '</span>' : '' );
							} elseif ( ! empty( $pending['key'] ) && $pending['key'] === $e['key'] ) {
								$label = '<span class="kzsv-warn">ждёт окончания эфира</span>';
							} elseif ( $cur && $cur['key'] === $e['key'] ) {
								$label = '<span class="kzsv-warn">пора поставить</span>';
							} elseif ( $e['act'] > $now ) {
								$label = 'будет';
							} else {
								$label = '<span class="kzsv-muted">прошёл</span>';
							}
							$edit = get_edit_post_link( $e['post_id'] );
							?>
							<tr class="<?php echo ( $cur && $cur['key'] === $e['key'] ) ? 'kzsv-row-cur' : ''; ?>">
								<td class="kzsv-nowrap"><?php echo esc_html( self::day_label( $e['start'] ) ); ?><?php echo $e['end'] ? '<br><span class="kzsv-muted">до ' . esc_html( wp_date( 'H:i', $e['end'], KZSV_Settings::tz() ) ) . '</span>' : ''; ?></td>
								<td><strong><?php echo $edit ? '<a href="' . esc_url( $edit ) . '">' . esc_html( $e['title'] ) . '</a>' : esc_html( $e['title'] ); ?></strong><?php echo $e['custom'] ? ' <span class="kzsv-tag">своё</span>' : ''; ?><br><span class="kzsv-muted"><?php echo esc_html( KZSV_Text::clip( $e['desc'], 140 ) ); ?></span></td>
								<td class="kzsv-nowrap"><?php echo null === $e['act'] ? '' : esc_html( self::when( $e['act'] ) ); ?></td>
								<td><?php echo $label; // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
								<td class="kzsv-nowrap"><?php echo ( null !== $e['act'] && $conn && ! $s['dry_run'] && $e['start'] > $now - 6 * HOUR_IN_SECONDS ) ? self::form( 'send', $is_last ? 'Отправить заново' : 'Поставить сейчас', 'button button-small', array( 'key' => $e['key'] ), 'Поставить в StreamVi «' . $e['title'] . '» прямо сейчас? Если идёт эфир, у него тоже сменится название.' ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
							</tr>
							<?php
						}
						if ( ! $shown ) {
							echo '<tr><td colspan="5" class="kzsv-muted">На ближайшие дни концертов главной сцены нет.</td></tr>';
						}
						?>
						</tbody>
					</table>
				<?php endif; ?>
			</section>

			<section class="kzsv-card" id="rules">
				<h2>Правила</h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="kzsv-form">
					<input type="hidden" name="action" value="kzsv_settings">
					<?php wp_nonce_field( 'kzsv_settings' ); ?>
					<table class="form-table" role="presentation">
						<tr><th scope="row">Переименовать</th><td>за <input type="number" name="lead_min" min="0" max="360" value="<?php echo (int) $s['lead_min']; ?>" class="small-text"> мин до начала концерта</td></tr>
						<tr><th scope="row">После концерта</th><td>концерт считается законченным через <input type="number" name="hold_min" min="30" max="720" value="<?php echo (int) $s['hold_min']; ?>" class="small-text"> мин после начала (или в конец по афише, если он позже); сразу после этого ставится следующий концерт главной сцены</td></tr>
						<tr><th scope="row">Идущий эфир</th><td><label><input type="checkbox" name="guard" value="1" <?php checked( $s['guard'] ); ?>> не переименовывать, пока идёт эфир предыдущего концерта или StreamVi не подтвердил, что эфира нет</label><br>
							<label><input type="checkbox" name="force" value="1" <?php checked( $s['force'] ); ?>> в крайнем случае всё же переименовать за</label> <input type="number" name="force_before_min" min="0" max="120" value="<?php echo (int) $s['force_before_min']; ?>" class="small-text"> мин до начала следующего концерта<br>
							<span class="kzsv-muted">С галочкой концерт не начнётся под чужим названием, но если предыдущий эфир затянулся без перерыва, его запись получит новое название. Без галочки название не сменится, пока эфир не закончится.</span></td></tr>
						<tr><th scope="row">Ручные правки в StreamVi</th><td><label><input type="checkbox" name="restore_drift" value="1" <?php checked( $s['restore_drift'] ); ?>> если название в StreamVi поменяли вручную, вернуть название по расписанию</label><br><span class="kzsv-muted">Без галочки ручное название остаётся до следующего переключения, а расхождение видно здесь и в журнале. Сверка раз в 15 минут.</span></td></tr>
						<tr><th scope="row">Название</th><td>как на афише, не длиннее <input type="number" name="title_max" min="20" max="100" value="<?php echo (int) $s['title_max']; ?>" class="small-text"> символов <span class="kzsv-muted">(YouTube принимает до 100)</span></td></tr>
						<tr><th scope="row">Описание</th><td>первый абзац анонса не короче <input type="number" name="par_min" min="0" max="400" value="<?php echo (int) $s['par_min']; ?>" class="small-text"> символов (короткие строки вроде «Состав:» пропускаются), всего не длиннее <input type="number" name="desc_max" min="50" max="5000" value="<?php echo (int) $s['desc_max']; ?>" class="small-text"> символов, обрезка по концу предложения</td></tr>
						<tr><th scope="row">Не транслируются никогда</th><td><textarea name="exclude" class="large-text" rows="3"><?php echo esc_textarea( (string) $s['exclude'] ); ?></textarea><br><span class="kzsv-muted">По одному названию в строке. Концерт пропускается целиком, если его название на афише содержит эту строку (регистр не важен). Для разового случая есть галочка в карточке концерта.</span></td></tr>
						<tr><th scope="row">Если анонса нет</th><td><input type="text" name="desc_fallback" class="large-text" value="<?php echo esc_attr( (string) $s['desc_fallback'] ); ?>"></td></tr>
						<tr><th scope="row">Строка в конце описания</th><td><textarea name="desc_suffix" class="large-text" rows="2" placeholder="Пусто: ничего не добавлять. Например: Билеты и программа: {url}"><?php echo esc_textarea( (string) $s['desc_suffix'] ); ?></textarea><br><span class="kzsv-muted">Подстановки: {url} ссылка на концерт, {date} дата, {time} время начала.</span></td></tr>
						<tr><th scope="row">Правки</th><td><label><input type="checkbox" name="resend_on_edit" value="1" <?php checked( $s['resend_on_edit'] ); ?>> если поправили название или анонс концерта, который сейчас стоит, отправить заново</label></td></tr>
						<tr><th scope="row">Письма</th><td><label><input type="checkbox" name="notify" value="1" <?php checked( $s['notify'] ); ?>> сообщать о сбоях на почту</label> <input type="email" name="notify_email" class="regular-text" value="<?php echo esc_attr( (string) $s['notify_email'] ); ?>" placeholder="<?php echo esc_attr( (string) get_option( 'admin_email' ) ); ?>"></td></tr>
						<tr><th scope="row">Источник афиши</th><td><select name="source">
							<option value="auto" <?php selected( $s['source'], 'auto' ); ?>>Автоматически</option>
							<option value="ai1ec" <?php selected( $s['source'], 'ai1ec' ); ?>>All-in-One Event Calendar (текущий сайт)</option>
							<option value="core" <?php selected( $s['source'], 'core' ); ?>>Kozlov Core (новый сайт)</option>
						</select></td></tr>
						<tr><th scope="row">Тестовый режим</th><td><label><input type="checkbox" name="dry_run" value="1" <?php checked( $s['dry_run'] ); ?>> только записывать в журнал, что было бы отправлено, в StreamVi ничего не менять</label></td></tr>
					</table>
					<p><button type="submit" class="button button-primary">Сохранить правила</button></p>
				</form>
			</section>

			<section class="kzsv-card" id="reliability">
				<h2>Надёжность</h2>
				<?php
				$due   = (int) get_option( KZSV_Settings::OPT_DUE );
				$tick  = isset( $state['last_tick'] ) ? (int) $state['last_tick'] : 0;
				$wpcr  = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
				$stale = $s['enabled'] && ( ! $tick || time() - $tick > 40 * MINUTE_IN_SECONDS );
				?>
				<dl class="kzsv-dl">
					<dt>Последняя проверка</dt><dd><?php echo $tick ? esc_html( self::when( $tick ) ) . ' <span class="kzsv-muted">(' . esc_html( isset( $state['last_reason'] ) ? $state['last_reason'] : '' ) . ')</span>' : 'ещё не было'; ?></dd>
					<dt>Следующая</dt><dd><?php echo $due ? esc_html( self::when( $due ) ) : '<span class="kzsv-muted">по страховке раз в 15 минут</span>'; ?></dd>
					<dt>WP-Cron</dt><dd><?php echo $wpcr ? '<span class="kzsv-warn">отключён на сайте (DISABLE_WP_CRON): нужна внешняя ссылка ниже</span>' : ( $stale ? '<span class="kzsv-warn">проверок не было больше 40 минут: подключите внешний вызов ниже</span>' : '<span class="kzsv-good">работает: последняя проверка была меньше 40 минут назад</span>' ); ?></dd>
				</dl>
				<p class="kzsv-muted">Проверка запускается сама ровно в момент переключения и раз в 15 минут для страховки. Если на сайте мало посещений ночью или WP-Cron отключён, добавьте в cron хостинга (или на cron-job.org) POST-запрос раз в 5 минут. Ключ передаётся в заголовке <code>X-Kzsv-Key</code>, в адресе его нет, поэтому он не попадает в журналы сервера. Чаще раза в минуту вызов не срабатывает. На Beget с включённой защитой нужен ещё заголовок <code>Cookie: beget=begetok</code>.</p>
				<p class="kzsv-copy"><code id="kzsv-tick">curl -fsS -X POST -H "X-Kzsv-Key: <?php echo esc_html( KZSV_Settings::tick_key() ); ?>" -H "Cookie: beget=begetok" "<?php echo esc_html( rest_url( KZSV_Cron::NS . '/tick' ) ); ?>" &gt;/dev/null</code> <button type="button" class="button button-small" data-kzsv-copy="kzsv-tick">Копировать</button>
					<?php echo self::form( 'new_key', 'Сменить ключ', 'button-link', array(), 'Старый ключ перестанет работать. Сменить?' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
			</section>

			<section class="kzsv-card" id="log">
				<h2>Журнал <?php echo self::form( 'clear_log', 'Очистить', 'button-link', array(), 'Очистить журнал?' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></h2>
				<?php
				$log = array_reverse( KZSV_Log::all() );
				if ( ! $log ) {
					echo '<p class="kzsv-muted">Пока пусто.</p>';
				} else {
					echo '<table class="widefat striped kzsv-log"><tbody>';
					foreach ( array_slice( $log, 0, 80 ) as $row ) {
						echo '<tr class="kzsv-log-' . esc_attr( $row[1] ) . '"><td class="kzsv-nowrap">' . esc_html( wp_date( 'd.m H:i', (int) $row[0], KZSV_Settings::tz() ) ) . '</td><td>' . esc_html( $row[2] ) . '</td></tr>';
					}
					echo '</tbody></table>';
				}
				?>
			</section>
			<p class="kzsv-muted">Kozlov StreamVi <?php echo esc_html( KZSV_VERSION ); ?>. Сайт, тему и афишу плагин не меняет: читает концерты и отправляет название в StreamVi.</p>
		</div>
		<?php
	}
}
