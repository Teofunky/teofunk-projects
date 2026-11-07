<?php
/**
 * REST только для чтения: /kozlov-video/v1/live (состояние эфира) и /kozlov-video/v1/videos (карточки архива).
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
				'args'                => array(
					'view' => array(
						'type'    => 'string',
						'enum'    => array( 'live', 'bar' ),
						'default' => 'live',
					),
				),
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
				),
			)
		);
	}

	/** Пока плагин выключен, данные отдаются только редакторам. */
	public static function allowed() {
		return 'all' === KZV_Settings::get( 'mode' ) || current_user_can( 'edit_pages' );
	}

	public static function live( WP_REST_Request $r ) {
		try {
			$s    = KZV_Live::state();
			$view = 'bar' === $r->get_param( 'view' ) ? 'bar' : 'live';
			$res  = new WP_REST_Response(
				array(
					'live' => (bool) $s['live'],
					'html' => KZV_Render::live( $s, $view ),
				)
			);
		} catch ( \Throwable $e ) {
			kzv_log_exception( $e );
			$res = new WP_REST_Response( array( 'live' => false, 'html' => null ), 200 );
		}
		$res->header( 'Cache-Control', 'no-store, max-age=0' );
		return $res;
	}

	public static function videos( WP_REST_Request $r ) {
		try {
			$per    = (int) KZV_Settings::get( 'per_page' );
			$offset = max( 0, (int) $r->get_param( 'offset' ) );
			$q      = mb_substr( sanitize_text_field( (string) $r->get_param( 'q' ) ), 0, 60 );
			$data   = KZV_Store::query( $offset, $per, $q );
			$items  = array();
			foreach ( $data['items'] as $row ) {
				$items[] = KZV_Render::present( $row );
			}
			$res = new WP_REST_Response(
				array(
					'html'  => KZV_Render::cards( $items ),
					'count' => count( $items ),
					'total' => (int) $data['total'],
					'label' => KZV_Render::count_label( (int) $data['total'] ),
					'next'  => $offset + count( $items ),
				)
			);
			$res->header( 'Cache-Control', 'public, max-age=300' );
			return $res;
		} catch ( \Throwable $e ) {
			kzv_log_exception( $e );
			return new WP_REST_Response( array( 'html' => '', 'count' => 0, 'total' => 0, 'label' => '', 'next' => 0 ), 200 );
		}
	}
}
