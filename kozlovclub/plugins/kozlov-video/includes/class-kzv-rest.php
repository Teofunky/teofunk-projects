<?php
/**
 * REST только для чтения:
 *   /kozlov-video/v1/live    состояние эфира для Kozlov TV (шапка, программа, плеер эфира);
 *   /kozlov-video/v1/videos  карточки записей (поиск, жанр, «Показать ещё»).
 * Ответы содержат готовый HTML тех же блоков, что и сервер при первой загрузке.
 */

defined( 'ABSPATH' ) || exit;

final class KZV_Rest {

	const NS = 'kozlov-video/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route(
			self::NS,
			'/live',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( __CLASS__, 'allowed' ),
				'callback'            => array( __CLASS__, 'live' ),
			)
		);
		register_rest_route(
			self::NS,
			'/videos',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( __CLASS__, 'allowed' ),
				'callback'            => array( __CLASS__, 'videos' ),
				'args'                => array(
					'offset' => array(
						'type'    => 'integer',
						'minimum' => 0,
						'maximum' => 100000,
						'default' => 0,
					),
					'q'      => array(
						'type'      => 'string',
						'default'   => '',
						'maxLength' => 60,
					),
					'genre'  => array(
						'type'    => 'string',
						'default' => '',
						'enum'    => array_merge( array( '' ), array_keys( KZV_TV::GENRES ) ),
					),
				),
			)
		);
	}

	/** Пока Kozlov TV не включён для всех, данные отдаются только редакторам. */
	public static function allowed() {
		return 'all' === KZV_Settings::get( 'mode' ) || current_user_can( 'edit_pages' );
	}

	public static function live( WP_REST_Request $r ) {
		try {
			$res = new WP_REST_Response( KZV_TV::live_payload() );
			KZV_Sync::maybe_run_later();
		} catch ( \Throwable $e ) {
			kzv_log_exception( $e );
			$res = new WP_REST_Response( array( 'live' => null ), 200 ); // скрипт страницы ничего не меняет
		}
		$res->header( 'Cache-Control', 'no-store, max-age=0' );
		// срок задаём явно: иначе правило сервера для JSON (mod_expires на Beget) допишет год хранения
		$res->header( 'Expires', 'Thu, 01 Jan 1970 00:00:01 GMT' );
		return $res;
	}

	public static function videos( WP_REST_Request $r ) {
		try {
			$per    = (int) KZV_Settings::get( 'per_page' );
			$offset = max( 0, (int) $r->get_param( 'offset' ) );
			$q      = mb_substr( sanitize_text_field( (string) $r->get_param( 'q' ) ), 0, 60 );
			$genre  = sanitize_key( (string) $r->get_param( 'genre' ) );
			$data   = KZV_Store::query( $offset, $per, $q, KZV_TV::genre_codes( $genre ) );
			$now    = time();
			$items  = array();
			foreach ( $data['items'] as $row ) {
				$items[] = KZV_TV::item( $row, $now );
			}
			$res = new WP_REST_Response(
				array(
					'html'  => KZV_TV::cards( $items ),
					'count' => count( $items ),
					'total' => (int) $data['total'],
					'label' => KZV_Render::count_label( (int) $data['total'] ),
					'next'  => $offset + count( $items ),
				)
			);
			// короткий кэш: скрытая в админке запись пропадает из ответов не позже чем через минуту
			$res->header( 'Cache-Control', 'public, max-age=60' );
			$res->header( 'Expires', gmdate( 'D, d M Y H:i:s', time() + 60 ) . ' GMT' );
			return $res;
		} catch ( \Throwable $e ) {
			kzv_log_exception( $e );
			return new WP_REST_Response( array( 'message' => 'error' ), 500 ); // скрипт покажет «Повторить»
		}
	}
}
