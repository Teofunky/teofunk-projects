<?php
/**
 * Состояние эфира для блока «Сейчас в эфире». Один запрос к StreamVi на всех посетителей раз в 45 секунд.
 */

defined( 'ABSPATH' ) || exit;

final class KZV_Live {

	const CACHE = 'kzv_live_state';
	const TTL   = 45;

	/**
	 * @return array [ live, vk_oid, vk_id, yt_id, title, url, started, next => [ title, start, url ]|null, checked ]
	 */
	public static function state( $force = false ) {
		if ( ! $force ) {
			$c = get_transient( self::CACHE );
			if ( is_array( $c ) ) {
				return $c;
			}
		}
		$now   = time();
		$state = array(
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

		$around = KZV_Concerts::around( $now );
		if ( $around['next'] ) {
			$state['next'] = array(
				'title' => KZV_Util::clean_title( $around['next']['title'] ),
				'start' => (int) $around['next']['start'],
				'url'   => (string) $around['next']['url'],
			);
		}

		if ( KZV_StreamVi::ready() ) {
			$st = KZV_StreamVi::status();
			if ( ! is_wp_error( $st ) ) {
				$state['known'] = true;
				if ( $st['live'] ) {
					$state['live']    = true;
					$state['signal']  = (string) ( $st['signal'] ?? '' );
					$state['started'] = (int) $st['started'];
					$links            = $st['links'];
					if ( ! self::has_vk( $links ) && $st['broadcast_id'] ) {
						$more = KZV_StreamVi::restreams( $st['broadcast_id'] );
						if ( ! is_wp_error( $more ) ) {
							$links = array_merge( $links, $more );
						}
						if ( ! self::has_vk( $links ) ) {
							$one = KZV_StreamVi::broadcast( $st['broadcast_id'] );
							if ( ! is_wp_error( $one ) ) {
								$links = array_merge( $links, $one['links'] );
							}
						}
					}
					$pick            = KZV_StreamVi::pick( $links, (int) KZV_Settings::get( 'vk_owner' ) );
					$state['vk_oid'] = (int) $pick['vk_oid'];
					$state['vk_id']  = (int) $pick['vk_id'];
					$state['yt_id']  = (string) $pick['yt_id'];
					if ( $around['current'] ) {
						$state['title'] = KZV_Util::clean_title( $around['current']['title'] );
						$state['url']   = (string) $around['current']['url'];
					}
				}
			} else {
				KZV_Settings::note_error( 'статус эфира: ' . $st->get_error_message() );
			}
		}
		set_transient( self::CACHE, $state, self::TTL );
		return $state;
	}

	private static function has_vk( array $links ) {
		foreach ( $links as $l ) {
			if ( KZV_Util::parse_vk( $l['url'] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}
}
