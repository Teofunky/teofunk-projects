<?php
/**
 * Разметка страниц. Всё экранируется здесь; REST отдаёт тот же HTML, что и сервер при первой загрузке.
 */

defined( 'ABSPATH' ) || exit;

final class KZV_Render {

	/* ------------------------------------------------------------ данные записи */

	/**
	 * Запись из таблицы в вид для страницы.
	 *
	 * @return array
	 */
	public static function present( array $row, $now = null ) {
		$now   = null === $now ? time() : (int) $now;
		$tz    = KZV_Settings::tz();
		$pid   = (int) $row['post_id'];
		// своё название из админки, затем концерт из афиши, затем название трансляции в StreamVi
		$title = '';
		foreach ( array( 'title_custom', 'title', 'sv_title' ) as $k ) {
			if ( '' !== trim( (string) ( $row[ $k ] ?? '' ) ) ) {
				$title = (string) $row[ $k ];
				break;
			}
		}
		$title = '' !== $title ? KZV_Util::clean_title( $title ) : 'Концерт на Главной сцене';
		$img   = '';
		$url   = '';
		if ( $pid > 0 && 'publish' === get_post_status( $pid ) ) {
			$url = (string) get_permalink( $pid );
			$t   = get_the_post_thumbnail_url( $pid, 'medium_large' );
			$img = $t ? (string) $t : '';
		}
		$yt = (string) $row['yt_id'];
		if ( '' === $img && '' !== $yt ) {
			$img = KZV_Util::yt_thumb( $yt );
		}
		$up = null;
		$e  = KZV_Concerts::upcoming_same( $title, $now );
		if ( $e && (int) $e['post_id'] !== $pid ) {
			$up = array(
				'label' => 'Снова в клубе ' . KZV_Util::when_label( (int) $e['start'], $now, $tz ),
				'url'   => (string) $e['url'],
			);
		}
		$id = (int) $row['broadcast_id'];
		return array(
			'id'       => $id,
			'title'    => $title,
			'date'     => KZV_Util::date_label( (int) $row['started_at'], $tz ),
			'dur'      => KZV_Util::duration_label( (int) $row['duration'] ),
			'started'  => (int) $row['started_at'],
			'duration' => (int) $row['duration'],
			'img'      => $img,
			'vk'       => (int) $row['vk_id'] ? array( (int) $row['vk_oid'], (int) $row['vk_id'] ) : null,
			'yt'       => $yt,
			'concert'  => $url,
			'up'       => $up,
			'href'     => self::archive_url( $id ),
		);
	}

	/** Адрес Kozlov TV: страница /tv/, а пока её нет, страница архива. С $id: ссылка на запись. */
	public static function archive_url( $id = 0 ) {
		$page = (int) KZV_Settings::get( 'tv_page' );
		if ( ! $page ) {
			$page = (int) KZV_Settings::get( 'archive_page' );
		}
		$base = $page ? (string) get_permalink( $page ) : home_url( '/videoarxiv/' );
		return $id ? add_query_arg( 'v', (int) $id, $base ) : $base;
	}

	public static function live_url() {
		$page = (int) KZV_Settings::get( 'live_page' );
		return $page ? (string) get_permalink( $page ) : home_url( '/live/' );
	}

	public static function count_label( $n ) {
		$n   = (int) $n;
		$m10 = $n % 10;
		$m100 = $n % 100;
		$word = ( 1 === $m10 && 11 !== $m100 ) ? 'запись' : ( ( $m10 >= 2 && $m10 <= 4 && ( $m100 < 12 || $m100 > 14 ) ) ? 'записи' : 'записей' );
		return number_format( $n, 0, ',', ' ' ) . ' ' . $word;
	}
}
