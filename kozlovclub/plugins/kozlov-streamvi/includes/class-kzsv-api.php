<?php
/**
 * StreamVi API: вход через OAuth (приложение «Автоматизация стримов» в кабинете StreamVi),
 * смена названия и описания (bulkUpdate), статус эфира, текущее название.
 * Документация: https://dev.streamvi.io
 */

defined( 'ABSPATH' ) || exit;

final class KZSV_Api {

	const BASE   = 'https://api-v2.streamvi.io';
	const AUTH   = 'https://streamvi.io/cabinet/oauth';
	const SCOPES = 'channel:read channel:write broadcast:read project:read';

	/* ------------------------------------------------------------ OAuth */

	public static function pkce() {
		$verifier  = rtrim( strtr( base64_encode( random_bytes( 48 ) ), '+/', '-_' ), '=' );
		$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
		return array( $verifier, $challenge );
	}

	public static function authorize_url( $client_id, $redirect, $state, $challenge ) {
		$args = array(
			'subject_type'          => 'project',
			'client_id'             => $client_id,
			'response_type'         => 'code',
			'redirect_uri'          => $redirect,
			'scope'                 => self::SCOPES,
			'state'                 => $state,
			'code_challenge'        => $challenge,
			'code_challenge_method' => 'S256',
		);
		return self::AUTH . '?' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Обмен кода на токен.
	 * Инструкция StreamVi (streamvi.io/ru/docs/dev/api): форма application/x-www-form-urlencoded,
	 * поля grant_type, client_id, redirect_uri, code, code_verifier, без client_secret.
	 * Справочник OpenAPI (dev.streamvi.io) описывает то же тело как JSON с необязательным client_secret.
	 * Поэтому порядок: форма по инструкции, форма с секретом (если он задан), JSON. Первый успех побеждает.
	 * Неудачная попытка из-за формата код не расходует; ошибка 401/403 и сетевой сбой прерывают перебор.
	 *
	 * @return array|WP_Error access_token, scope, project_id, project_external_id, _variant
	 */
	public static function exchange_code( $code, $client_id, $secret, $redirect, $verifier ) {
		$base = array(
			'grant_type'    => 'authorization_code',
			'client_id'     => (string) $client_id,
			'redirect_uri'  => (string) $redirect,
			'code'          => (string) $code,
			'code_verifier' => (string) $verifier,
		);
		$variants = array( array( 'form', $base, 'форма' ) );
		if ( '' !== (string) $secret ) {
			$variants[] = array( 'form', array_merge( $base, array( 'client_secret' => (string) $secret ) ), 'форма с секретом' );
			$variants[] = array( 'json', array_merge( $base, array( 'client_secret' => (string) $secret ) ), 'JSON с секретом' );
		} else {
			$variants[] = array( 'json', $base, 'JSON' );
		}
		$first = null;
		foreach ( $variants as $v ) {
			$res = self::raw( 'POST', '/site/oauth/token', array(), $v[1], '', $v[0] );
			if ( ! is_wp_error( $res ) ) {
				$data = $res['data'];
				if ( empty( $data['access_token'] ) ) {
					return new WP_Error( 'kzsv_token', 'StreamVi не выдал токен: ' . self::short( $res['body'] ) );
				}
				if ( empty( $data['project_id'] ) ) {
					return new WP_Error( 'kzsv_project', 'Доступ выдан не на проект. При подключении выберите проект трансляций.' );
				}
				$data['_variant'] = $v[2];
				return $data;
			}
			if ( null === $first ) {
				$first = $res;
			}
			if ( ! in_array( (int) $res->get_error_data(), array( 400, 415, 422 ), true ) ) {
				break; // не формат: дальше перебирать бессмысленно
			}
		}
		return $first;
	}

	/* ------------------------------------------------------------ методы */

	/** Новое название и описание на всех площадках проекта (глобальная настройка). */
	public static function bulk_update( $title, $desc ) {
		$sec = KZSV_Settings::secrets();
		$res = self::call(
			'POST',
			'/method/channel_settings/bulkUpdate',
			array(),
			array(
				'v'           => '1',
				'language'    => 'ru',
				'project_id'  => (int) $sec['project_id'],
				'title'       => (string) $title,
				'description' => (string) $desc,
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( empty( $res['data']['success'] ) ) {
			return new WP_Error( 'kzsv_bulk', 'StreamVi ответил без success: ' . self::short( $res['body'] ), $res['code'] );
		}
		return true;
	}

	/**
	 * Идёт ли сейчас эфир.
	 * Основной метод: GET /method/broadcast/status (справочник OpenAPI, право broadcast:read, схема ответа описана).
	 * Запасной: GET /method/project/live-status (инструкция StreamVi, право project:read, схема не описана).
	 * Ответ, который не удаётся однозначно понять, даёт state = unknown, а не «эфира нет».
	 *
	 * @return array|WP_Error [ state => live|offline|unknown, started => ts, status => строка, via => метод ]
	 */
	public static function live_status() {
		$sec  = KZSV_Settings::secrets();
		$pid  = (int) $sec['project_id'];
		$last = null;
		if ( KZSV_Settings::has_scope( 'broadcast:read' ) ) {
			$res = self::call( 'GET', '/method/broadcast/status', array( 'project_id' => $pid ) );
			if ( ! is_wp_error( $res ) ) {
				$st = self::parse_broadcast_status( $res['data'] );
				if ( 'unknown' !== $st['state'] ) {
					return $st;
				}
				$last = $st;
			} else {
				$last = $res;
			}
		}
		if ( KZSV_Settings::has_scope( 'project:read' ) ) {
			$res = self::call( 'GET', '/method/project/live-status', array( 'project_id' => $pid ) );
			if ( ! is_wp_error( $res ) ) {
				$st = self::parse_project_live( $res['data'] );
				if ( 'unknown' !== $st['state'] || null === $last || is_wp_error( $last ) ) {
					return $st;
				}
			} elseif ( null === $last ) {
				$last = $res;
			}
		}
		return ( null === $last ) ? new WP_Error( 'kzsv_scope', 'нет прав на чтение статуса эфира', 403 ) : $last;
	}

	/** Ответ /method/broadcast/status по схеме OpenAPI: kind inactive/live, status offline/active/pause/lost/done. */
	public static function parse_broadcast_status( $d ) {
		$out = array(
			'state'   => 'unknown',
			'started' => 0,
			'status'  => '',
			'via'     => 'broadcast/status',
		);
		if ( ! is_array( $d ) ) {
			return $out;
		}
		$kind          = isset( $d['kind'] ) && is_string( $d['kind'] ) ? $d['kind'] : '';
		$status        = isset( $d['status'] ) && is_string( $d['status'] ) ? $d['status'] : '';
		$out['status'] = trim( $kind . ' ' . $status );
		if ( 'inactive' === $kind && in_array( $status, array( 'offline', '' ), true ) ) {
			$out['state'] = 'offline';
		} elseif ( 'live' === $kind && in_array( $status, array( 'active', 'pause', 'lost' ), true ) ) {
			$out['state'] = 'live';
		} elseif ( 'live' === $kind && 'done' === $status ) {
			$out['state'] = 'offline';
		}
		if ( 'live' === $out['state'] && ! empty( $d['date_start'] ) && is_string( $d['date_start'] ) ) {
			$ts             = strtotime( $d['date_start'] );
			$out['started'] = $ts ? (int) $ts : 0;
		}
		return $out;
	}

	/** Ответ /method/project/live-status: схема не опубликована, понимаем только явные признаки. */
	public static function parse_project_live( $d ) {
		$out = array(
			'state'   => 'unknown',
			'started' => 0,
			'status'  => '',
			'via'     => 'project/live-status',
		);
		if ( ! is_array( $d ) ) {
			return $out;
		}
		if ( isset( $d['data'] ) && is_array( $d['data'] ) ) {
			$d = $d['data'];
		}
		foreach ( array( 'live', 'is_live', 'online', 'is_online' ) as $k ) {
			if ( array_key_exists( $k, $d ) && is_bool( $d[ $k ] ) ) {
				$out['state']  = $d[ $k ] ? 'live' : 'offline';
				$out['status'] = $k . '=' . ( $d[ $k ] ? 'true' : 'false' );
				return $out;
			}
		}
		if ( isset( $d['kind'] ) || isset( $d['status'] ) ) {
			$st        = self::parse_broadcast_status( $d );
			$st['via'] = 'project/live-status';
			return $st;
		}
		return $out;
	}

	/** Название и описание, которые сейчас стоят в StreamVi. */
	public static function template() {
		$sec = KZSV_Settings::secrets();
		$res = self::call( 'GET', '/method/broadcast/template', array( 'project_id' => (int) $sec['project_id'] ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return array(
			'title' => isset( $res['data']['title'] ) ? (string) $res['data']['title'] : '',
			'desc'  => isset( $res['data']['description'] ) ? (string) $res['data']['description'] : '',
		);
	}

	/** Проверка токена: живой ли, какие права, на какой проект. */
	public static function token_context() {
		$res = self::call( 'GET', '/site/oauth/token-context', array() );
		return is_wp_error( $res ) ? $res : $res['data'];
	}

	/* ------------------------------------------------------------ транспорт */

	private static function call( $method, $path, array $query, $body = null ) {
		$sec = KZSV_Settings::secrets();
		if ( '' === $sec['access_token'] ) {
			return new WP_Error( 'kzsv_auth', 'StreamVi не подключён', 401 );
		}
		if ( 'GET' === $method ) {
			$query = array_merge( array( 'v' => '1', 'language' => 'ru' ), $query );
		}
		return self::raw( $method, $path, $query, $body, $sec['access_token'], 'json' );
	}

	/**
	 * @return array|WP_Error [ code, data, body ]. У ошибки в data лежит HTTP-код.
	 */
	private static function raw( $method, $path, array $query, $body, $token, $format ) {
		$url = self::BASE . $path;
		if ( $query ) {
			$url .= '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
		}
		$headers = array(
			'Accept'              => 'application/json',
			'X-Client-Request-Id' => wp_generate_uuid4(),
		);
		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}
		$args = array(
			'method'      => $method,
			'timeout'     => 15,
			'redirection' => 0,
			'headers'     => $headers,
			'user-agent'  => 'KozlovStreamVi/' . KZSV_VERSION . '; ' . home_url( '/' ),
		);
		if ( null !== $body ) {
			if ( 'form' === $format ) {
				$args['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
				$args['body']                    = http_build_query( $body, '', '&' );
			} else {
				$args['headers']['Content-Type'] = 'application/json';
				$args['body']                    = wp_json_encode( $body );
			}
		}
		$res = wp_remote_request( $url, $args );
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'kzsv_net', 'нет связи со StreamVi: ' . $res->get_error_message(), 0 );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$raw  = (string) wp_remote_retrieve_body( $res );
		$data = json_decode( $raw, true );
		if ( $code >= 200 && $code < 300 ) {
			return array(
				'code' => $code,
				'data' => is_array( $data ) ? $data : array(),
				'body' => $raw,
			);
		}
		$msg = '';
		if ( is_array( $data ) && isset( $data['error'] ) ) {
			$err = $data['error'];
			$msg = is_array( $err ) ? (string) ( isset( $err['msg'] ) ? $err['msg'] : wp_json_encode( $err ) ) : (string) $err;
		}
		if ( '' === $msg ) {
			$msg = self::short( $raw );
		}
		$names = array(
			401 => 'kzsv_auth',
			403 => 'kzsv_scope',
			429 => 'kzsv_rate',
		);
		$name = isset( $names[ $code ] ) ? $names[ $code ] : ( $code >= 500 ? 'kzsv_server' : 'kzsv_http' );
		return new WP_Error( $name, 'StreamVi ответил ' . $code . ': ' . $msg, $code );
	}

	private static function short( $s ) {
		$s = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $s ) ) );
		return ( strlen( $s ) > 300 ) ? substr( $s, 0, 300 ) . '…' : $s;
	}
}
