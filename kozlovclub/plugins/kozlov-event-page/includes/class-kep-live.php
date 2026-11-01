<?php
/**
 * Свежая цена «от» по свободным билетам Ticketscloud.
 *
 * Страницы концертов для гостей лежат в кэше W3 Total Cache до 12 часов, поэтому в HTML
 * остаётся цена из анонса. После загрузки страницы kep.js спрашивает сайт:
 * GET /wp-json/kozlov-event-page/v1/price/<ID записи>?event=<ID мероприятия Ticketscloud>.
 * Сайт берёт цены у Ticketscloud тем же открытым ключом виджета, который тема пишет
 * в шапке (window.ticketscloudid), и отдаёт самую низкую цену среди свободных билетов.
 * Ответ хранится от 5 минут до часа (чем меньше мест по самой низкой цене, тем чаще
 * проверка), страницу этот запрос не задерживает.
 *
 * Любой сбой (ключ ещё не найден, Ticketscloud не ответил, другой формат ответа):
 * ответ без цены, на странице остаётся цена из анонса. Анонсы, афиша и Ticketscloud
 * не меняются: плагин только читает.
 *
 * Выключить без правки кода: add_filter( 'kep_live_price', '__return_false' );
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KEP_Live {

	const NS        = 'kozlov-event-page/v1';
	const OPT_TOKEN = 'kep_tc_token';
	const META_TC   = 'ticketscloud_link';
	const API       = 'https://ticketscloud.com/v1/services/widget';
	const TTL       = 300; // ответ браузеру и самый короткий срок хранения цен, секунд
	const TTL_FAIL  = 120; // после сбоя Ticketscloud спрашивается не чаще раза в 2 минуты
	const TIMEOUT   = 4;   // ожидание ответа Ticketscloud, секунд

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * Включена ли свежая цена.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (bool) apply_filters( 'kep_live_price', true );
	}

	/**
	 * Запомнить открытый ключ виджета из готовой страницы: тема пишет его в шапке
	 * строкой window.ticketscloudid='…'. Пишется в базу, только если ключ изменился.
	 *
	 * @param string $html Готовая страница.
	 */
	public static function remember_token( $html ) {
		if ( ! is_string( $html ) || false === strpos( $html, 'ticketscloudid' ) ) {
			return;
		}
		if ( ! preg_match( '/ticketscloudid\s*=\s*[\'"]([A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+)[\'"]/', $html, $m ) ) {
			return;
		}
		if ( (string) get_option( self::OPT_TOKEN, '' ) !== $m[1] ) {
			update_option( self::OPT_TOKEN, $m[1], false );
		}
	}

	public static function routes() {
		register_rest_route(
			self::NS,
			'/price/(?P<post>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_price' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Ответ для kep.js: { min: число|null, why: причина }. Причина без секретов, для проверки:
	 * ok, off (выключено фильтром), no_event (нет записи или ID мероприятия), mismatch (кнопка и поле
	 * записи расходятся), no_token (ключ виджета ещё не запомнен), busy (запрос уже идёт),
	 * http_<код> или http_error (Ticketscloud не ответил), bad_json, closed (продажа закрыта),
	 * no_price (нет свободных билетов с ценой), error (сбой плагина).
	 *
	 * @param WP_REST_Request $request Запрос.
	 * @return WP_REST_Response
	 */
	public static function rest_price( $request ) {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // ответ не должен лечь в кэш страниц на 12 часов
		}
		$out = array(
			'min' => null,
			'why' => 'off',
			'v'   => KEP_VERSION,
		);
		try {
			if ( self::enabled() ) {
				$event = self::event_id( (int) $request['post'] );
				$asked = strtolower( (string) $request->get_param( 'event' ) );
				if ( '' === $event ) {
					$out['why'] = 'no_event';
				} elseif ( '' !== $asked && $asked !== $event ) {
					$out['why'] = 'mismatch'; // кнопка на странице и поле записи указывают на разные мероприятия
				} else {
					$data       = self::lookup( $event );
					$out['why'] = isset( $data['why'] ) ? (string) $data['why'] : 'error';
					if ( null !== $data['min'] ) {
						$out['min'] = (int) $data['min'];
					}
				}
			}
		} catch ( Exception $e ) {
			$out['min'] = null;
			$out['why'] = 'error';
		} catch ( Error $e ) {
			$out['min'] = null;
			$out['why'] = 'error';
		}
		$res = rest_ensure_response( $out );
		$res->header( 'Cache-Control', 'public, max-age=' . self::TTL );
		return $res;
	}

	/**
	 * ID мероприятия Ticketscloud опубликованного концерта: поле «ticketscloud_link»,
	 * иначе кнопка tc-event в тексте анонса. Пустая строка, если не найдено.
	 *
	 * @param int $post_id ID записи.
	 * @return string
	 */
	public static function event_id( $post_id ) {
		$post = $post_id > 0 ? get_post( $post_id ) : null;
		if ( ! $post || KEP_POST_TYPE !== $post->post_type || 'publish' !== get_post_status( $post ) || '' !== (string) $post->post_password ) {
			return '';
		}
		$raw = (string) get_post_meta( $post_id, self::META_TC, true );
		if ( preg_match( '/(?<![0-9a-f])([0-9a-f]{24})(?![0-9a-f])/i', $raw, $m ) ) {
			return strtolower( $m[1] );
		}
		if ( preg_match( '/tc-event\s*=\s*["\']([0-9a-f]{24})["\']/i', (string) $post->post_content, $m ) ) {
			return strtolower( $m[1] );
		}
		return '';
	}

	/**
	 * Цены мероприятия из кэша или от Ticketscloud.
	 *
	 * @param string $event ID мероприятия Ticketscloud.
	 * @return array array( 'min' => int|null, 'vacant' => int, 'why' => причина ).
	 */
	public static function lookup( $event ) {
		$key  = 'kep_live_' . $event;
		$data = get_transient( $key );
		if ( is_array( $data ) ) {
			return $data;
		}
		$token = (string) get_option( self::OPT_TOKEN, '' );
		if ( '' === $token ) {
			// Ключ появится после первого показа новой страницы концерта. Не кэшируется.
			return array(
				'min'    => null,
				'vacant' => 0,
				'why'    => 'no_token',
			);
		}
		// Пока один запрос ждёт Ticketscloud, остальные не дублируют его.
		$lock = 'kep_live_lock_' . $event;
		if ( get_transient( $lock ) ) {
			return array(
				'min'    => null,
				'vacant' => 0,
				'why'    => 'busy',
			);
		}
		set_transient( $lock, 1, 30 );
		$data = self::fetch( $event, $token );
		set_transient( $key, $data, self::ttl( $data ) );
		delete_transient( $lock );
		return $data;
	}

	/**
	 * Сколько хранить ответ. Самая низкая цена меняется, только когда раскупят её места,
	 * поэтому чем их больше, тем реже нужно спрашивать Ticketscloud:
	 * 20 и больше мест по самой низкой цене: час; 5–19: 15 минут; меньше 5: 5 минут.
	 * Свободных билетов нет или продажа закрыта: час. Сбой: 2 минуты.
	 *
	 * @param array $data Итог summarize().
	 * @return int Секунды.
	 */
	public static function ttl( $data ) {
		if ( ! empty( $data['fail'] ) ) {
			return self::TTL_FAIL;
		}
		if ( null === $data['min'] ) {
			return HOUR_IN_SECONDS;
		}
		$left = isset( $data['at_min'] ) ? (int) $data['at_min'] : 0;
		if ( $left >= 20 ) {
			return HOUR_IN_SECONDS;
		}
		return $left >= 5 ? 15 * MINUTE_IN_SECONDS : self::TTL;
	}

	/**
	 * Спросить Ticketscloud. Тот же запрос делает виджет при открытии окна покупки.
	 *
	 * @param string $event ID мероприятия.
	 * @param string $token Открытый ключ виджета.
	 * @return array
	 */
	public static function fetch( $event, $token ) {
		$res = wp_remote_post(
			self::API,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'token ' . $token,
				),
				'body'    => wp_json_encode( array( 'event' => $event ) ),
			)
		);
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			$why = is_wp_error( $res ) ? 'http_error: ' . substr( (string) $res->get_error_message(), 0, 80 ) : 'http_' . (int) wp_remote_retrieve_response_code( $res );
			return array(
				'min'    => null,
				'vacant' => 0,
				'fail'   => 1,
				'why'    => $why,
			);
		}
		return self::summarize( json_decode( (string) wp_remote_retrieve_body( $res ), true ), time() );
	}

	/**
	 * Самая низкая цена среди свободных билетов, которые продаются сейчас.
	 * Формат Ticketscloud: sets = { id: { amount_vacant, prices: [ { start, finish, nominal, extra, full } ] } },
	 * время цен в UTC, цена за один билет.
	 *
	 * @param mixed $json Ответ Ticketscloud.
	 * @param int   $now  Сейчас, Unix time.
	 * @return array
	 */
	public static function summarize( $json, $now ) {
		$out = array(
			'min'    => null,
			'vacant' => 0,
			'at_min' => 0, // свободных мест по самой низкой цене
		);
		if ( ! is_array( $json ) || ! isset( $json['sets'] ) || ! is_array( $json['sets'] ) ) {
			$out['fail'] = 1;
			$out['why']  = 'bad_json';
			return $out;
		}
		if ( ! empty( $json['closed_sales'] ) ) {
			$out['why'] = 'closed';
			return $out; // продажа закрыта: свежей цены нет, на странице цена из анонса
		}
		foreach ( $json['sets'] as $set ) {
			if ( ! is_array( $set ) || empty( $set['prices'] ) || ! is_array( $set['prices'] ) ) {
				continue;
			}
			$vacant = isset( $set['amount_vacant'] ) ? (int) $set['amount_vacant'] : 0;
			if ( $vacant <= 0 ) {
				continue;
			}
			$price = self::current_price( $set['prices'], $now );
			if ( null === $price || $price <= 0 ) {
				continue; // пригласительные и категории без цены не считаются
			}
			$out['vacant'] += $vacant;
			if ( null === $out['min'] || $price < $out['min'] ) {
				$out['min']    = $price;
				$out['at_min'] = $vacant;
			} elseif ( $price === $out['min'] ) {
				$out['at_min'] += $vacant;
			}
		}
		$out['why'] = null === $out['min'] ? 'no_price' : 'ok';
		return $out;
	}

	/**
	 * Номинал билета, который действует сейчас. Берётся nominal, без сервисного сбора (extra):
	 * так же пишут цену редакторы, и под ценой стоит подпись «без сервисного сбора».
	 *
	 * @param array $prices Цены категории.
	 * @param int   $now    Сейчас, Unix time.
	 * @return int|null
	 */
	private static function current_price( $prices, $now ) {
		foreach ( $prices as $p ) {
			if ( ! is_array( $p ) || ! isset( $p['nominal'] ) || ! is_numeric( $p['nominal'] ) ) {
				continue;
			}
			$start  = ! empty( $p['start'] ) ? strtotime( $p['start'] . ' UTC' ) : false;
			$finish = ! empty( $p['finish'] ) ? strtotime( $p['finish'] . ' UTC' ) : false;
			if ( ( false === $start || $start <= $now ) && ( false === $finish || $now < $finish ) ) {
				return (int) round( (float) $p['nominal'] );
			}
		}
		return null;
	}
}
