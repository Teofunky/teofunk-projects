<?php
/**
 * Plugin Name:       Kozlov StreamVi Guard
 * Description:       Страховка на время, когда сервер сайта не может связаться со StreamVi. Подключения к API ждём не дольше 3 с, после сбоя 5 минут не пытаемся, поэтому Kozlov TV и админка не тормозят. Пока статус эфира неизвестен, Kozlov TV во время концерта главной сцены (по афише) показывает «В эфире» со ссылкой на VK Видео. Код Kozlov TV, Kozlov StreamVi, темы и афиши не меняет. Настройки и проверка связи: «Трансляции → Связь со StreamVi».
 * Version:           1.0.0
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * Author:            Kozlov Club
 * License:           GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

final class KZSG {

	const VERSION    = '1.0.0';
	const HOST       = 'api-v2.streamvi.io';
	const OPT        = 'kzsg_settings';   // [ enabled, tv ]
	const STATE      = 'kzsg_state';      // [ until, since, error, fails, last_fail, last_ok, recovered ]
	const CHECK      = 'kzsg_check';      // результат кнопки «Проверить связь»
	const FB         = 'kzsg_live_fb';    // запасное состояние эфира для Kozlov TV
	const PAGE       = 'kozlov-streamvi-guard';
	const CONNECT_MS = 3000;              // ждать подключения к API не дольше
	const PAUSE      = 300;               // после сбоя не пытаться, с
	const LIVE_FROM  = 20 * 60;           // эфир по афише считаем начавшимся через 20 мин после начала концерта
	const FB_TTL     = 45;                // как кэш эфира в Kozlov TV

	/** @var bool Проверка связи из админки: идёт мимо паузы. */
	private static $force = false;

	/** @var array|null */
	private static $state = null;

	public static function init() {
		add_action( 'http_api_curl', array( __CLASS__, 'curl' ), 10, 3 );
		add_filter( 'pre_http_request', array( __CLASS__, 'pre' ), 5, 3 );
		add_action( 'http_api_debug', array( __CLASS__, 'after' ), 10, 5 );
		add_filter( 'pre_transient_kzv_live_state', array( __CLASS__, 'live_get' ), 10, 1 );
		add_filter( 'pre_set_transient_kzv_live_state', array( __CLASS__, 'live_set' ), 10, 1 );
		if ( is_admin() ) {
			add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
			add_action( 'admin_post_kzsg_check', array( __CLASS__, 'do_check' ) );
			add_action( 'admin_post_kzsg_save', array( __CLASS__, 'do_save' ) );
			add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( __CLASS__, 'links' ) );
		}
	}

	/* ------------------------------------------------------------ настройки и состояние */

	public static function opt( $key ) {
		$o = get_option( self::OPT );
		$o = is_array( $o ) ? $o : array();
		$o = array_merge(
			array(
				'enabled' => 1,
				'tv'      => 1,
			),
			$o
		);
		return (int) ( $o[ $key ] ?? 0 );
	}

	public static function state() {
		if ( null === self::$state ) {
			$s           = get_option( self::STATE );
			self::$state = array_merge(
				array(
					'until'     => 0,
					'since'     => 0,
					'error'     => '',
					'fails'     => 0,
					'last_fail' => 0,
					'last_ok'   => 0,
					'recovered' => 0,
				),
				is_array( $s ) ? $s : array()
			);
		}
		return self::$state;
	}

	private static function save_state( array $s ) {
		self::$state = $s;
		update_option( self::STATE, $s, false );
	}

	/** Идёт ли сейчас пауза после сбоя связи. */
	public static function paused() {
		return self::state()['until'] > time();
	}

	private static function is_api( $url ) {
		$host = wp_parse_url( (string) $url, PHP_URL_HOST );
		return is_string( $host ) && strtolower( $host ) === self::HOST;
	}

	/** Ошибка сети (нет подключения, таймаут, DNS, обрыв), а не ответ API. */
	private static function is_net( $msg ) {
		return (bool) preg_match( '/cURL error (6|7|28|35|52|56)\b|timed out|Failed to connect|Could not resolve|Connection refused|Connection reset/i', (string) $msg );
	}

	/** Ручные действия в админке (кнопки Kozlov StreamVi и Kozlov TV, проверка связи) идут мимо паузы. */
	private static function bypass() {
		if ( self::$force ) {
			return true;
		}
		return is_admin() && isset( $GLOBALS['pagenow'] ) && 'admin-post.php' === $GLOBALS['pagenow'];
	}

	/* ------------------------------------------------------------ HTTP */

	/** Подключение к API StreamVi ждём не дольше 3 с. Время ответа после подключения не ограничиваем. */
	public static function curl( $handle, $args, $url ) {
		try {
			if ( self::opt( 'enabled' ) && self::is_api( $url ) && defined( 'CURLOPT_CONNECTTIMEOUT_MS' ) ) {
				curl_setopt( $handle, CURLOPT_CONNECTTIMEOUT_MS, self::CONNECT_MS ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
			}
		} catch ( \Throwable $e ) {
			self::log( $e );
		}
	}

	/** Во время паузы запрос к API сразу получает ошибку, без ожидания. */
	public static function pre( $pre, $args, $url ) {
		try {
			if ( false !== $pre || ! self::opt( 'enabled' ) || ! self::is_api( $url ) || self::bypass() ) {
				return $pre;
			}
			$s = self::state();
			if ( $s['until'] > time() ) {
				return new WP_Error(
					'http_request_failed',
					'пауза после сбоя связи, следующая попытка после ' . self::when( $s['until'] ) . ' (последняя ошибка: ' . $s['error'] . ')'
				);
			}
		} catch ( \Throwable $e ) {
			self::log( $e );
		}
		return $pre;
	}

	/** После каждого запроса к API: сбой сети включает паузу, любой ответ API её снимает. */
	public static function after( $response, $context, $class, $args, $url ) {
		try {
			if ( 'response' !== $context || ! self::is_api( $url ) ) {
				return;
			}
			if ( is_wp_error( $response ) ) {
				if ( self::is_net( $response->get_error_message() ) ) {
					self::fail( $response->get_error_message() );
				}
				return;
			}
			self::ok();
		} catch ( \Throwable $e ) {
			self::log( $e );
		}
	}

	private static function fail( $msg ) {
		$s              = self::state();
		$now            = time();
		$s['since']     = $s['since'] ? $s['since'] : $now;
		$s['error']     = function_exists( 'mb_substr' ) ? mb_substr( (string) $msg, 0, 300 ) : substr( (string) $msg, 0, 300 );
		$s['until']     = $now + self::PAUSE;
		$s['fails']     = (int) $s['fails'] + 1;
		$s['last_fail'] = $now;
		self::save_state( $s );
		delete_transient( self::FB );
	}

	private static function ok() {
		$s   = self::state();
		$now = time();
		if ( $s['since'] || $s['until'] ) {
			$s['until']     = 0;
			$s['since']     = 0;
			$s['fails']     = 0;
			$s['recovered'] = $now;
			$s['last_ok']   = $now;
			self::save_state( $s );
			delete_transient( self::FB );
			return;
		}
		if ( $now - (int) $s['last_ok'] > 10 * MINUTE_IN_SECONDS ) {
			$s['last_ok'] = $now;
			self::save_state( $s );
		}
	}

	/* ------------------------------------------------------------ Kozlov TV */

	/**
	 * Во время паузы Kozlov TV не спрашивает StreamVi: состояние эфира берётся из афиши.
	 * Без паузы ничего не меняем.
	 */
	public static function live_get( $pre ) {
		if ( false !== $pre ) {
			return $pre;
		}
		try {
			if ( ! self::opt( 'enabled' ) || ! self::opt( 'tv' ) || ! self::paused() || ! self::tv_ready() ) {
				return false;
			}
			$fb = get_transient( self::FB );
			if ( is_array( $fb ) ) {
				return $fb;
			}
			$fb = self::guess( null );
			if ( is_array( $fb ) ) {
				set_transient( self::FB, $fb, self::FB_TTL );
				return $fb;
			}
		} catch ( \Throwable $e ) {
			self::log( $e );
		}
		return false;
	}

	/** Kozlov TV не узнал статус эфира (нет связи): если по афише идёт концерт, отмечаем эфир. */
	public static function live_set( $value ) {
		try {
			if ( ! is_array( $value ) || ! empty( $value['known'] ) || ! empty( $value['live'] ) ) {
				return $value;
			}
			if ( ! self::opt( 'enabled' ) || ! self::opt( 'tv' ) || ! self::tv_ready() ) {
				return $value;
			}
			$g = self::guess( $value );
			return is_array( $g ) ? $g : $value;
		} catch ( \Throwable $e ) {
			self::log( $e );
			return $value;
		}
	}

	private static function tv_ready() {
		return class_exists( 'KZV_Concerts' ) && class_exists( 'KZV_Util' );
	}

	/**
	 * Состояние эфира по афише в формате Kozlov TV.
	 *
	 * @param array|null $base Состояние от Kozlov TV или null (собрать с нуля).
	 * @return array|null
	 */
	public static function guess( $base, $now = null ) {
		$now    = null === $now ? time() : (int) $now;
		$around = KZV_Concerts::around( $now );
		if ( ! is_array( $base ) ) {
			$base = array(
				'live'    => false,
				'vk_oid'  => 0,
				'vk_id'   => 0,
				'yt_id'   => '',
				'title'   => '',
				'url'     => '',
				'started' => 0,
				'next'    => null,
				'checked' => $now,
				'known'   => false,
				'signal'  => '',
			);
			if ( ! empty( $around['next'] ) ) {
				$base['next'] = array(
					'title' => KZV_Util::clean_title( $around['next']['title'] ),
					'start' => (int) $around['next']['start'],
					'url'   => (string) $around['next']['url'],
				);
			}
		}
		$cur = $around['current'] ?? null;
		if ( ! is_array( $cur ) ) {
			return $base;
		}
		$start = (int) $cur['start'];
		$end   = ( (int) $cur['end'] > $start ) ? (int) $cur['end'] : $start + 3 * HOUR_IN_SECONDS;
		if ( $now < $start + self::LIVE_FROM || $now >= $end ) {
			return $base;
		}
		return array_merge(
			$base,
			array(
				'live'    => true,
				'signal'  => '',
				'vk_oid'  => 0,
				'vk_id'   => 0,
				'yt_id'   => '',
				'title'   => KZV_Util::clean_title( $cur['title'] ),
				'url'     => (string) $cur['url'],
				'started' => $start,
				'known'   => false,
				'guess'   => true,
			)
		);
	}

	/* ------------------------------------------------------------ админка */

	/** Меню «Трансляции» есть, если включён Kozlov StreamVi; иначе страница в «Настройках». */
	private static function under_streamvi() {
		return class_exists( 'KZSV_Settings' );
	}

	public static function menu() {
		$parent = self::under_streamvi() ? 'kozlov-streamvi' : 'options-general.php';
		add_submenu_page( $parent, 'Связь со StreamVi', 'Связь со StreamVi', 'manage_options', self::PAGE, array( __CLASS__, 'render' ) );
	}

	public static function links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">Настройки</a>' );
		return $links;
	}

	private static function url() {
		return admin_url( ( self::under_streamvi() ? 'admin.php' : 'options-general.php' ) . '?page=' . self::PAGE );
	}

	public static function do_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Нет прав.' );
		}
		check_admin_referer( 'kzsg_save' );
		update_option(
			self::OPT,
			array(
				'enabled' => empty( $_POST['enabled'] ) ? 0 : 1,
				'tv'      => empty( $_POST['tv'] ) ? 0 : 1,
			),
			true
		);
		delete_transient( self::FB );
		wp_safe_redirect( add_query_arg( 'kzsg', 'saved', self::url() ) );
		exit;
	}

	/** Проверка связи мимо паузы: ответ API и IP, с которого сервер ходит наружу. */
	public static function do_check() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Нет прав.' );
		}
		check_admin_referer( 'kzsg_check' );
		self::$force = true;
		$t0          = microtime( true );
		$r           = wp_remote_get(
			'https://' . self::HOST . '/',
			array(
				'timeout'     => 8,
				'redirection' => 0,
				'user-agent'  => 'KozlovStreamViGuard/' . self::VERSION . '; ' . home_url( '/' ),
			)
		);
		$ms          = (int) round( ( microtime( true ) - $t0 ) * 1000 );
		self::$force = false;
		$out         = array(
			'at' => time(),
			'ms' => $ms,
		);
		if ( is_wp_error( $r ) ) {
			$out['ok']   = false;
			$out['text'] = $r->get_error_message();
		} else {
			$out['ok']   = true;
			$out['code'] = (int) wp_remote_retrieve_response_code( $r );
		}
		$out['ip'] = '';
		foreach ( array( 'https://api.ipify.org', 'https://icanhazip.com' ) as $svc ) {
			$ip = wp_remote_get( $svc, array( 'timeout' => 5 ) );
			if ( ! is_wp_error( $ip ) && 200 === (int) wp_remote_retrieve_response_code( $ip ) ) {
				$cand = trim( (string) wp_remote_retrieve_body( $ip ) );
				if ( filter_var( $cand, FILTER_VALIDATE_IP ) ) {
					$out['ip'] = $cand;
					break;
				}
			}
		}
		set_transient( self::CHECK, $out, DAY_IN_SECONDS );
		wp_safe_redirect( add_query_arg( 'kzsg', 'checked', self::url() ) );
		exit;
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s     = self::state();
		$chk   = get_transient( self::CHECK );
		$now   = time();
		$ip    = ( is_array( $chk ) && ! empty( $chk['ip'] ) ) ? $chk['ip'] : '';
		$since = $s['since'] ? self::when( $s['since'] ) : '';
		?>
		<div class="wrap">
			<h1>Связь со StreamVi</h1>
			<?php if ( isset( $_GET['kzsg'] ) && 'saved' === $_GET['kzsg'] ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success is-dismissible"><p>Сохранено.</p></div>
			<?php endif; ?>
			<p>Если сервер сайта не может связаться с API StreamVi (<code><?php echo esc_html( self::HOST ); ?></code>), каждый запрос к нему раньше ждал до 10–12 секунд. Из-за этого тормозил Kozlov TV, а эфир на нём не показывался. Этот плагин ограничивает ожидание и включает запасной показ эфира.</p>

			<h2>Сейчас</h2>
			<table class="widefat striped" style="max-width:900px">
				<tbody>
				<tr>
					<td style="width:220px">Связь с API StreamVi</td>
					<td>
						<?php if ( $s['since'] ) : ?>
							<strong style="color:#b32d2e">нет с <?php echo esc_html( $since ); ?></strong>
							(<?php echo (int) $s['fails']; ?> сбоев подряд).
							<?php echo $s['until'] > $now ? 'Пауза, следующая попытка после ' . esc_html( self::when( $s['until'] ) ) . '.' : 'Следующий запрос будет настоящей попыткой.'; ?>
							<br><span style="color:#646970">Последняя ошибка: <?php echo esc_html( $s['error'] ); ?></span>
						<?php else : ?>
							<strong style="color:#008a20">есть</strong><?php echo $s['last_ok'] ? ', последний ответ ' . esc_html( self::when( $s['last_ok'] ) ) : ''; ?><?php echo $s['recovered'] ? ' (восстановилась ' . esc_html( self::when( $s['recovered'] ) ) . ')' : ''; ?>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<td>Kozlov TV</td>
					<td>
						<?php if ( ! self::tv_ready() ) : ?>
							плагин Kozlov Video не найден или выключен
						<?php elseif ( self::paused() && self::opt( 'tv' ) && self::opt( 'enabled' ) ) : ?>
							<?php $g = self::guess( null ); ?>
							статус эфира берётся из афиши: <?php echo ! empty( $g['live'] ) ? '<strong>«В эфире»: ' . esc_html( $g['title'] ) . '</strong>' : 'по афише сейчас эфира нет'; ?>
						<?php else : ?>
							статус эфира берётся из StreamVi
						<?php endif; ?>
					</td>
				</tr>
				<?php if ( is_array( $chk ) ) : ?>
					<tr>
						<td>Последняя проверка</td>
						<td>
							<?php echo esc_html( self::when( (int) $chk['at'] ) ); ?>:
							<?php if ( $chk['ok'] ) : ?>
								API ответил за <?php echo (int) $chk['ms']; ?> мс (код <?php echo (int) $chk['code']; ?>; 404 на корневой адрес это нормально, значит связь есть).
							<?php else : ?>
								<strong style="color:#b32d2e">нет связи</strong> за <?php echo (int) $chk['ms']; ?> мс: <?php echo esc_html( $chk['text'] ); ?>
							<?php endif; ?>
							<br>IP сервера для исходящих запросов: <strong><?php echo '' !== $ip ? esc_html( $ip ) : 'не удалось узнать'; ?></strong>
						</td>
					</tr>
				<?php endif; ?>
				</tbody>
			</table>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px">
				<input type="hidden" name="action" value="kzsg_check">
				<?php wp_nonce_field( 'kzsg_check' ); ?>
				<button type="submit" class="button button-primary">Проверить связь сейчас</button>
				<span style="color:#646970;margin-left:8px">до 15 секунд; если связь есть, пауза снимается сразу</span>
			</form>

			<h2>Настройки</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="kzsg_save">
				<?php wp_nonce_field( 'kzsg_save' ); ?>
				<p><label><input type="checkbox" name="enabled" value="1" <?php checked( self::opt( 'enabled' ) ); ?>> Включено: ждать подключения к StreamVi не дольше 3 с, после сбоя 5 минут не пытаться (кнопки в админке работают всегда)</label></p>
				<p><label><input type="checkbox" name="tv" value="1" <?php checked( self::opt( 'tv' ) ); ?>> Kozlov TV: пока связи нет, показывать «В эфире» по афише, начиная через 20 минут после начала концерта главной сцены и до его конца (без отметки «не транслируется»). Вместо плеера будет ссылка на VK Видео клуба.</label></p>
				<p><button type="submit" class="button">Сохранить</button></p>
			</form>

			<h2>Текст для поддержки StreamVi</h2>
			<textarea readonly rows="5" style="width:100%;max-width:900px">Здравствуйте. С <?php echo esc_html( '' !== $since ? $since : '[дата]' ); ?> МСК наш сервер не может подключиться к <?php echo esc_html( self::HOST ); ?> (порт 443): соединение не устанавливается, таймаут. Сервер на хостинге Beget, исходящий IP <?php echo esc_html( '' !== $ip ? $ip : '[нажмите «Проверить связь сейчас»]' ); ?>. Из других сетей API отвечает. Проверьте, пожалуйста, не блокирует ли защита от DDoS этот адрес, и при необходимости добавьте его в разрешённые.</textarea>

			<p style="color:#646970">Kozlov StreamVi Guard <?php echo esc_html( self::VERSION ); ?>. Меняет только время ожидания запросов к <?php echo esc_html( self::HOST ); ?> и, пока связи нет, состояние эфира для Kozlov TV. Выключение плагина возвращает всё как было.</p>
		</div>
		<?php
	}

	/* ------------------------------------------------------------ служебное */

	private static function when( $ts ) {
		$tz = new DateTimeZone( 'Europe/Moscow' );
		$d  = ( new DateTimeImmutable( '@' . (int) $ts ) )->setTimezone( $tz );
		$t  = ( new DateTimeImmutable( 'now', $tz ) )->format( 'Y-m-d' ) === $d->format( 'Y-m-d' ) ? $d->format( 'H:i' ) : $d->format( 'd.m H:i' );
		return $t;
	}

	private static function log( $e ) {
		$msg = ( $e instanceof \Throwable ) ? $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() : (string) $e;
		error_log( '[Kozlov StreamVi Guard] ' . $msg ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	public static function uninstall() {
		delete_option( self::OPT );
		delete_option( self::STATE );
		delete_transient( self::CHECK );
		delete_transient( self::FB );
	}
}

try {
	KZSG::init();
	register_uninstall_hook( __FILE__, array( 'KZSG', 'uninstall' ) );
} catch ( \Throwable $e ) {
	error_log( '[Kozlov StreamVi Guard] ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
}
