<?php
/**
 * StreamVi API, только чтение. Доступ берётся из подключения плагина Kozlov StreamVi
 * (право broadcast:read), своего входа у этого плагина нет. Токен никуда не выводится.
 * Справочник: https://dev.streamvi.io (GET /method/broadcast/list, /method/broadcast,
 * /method/broadcast/status, /method/broadcast/restreams).
 */

defined( 'ABSPATH' ) || exit;

final class KZV_StreamVi {

	const BASE = 'https://api-v2.streamvi.io';

	/** Подключён ли StreamVi через Kozlov StreamVi и есть ли право читать трансляции. */
	public static function ready() {
		if ( ! class_exists( 'KZSV_Settings' ) || ! method_exists( 'KZSV_Settings', 'secrets' ) ) {
			return false;
		}
		$sec = self::secrets();
		if ( '' === $sec['token'] || $sec['project'] <= 0 ) {
			return false;
		}
		if ( method_exists( 'KZSV_Settings', 'has_scope' ) ) {
			try {
				return (bool) KZSV_Settings::has_scope( 'broadcast:read' );
			} catch ( \Throwable $e ) {
				return false;
			}
		}
		return true;
	}

	private static function secrets() {
		try {
			$s = KZSV_Settings::secrets();
		} catch ( \Throwable $e ) {
			$s = array();
		}
		return array(
			'token'   => isset( $s['access_token'] ) ? (string) $s['access_token'] : '',
			'project' => isset( $s['project_id'] ) ? (int) $s['project_id'] : 0,
		);
	}

	/**
	 * Список трансляций.
	 *
	 * @return array|WP_Error [ total, items => [ [ broadcast_id, started_at, finished_at ] ] ]
	 */
	public static function list_broadcasts( $offset, $limit, $order = 'desc' ) {
		$res = self::get(
			'/method/broadcast/list',
			array(
				'limit'  => min( 100, max( 1, (int) $limit ) ),
				'offset' => max( 0, (int) $offset ),
				'order'  => 'asc' === $order ? 'asc' : 'desc',
				'v'      => '3',
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$d     = self::unwrap( $res );
		$items = array();
		foreach ( (array) ( $d['results'] ?? array() ) as $r ) {
			if ( ! is_array( $r ) || empty( $r['id'] ) ) {
				continue;
			}
			$items[] = array(
				'broadcast_id' => (int) $r['id'],
				'started_at'   => KZV_Util::ts( $r['created_at'] ?? '' ),
				'finished_at'  => KZV_Util::ts( $r['finish_at'] ?? '' ),
			);
		}
		return array(
			'total' => (int) ( $d['total'] ?? 0 ),
			'items' => $items,
		);
	}

	/**
	 * Одна трансляция: ссылки на видео на площадках.
	 *
	 * @return array|WP_Error [ started_at, finished_at, links => [ [ type, name, url ] ] ]
	 */
	public static function broadcast( $broadcast_id ) {
		$res = self::get(
			'/method/broadcast',
			array(
				'broadcast_id' => (int) $broadcast_id,
				'v'            => '3',
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$d     = self::unwrap( $res );
		$links = array();
		foreach ( (array) ( $d['restreams'] ?? array() ) as $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			$links[] = array(
				'type' => (string) ( $r['type'] ?? '' ),
				'name' => (string) ( $r['name'] ?? '' ),
				'url'  => (string) ( $r['video'] ?? ( $r['url'] ?? '' ) ),
			);
		}
		$name = '';
		foreach ( array( 'name', 'title' ) as $k ) {
			if ( isset( $d[ $k ] ) && is_string( $d[ $k ] ) && '' !== trim( $d[ $k ] ) ) {
				$name = trim( $d[ $k ] );
				break;
			}
		}
		return array(
			'started_at'  => KZV_Util::ts( $d['created_at'] ?? '' ),
			'finished_at' => KZV_Util::ts( $d['finish_at'] ?? '' ),
			'name'        => $name, // название трансляции (Kozlov StreamVi ставит название концерта)
			'links'       => $links,
		);
	}

	/**
	 * Идёт ли эфир сейчас.
	 *
	 * @return array|WP_Error [ live => bool, broadcast_id => int, started => int, links => [] ]
	 */
	public static function status() {
		$res = self::get( '/method/broadcast/status', array( 'v' => '1' ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$d    = self::unwrap( $res );
		$kind = (string) ( $d['kind'] ?? '' );
		$st   = (string) ( $d['status'] ?? '' );
		$live = ( 'live' === $kind && in_array( $st, array( 'active', 'pause', 'lost' ), true ) );
		$out  = array(
			'live'         => $live,
			'signal'       => $live ? $st : '', // active | pause | lost
			'broadcast_id' => $live ? (int) ( $d['broadcast_id'] ?? 0 ) : 0,
			'started'      => $live ? KZV_Util::ts( $d['date_start'] ?? '' ) : 0,
			'links'        => array(),
		);
		if ( $live ) {
			foreach ( (array) ( $d['restreams'] ?? array() ) as $r ) {
				if ( is_array( $r ) ) {
					$out['links'][] = array(
						'type' => (string) ( $r['type'] ?? ( $r['channel_type'] ?? '' ) ),
						'name' => (string) ( $r['name'] ?? ( $r['channel_name'] ?? '' ) ),
						'url'  => (string) ( $r['video'] ?? ( $r['url'] ?? '' ) ),
					);
				}
			}
		}
		return $out;
	}

	/** Ссылки идущего эфира на площадках (url у каждой площадки). */
	public static function restreams( $broadcast_id ) {
		$res = self::get(
			'/method/broadcast/restreams',
			array(
				'broadcast_id' => (int) $broadcast_id,
				'v'            => '1',
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$d     = self::unwrap( $res );
		$links = array();
		foreach ( (array) ( $d['results'] ?? array() ) as $r ) {
			if ( is_array( $r ) ) {
				$links[] = array(
					'type' => (string) ( $r['channel_type'] ?? ( $r['type'] ?? '' ) ),
					'name' => (string) ( $r['channel_name'] ?? ( $r['name'] ?? '' ) ),
					'url'  => (string) ( $r['url'] ?? ( $r['video'] ?? '' ) ),
				);
			}
		}
		return $links;
	}

	/**
	 * Из ссылок площадок: видео в группе клуба VK и ID на YouTube.
	 *
	 * @return array [ vk_oid, vk_id, yt_id ]
	 */
	public static function pick( array $links, $vk_owner ) {
		$vk_any = null;
		$out    = array(
			'vk_oid' => 0,
			'vk_id'  => 0,
			'yt_id'  => '',
		);
		foreach ( $links as $l ) {
			$vk = KZV_Util::parse_vk( $l['url'] ?? '' );
			if ( $vk ) {
				if ( (int) $vk[0] === (int) $vk_owner ) {
					$out['vk_oid'] = $vk[0];
					$out['vk_id']  = $vk[1];
				} elseif ( null === $vk_any ) {
					$vk_any = $vk;
				}
				continue;
			}
			$yt = KZV_Util::parse_yt( $l['url'] ?? '' );
			if ( $yt && '' === $out['yt_id'] ) {
				$out['yt_id'] = $yt;
			}
		}
		// своей группы в трансляции не было: берём видео из другой группы VK, лишь бы играло
		if ( ! $out['vk_id'] && $vk_any ) {
			$out['vk_oid'] = $vk_any[0];
			$out['vk_id']  = $vk_any[1];
		}
		return $out;
	}

	/* ------------------------------------------------------------ транспорт */

	private static function unwrap( array $res ) {
		$d = $res['data'];
		if ( isset( $d['data'] ) && is_array( $d['data'] ) && ! isset( $d['results'] ) && ! isset( $d['kind'] ) && ! isset( $d['restreams'] ) ) {
			$d = $d['data'];
		}
		return is_array( $d ) ? $d : array();
	}

	/**
	 * @return array|WP_Error [ code, data ]
	 */
	private static function get( $path, array $query ) {
		if ( ! self::ready() ) {
			return new WP_Error( 'kzv_streamvi', 'StreamVi не подключён (плагин Kozlov StreamVi)', 401 );
		}
		$sec   = self::secrets();
		$query = array_merge(
			array(
				'language'   => 'ru',
				'project_id' => $sec['project'],
			),
			$query
		);
		$url   = self::BASE . $path . '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
		$res   = wp_remote_get(
			$url,
			array(
				'timeout'     => 12,
				'redirection' => 0,
				'headers'     => array(
					'Accept'              => 'application/json',
					'Authorization'       => 'Bearer ' . $sec['token'],
					'X-Client-Request-Id' => wp_generate_uuid4(),
				),
				'user-agent'  => 'KozlovVideo/' . KZV_VERSION . '; ' . home_url( '/' ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'kzv_net', 'нет связи со StreamVi: ' . $res->get_error_message(), 0 );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = (string) wp_remote_retrieve_body( $res );
		$data = json_decode( $body, true );
		if ( $code >= 200 && $code < 300 && is_array( $data ) ) {
			return array(
				'code' => $code,
				'data' => $data,
			);
		}
		$msg = '';
		if ( is_array( $data ) && isset( $data['error'] ) ) {
			$msg = is_array( $data['error'] ) ? (string) ( $data['error']['msg'] ?? wp_json_encode( $data['error'] ) ) : (string) $data['error'];
		}
		if ( '' === $msg ) {
			$msg = mb_substr( trim( wp_strip_all_tags( $body ) ), 0, 200 );
		}
		return new WP_Error( 429 === $code ? 'kzv_rate' : 'kzv_http', 'StreamVi ответил ' . $code . ': ' . $msg, $code );
	}
}
