<?php
/**
 * Настройки, секреты (зашифрованы ключами сайта) и состояние плагина.
 */

defined( 'ABSPATH' ) || exit;

final class KZSV_Settings {

	const OPT         = 'kzsv_settings';
	const OPT_SECRETS = 'kzsv_secrets';
	const OPT_STATE   = 'kzsv_state';
	const OPT_DUE     = 'kzsv_next_due';
	const PAGE        = 'kozlov-streamvi';

	/** @var array|null */
	private static $cache = null;

	public static function defaults() {
		return array(
			'enabled'          => 1,   // общий выключатель
			'dry_run'          => 0,   // тестовый режим: только журнал, в StreamVi ничего не уходит
			'source'           => 'auto',
			'lead_min'         => 60,  // за сколько минут до начала концерта переименовывать
			'hold_min'         => 180, // через сколько минут после начала концерт считается законченным
			'guard'            => 1,   // не трогать идущий эфир предыдущего концерта (и при неизвестном статусе)
			'force'            => 1,   // в крайнем случае всё же переименовать...
			'force_before_min' => 10,  // ...за столько минут до начала следующего концерта
			'restore_drift'    => 0,   // название в StreamVi поменяли вручную: вернуть по расписанию
			'title_max'        => 100, // YouTube принимает до 100 символов
			'desc_max'         => 1000,
			'par_min'          => 40,  // абзац короче (например «Состав:») пропускается
			'desc_fallback'    => 'Прямая трансляция из Клуба Алексея Козлова.',
			'desc_suffix'      => '',
			'exclude'          => 'Зона 4/5', // концерты, которые никогда не транслируются (часть названия, по строке)
			'resend_on_edit'   => 1,   // исправили название или анонс текущего концерта: отправить заново
			'notify'           => 1,
			'notify_email'     => '',
			'client_id'        => '',
			'tick_key'         => '',
		);
	}

	public static function get( $key = null ) {
		if ( null === self::$cache ) {
			$raw         = get_option( self::OPT, array() );
			self::$cache = array_merge( self::defaults(), is_array( $raw ) ? $raw : array() );
		}
		if ( null === $key ) {
			return self::$cache;
		}
		return isset( self::$cache[ $key ] ) ? self::$cache[ $key ] : null;
	}

	public static function update( array $values ) {
		$all = array_merge( self::get(), $values );
		$all = self::sanitize( $all );
		update_option( self::OPT, $all, false );
		self::$cache = $all;
		return $all;
	}

	public static function sanitize( array $s ) {
		$d   = self::defaults();
		$out = array();
		$int = array(
			'lead_min'         => array( 0, 360 ),
			'hold_min'         => array( 30, 720 ),
			'force_before_min' => array( 0, 120 ),
			'title_max'        => array( 20, 100 ), // больше 100 YouTube не принимает
			'desc_max'         => array( 50, 5000 ),
			'par_min'          => array( 0, 400 ),
		);
		foreach ( $d as $k => $def ) {
			$v = isset( $s[ $k ] ) ? $s[ $k ] : $def;
			if ( isset( $int[ $k ] ) ) {
				$v = max( $int[ $k ][0], min( $int[ $k ][1], (int) $v ) );
			} elseif ( in_array( $k, array( 'enabled', 'dry_run', 'guard', 'force', 'restore_drift', 'resend_on_edit', 'notify' ), true ) ) {
				$v = $v ? 1 : 0;
			} elseif ( 'source' === $k ) {
				$v = in_array( $v, array( 'auto', 'ai1ec', 'core' ), true ) ? $v : 'auto';
			} elseif ( 'notify_email' === $k ) {
				$v = sanitize_email( (string) $v );
			} elseif ( 'client_id' === $k ) {
				$v = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $v );
			} elseif ( 'tick_key' === $k ) {
				$v = preg_replace( '/[^A-Za-z0-9]/', '', (string) $v );
			} elseif ( in_array( $k, array( 'desc_fallback', 'desc_suffix', 'exclude' ), true ) ) {
				$v = sanitize_textarea_field( (string) $v );
			}
			$out[ $k ] = $v;
		}
		return $out;
	}

	/**
	 * Часовой пояс клуба: Москва, независимо от настройки часового пояса WordPress.
	 * Афиша печатает время по Москве; переопределить можно фильтром kzsv_timezone.
	 */
	public static function tz() {
		static $tz = null;
		if ( null === $tz ) {
			$name = function_exists( 'apply_filters' ) ? (string) apply_filters( 'kzsv_timezone', 'Europe/Moscow' ) : 'Europe/Moscow';
			try {
				$tz = new DateTimeZone( $name );
			} catch ( \Throwable $e ) {
				$tz = new DateTimeZone( 'Europe/Moscow' );
			}
		}
		return $tz;
	}

	public static function admin_url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	/** Адрес, на который StreamVi возвращает после разрешения доступа (вписывается в настройки приложения). */
	public static function redirect_uri() {
		return rest_url( 'kozlov-streamvi/v1/oauth' );
	}

	public static function tick_key() {
		$key = (string) self::get( 'tick_key' );
		if ( strlen( $key ) < 24 ) {
			$key = wp_generate_password( 32, false, false );
			self::update( array( 'tick_key' => $key ) );
		}
		return $key;
	}

	/* ------------------------------------------------------------ секреты */

	/**
	 * client_secret, access_token, project_id, project_external_id, scope, connected_at, expires_at.
	 */
	public static function secrets() {
		$raw = get_option( self::OPT_SECRETS, array() );
		$raw = is_array( $raw ) ? $raw : array();
		$out = array(
			'client_secret'       => isset( $raw['client_secret'] ) ? self::open( $raw['client_secret'] ) : '',
			'access_token'        => isset( $raw['access_token'] ) ? self::open( $raw['access_token'] ) : '',
			'project_id'          => isset( $raw['project_id'] ) ? (int) $raw['project_id'] : 0,
			'project_external_id' => isset( $raw['project_external_id'] ) ? (string) $raw['project_external_id'] : '',
			'scope'               => isset( $raw['scope'] ) ? (string) $raw['scope'] : '',
			'connected_at'        => isset( $raw['connected_at'] ) ? (int) $raw['connected_at'] : 0,
			'expires_at'          => isset( $raw['expires_at'] ) ? (int) $raw['expires_at'] : 0,
		);
		return $out;
	}

	public static function save_secrets( array $values ) {
		$cur = self::secrets();
		$all = array_merge( $cur, $values );
		$raw = array(
			'client_secret'       => self::seal( (string) $all['client_secret'] ),
			'access_token'        => self::seal( (string) $all['access_token'] ),
			'project_id'          => (int) $all['project_id'],
			'project_external_id' => (string) $all['project_external_id'],
			'scope'               => (string) $all['scope'],
			'connected_at'        => (int) $all['connected_at'],
			'expires_at'          => (int) $all['expires_at'],
		);
		update_option( self::OPT_SECRETS, $raw, false );
	}

	public static function connected() {
		$s = self::secrets();
		return '' !== $s['access_token'] && $s['project_id'] > 0;
	}

	public static function has_scope( $scope ) {
		$s = self::secrets();
		return in_array( $scope, preg_split( '/[\s,]+/', $s['scope'] ), true );
	}

	private static function key() {
		$salt = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : ( defined( 'AUTH_KEY' ) ? AUTH_KEY : 'kzsv' );
		return hash( 'sha256', 'kzsv|' . $salt, true );
	}

	/** Шифрует строку ключами сайта: в дампе базы токен и секрет не читаются. */
	public static function seal( $plain ) {
		$plain = (string) $plain;
		if ( '' === $plain ) {
			return '';
		}
		try {
			if ( function_exists( 'sodium_crypto_secretbox' ) ) {
				$nonce = random_bytes( 24 );
				return 's1:' . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, self::key() ) );
			}
			if ( function_exists( 'openssl_encrypt' ) ) {
				$iv  = random_bytes( 16 );
				$ct  = openssl_encrypt( $plain, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv );
				$mac = hash_hmac( 'sha256', $iv . $ct, self::key(), true );
				return 'o1:' . base64_encode( $iv . $mac . $ct );
			}
		} catch ( \Throwable $e ) {
			kzsv_log_exception( $e );
		}
		return 'p0:' . base64_encode( $plain );
	}

	/** Расшифровка. Если ключи сайта сменились (перенос на другой сайт), вернёт пустую строку: нужно подключиться заново. */
	public static function open( $sealed ) {
		$sealed = (string) $sealed;
		if ( strlen( $sealed ) < 4 ) {
			return '';
		}
		$kind = substr( $sealed, 0, 3 );
		$bin  = base64_decode( substr( $sealed, 3 ), true );
		if ( false === $bin ) {
			return '';
		}
		try {
			if ( 's1:' === $kind && function_exists( 'sodium_crypto_secretbox_open' ) && strlen( $bin ) > 24 ) {
				$out = sodium_crypto_secretbox_open( substr( $bin, 24 ), substr( $bin, 0, 24 ), self::key() );
				return ( false === $out ) ? '' : $out;
			}
			if ( 'o1:' === $kind && function_exists( 'openssl_decrypt' ) && strlen( $bin ) > 48 ) {
				$iv  = substr( $bin, 0, 16 );
				$mac = substr( $bin, 16, 32 );
				$ct  = substr( $bin, 48 );
				if ( ! hash_equals( hash_hmac( 'sha256', $iv . $ct, self::key(), true ), $mac ) ) {
					return '';
				}
				$out = openssl_decrypt( $ct, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv );
				return ( false === $out ) ? '' : $out;
			}
			if ( 'p0:' === $kind ) {
				return $bin;
			}
		} catch ( \Throwable $e ) {
			kzsv_log_exception( $e );
		}
		return '';
	}

	/* ------------------------------------------------------------ состояние */

	public static function state() {
		wp_cache_delete( self::OPT_STATE, 'options' );
		$raw = get_option( self::OPT_STATE, array() );
		return is_array( $raw ) ? $raw : array();
	}

	public static function save_state( array $state ) {
		update_option( self::OPT_STATE, $state, false );
	}

	/** Обновляет несколько полей состояния, не затирая остальные (перечитывает свежую копию). */
	public static function patch_state( array $values ) {
		$state = array_merge( self::state(), $values );
		self::save_state( $state );
		return $state;
	}
}
