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
		$title = trim( (string) $row['title_custom'] ) !== '' ? (string) $row['title_custom'] : (string) $row['title'];
		$title = '' !== trim( $title ) ? KZV_Util::clean_title( $title ) : 'Концерт на Главной сцене';
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

	public static function archive_url( $id = 0 ) {
		$page = (int) KZV_Settings::get( 'archive_page' );
		$base = $page ? (string) get_permalink( $page ) : home_url( '/videoarxiv/' );
		return $id ? add_query_arg( 'v', (int) $id, $base ) : $base;
	}

	public static function live_url() {
		$page = (int) KZV_Settings::get( 'live_page' );
		return $page ? (string) get_permalink( $page ) : home_url( '/live/' );
	}

	/** То, что нужно скрипту для переключения плеера по карточке. */
	private static function client_item( array $v ) {
		return array(
			'id'      => $v['id'],
			'title'   => $v['title'],
			'meta'    => 'Запись трансляции · ' . $v['date'] . ' · ' . $v['dur'],
			'img'     => $v['img'],
			'vk'      => $v['vk'] ? KZV_Util::vk_embed( $v['vk'][0], $v['vk'][1], true ) : '',
			'vkWatch' => $v['vk'] ? KZV_Util::vk_watch( $v['vk'][0], $v['vk'][1] ) : '',
			'yt'      => '' !== $v['yt'] ? KZV_Util::yt_watch( $v['yt'] ) : '',
			'concert' => $v['concert'],
			'up'      => $v['up'],
			'href'    => $v['href'],
		);
	}

	/* ------------------------------------------------------------ части */

	/** Плеер с обложкой: iframe VK появляется только по нажатию (страница не грузит плеер заранее). */
	public static function player( ?array $v = null, $kicker = '' ) {
		ob_start();
		$embed = ( $v && $v['vk'] ) ? KZV_Util::vk_embed( $v['vk'][0], $v['vk'][1], true ) : '';
		?>
		<section class="kzv-stage" id="kzv-player" aria-label="Плеер">
			<div class="kzv-frame" data-kzv-frame data-src="<?php echo esc_attr( $embed ); ?>">
				<?php if ( $v ) : ?>
					<button type="button" class="kzv-facade" data-kzv-play aria-label="<?php echo esc_attr( 'Смотреть: ' . $v['title'] ); ?>">
						<?php if ( '' !== $v['img'] ) : ?>
							<img src="<?php echo esc_url( $v['img'] ); ?>" alt="" decoding="async">
						<?php endif; ?>
						<span class="kzv-play" aria-hidden="true"></span>
					</button>
				<?php else : ?>
					<div class="kzv-empty">Записи появятся здесь после первой синхронизации.</div>
				<?php endif; ?>
			</div>
			<?php if ( $v ) : ?>
				<div class="kzv-info">
					<p class="kzv-kicker" data-kzv-meta><?php echo esc_html( '' !== $kicker ? $kicker : 'Запись трансляции · ' . $v['date'] . ' · ' . $v['dur'] ); ?></p>
					<h2 class="kzv-title" data-kzv-title><?php echo esc_html( $v['title'] ); ?></h2>
					<p class="kzv-actions" data-kzv-actions><?php echo self::actions( self::client_item( $v ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
				</div>
			<?php endif; ?>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/** Кнопки под плеером: концерт, снова в клубе, площадки. Принимает client_item. */
	public static function actions( array $c ) {
		$out = array();
		if ( ! empty( $c['up'] ) ) {
			$out[] = '<a class="kzv-btn" href="' . esc_url( $c['up']['url'] ) . '">' . esc_html( $c['up']['label'] ) . '</a>';
		}
		if ( '' !== $c['concert'] ) {
			$out[] = '<a class="kzv-btn kzv-btn--ghost" href="' . esc_url( $c['concert'] ) . '">О концерте</a>';
		}
		if ( '' !== $c['vkWatch'] ) {
			$out[] = '<a class="kzv-link" href="' . esc_url( $c['vkWatch'] ) . '" target="_blank" rel="noopener">VK Видео</a>';
		}
		if ( '' !== $c['yt'] ) {
			$out[] = '<a class="kzv-link" href="' . esc_url( $c['yt'] ) . '" target="_blank" rel="noopener">YouTube</a>';
		}
		return implode( ' ', $out );
	}

	public static function cards( array $items ) {
		$out = '';
		foreach ( $items as $v ) {
			$out .= '<li><a class="kzv-card" href="' . esc_url( $v['href'] ) . '" data-kzv-id="' . (int) $v['id'] . '" data-kzv-card="' . esc_attr( wp_json_encode( self::client_item( $v ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) . '">'
				. '<span class="kzv-thumb">' . ( '' !== $v['img'] ? '<img src="' . esc_url( $v['img'] ) . '" alt="" loading="lazy" decoding="async">' : '' ) . '<span class="kzv-dur">' . esc_html( $v['dur'] ) . '</span></span>'
				. '<span class="kzv-card-body"><span class="kzv-card-date">' . esc_html( $v['date'] ) . '</span>'
				. '<span class="kzv-card-title">' . esc_html( $v['title'] ) . '</span></span>'
				. '</a></li>';
		}
		return $out;
	}

	/**
	 * Блок эфира.
	 *
	 * @param array  $s    KZV_Live::state().
	 * @param string $view live (большой блок на странице «Live») | bar (строка над архивом).
	 */
	public static function live( array $s, $view ) {
		$tz  = KZV_Settings::tz();
		$now = time();
		if ( $s['live'] ) {
			$title = '' !== $s['title'] ? $s['title'] : 'Прямая трансляция с Главной сцены';
			$embed = $s['vk_id'] ? KZV_Util::vk_embed( $s['vk_oid'], $s['vk_id'], true ) : '';
			$watch = $s['vk_id'] ? KZV_Util::vk_watch( $s['vk_oid'], $s['vk_id'] ) : (string) KZV_Settings::get( 'vk_public' );
			if ( 'bar' === $view ) {
				return '<div class="kzv-bar kzv-bar--on"><span class="kzv-badge">В эфире</span> <span class="kzv-bar-title">' . esc_html( $title ) . '</span> '
					. ( '' !== $embed
						? '<button type="button" class="kzv-btn" data-kzv-live-play="' . esc_attr( $embed ) . '" data-kzv-live-title="' . esc_attr( $title ) . '">Смотреть эфир</button>'
						: '<a class="kzv-btn" href="' . esc_url( $watch ) . '" target="_blank" rel="noopener">Смотреть в VK</a>' )
					. '</div>';
			}
			ob_start();
			?>
			<section class="kzv-onair" aria-label="Прямой эфир">
				<p class="kzv-kicker"><span class="kzv-badge">В эфире</span> Главная сцена</p>
				<h2 class="kzv-title"><?php echo esc_html( $title ); ?></h2>
				<div class="kzv-frame kzv-frame--live">
					<?php if ( '' !== $embed ) : ?>
						<iframe src="<?php echo esc_url( $embed ); ?>" title="<?php echo esc_attr( $title ); ?>" allow="autoplay; encrypted-media; fullscreen; picture-in-picture; screen-wake-lock" allowfullscreen loading="lazy"></iframe>
					<?php else : ?>
						<div class="kzv-empty">Эфир идёт. Плеер появится через минуту, а пока смотрите <a href="<?php echo esc_url( $watch ); ?>" target="_blank" rel="noopener">в VK Видео</a>.</div>
					<?php endif; ?>
				</div>
				<p class="kzv-actions">
					<?php if ( '' !== $s['url'] ) : ?>
						<a class="kzv-btn kzv-btn--ghost" href="<?php echo esc_url( $s['url'] ); ?>">О концерте</a>
					<?php endif; ?>
					<a class="kzv-link" href="<?php echo esc_url( $watch ); ?>" target="_blank" rel="noopener">Открыть в VK Видео</a>
					<?php if ( '' !== $s['yt_id'] ) : ?>
						<a class="kzv-link" href="<?php echo esc_url( KZV_Util::yt_watch( $s['yt_id'] ) ); ?>" target="_blank" rel="noopener">YouTube</a>
					<?php endif; ?>
				</p>
			</section>
			<?php
			return (string) ob_get_clean();
		}

		$next = $s['next'];
		if ( 'bar' === $view ) {
			if ( ! $next ) {
				return '';
			}
			return '<div class="kzv-bar"><span class="kzv-bar-label">Следующая трансляция ' . esc_html( KZV_Util::when_label( $next['start'], $now, $tz ) ) . ':</span> <a class="kzv-bar-title" href="' . esc_url( self::live_url() ) . '">'
				. esc_html( $next['title'] ) . '</a></div>';
		}
		ob_start();
		?>
		<section class="kzv-offair" aria-label="Расписание трансляций">
			<p class="kzv-kicker">Сейчас эфира нет</p>
			<?php if ( $next ) : ?>
				<h2 class="kzv-title">Следующая трансляция: <?php echo esc_html( $next['title'] ); ?></h2>
				<p class="kzv-when"><?php echo esc_html( KZV_Util::ucfirst( KZV_Util::when_label( $next['start'], $now, $tz ) ) ); ?>, Главная сцена. Плеер включится здесь сам, страницу можно не обновлять.</p>
				<?php if ( '' !== $next['url'] ) : ?>
					<p class="kzv-actions"><a class="kzv-btn" href="<?php echo esc_url( $next['url'] ); ?>">О концерте и билеты</a></p>
				<?php endif; ?>
			<?php else : ?>
				<h2 class="kzv-title">Трансляции идут с Главной сцены</h2>
				<p class="kzv-when">Плеер включится здесь сам, когда начнётся эфир.</p>
			<?php endif; ?>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/* ------------------------------------------------------------ страницы */

	public static function page_live() {
		$s      = KZV_Live::state();
		$latest = $s['live'] ? null : KZV_Store::latest();
		$v      = $latest ? self::present( $latest ) : null;
		ob_start();
		?>
		<div class="kzv kzv--live" data-kzv="<?php echo esc_attr( wp_json_encode( self::config( 'live', $s ) ) ); ?>">
			<div data-kzv-live><?php echo self::live( $s, 'live' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
			<?php if ( $v ) : ?>
				<div data-kzv-last>
					<h3 class="kzv-h3">Последняя трансляция</h3>
					<?php echo self::player( $v ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				</div>
			<?php endif; ?>
			<?php echo self::follow(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	public static function page_archive() {
		$per   = (int) KZV_Settings::get( 'per_page' );
		$q     = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$q     = mb_substr( $q, 0, 60 );
		$res   = KZV_Store::query( 0, $per, $q );
		$items = array();
		foreach ( $res['items'] as $row ) {
			$items[] = self::present( $row );
		}
		$sel = null;
		$vid = isset( $_GET['v'] ) ? absint( $_GET['v'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $vid ) {
			$row = KZV_Store::get( $vid );
			if ( $row && ! (int) $row['hidden'] && (int) $row['vk_id'] ) {
				$sel = self::present( $row );
			}
		}
		if ( ! $sel && $items ) {
			$sel = $items[0];
		}
		$s = KZV_Live::state();
		ob_start();
		?>
		<div class="kzv kzv--archive" data-kzv="<?php echo esc_attr( wp_json_encode( self::config( 'archive', $s, array( 'total' => $res['total'], 'q' => $q ) ) ) ); ?>">
			<div data-kzv-live><?php echo self::live( $s, 'bar' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
			<?php echo self::player( $sel ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<form class="kzv-search" role="search" action="<?php echo esc_url( self::archive_url() ); ?>" method="get" data-kzv-search>
				<label class="kzv-sr" for="kzv-q">Поиск по записям концертов</label>
				<input id="kzv-q" name="q" type="search" value="<?php echo esc_attr( $q ); ?>" placeholder="Найти артиста или концерт" maxlength="60" autocomplete="off">
				<span class="kzv-count" data-kzv-count aria-live="polite"><?php echo esc_html( self::count_label( $res['total'] ) ); ?></span>
			</form>
			<ul class="kzv-grid" data-kzv-grid><?php echo self::cards( $items ); // phpcs:ignore WordPress.Security.EscapeOutput ?></ul>
			<p class="kzv-more-wrap"<?php echo ( $res['total'] > count( $items ) ) ? '' : ' hidden'; ?> data-kzv-more-wrap>
				<button type="button" class="kzv-btn kzv-btn--ghost kzv-more" data-kzv-more>Показать ещё</button>
			</p>
			<?php echo self::follow( true ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<?php echo self::schema( $items ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	public static function count_label( $n ) {
		$n   = (int) $n;
		$m10 = $n % 10;
		$m100 = $n % 100;
		$word = ( 1 === $m10 && 11 !== $m100 ) ? 'запись' : ( ( $m10 >= 2 && $m10 <= 4 && ( $m100 < 12 || $m100 > 14 ) ) ? 'записи' : 'записей' );
		return number_format( $n, 0, ',', ' ' ) . ' ' . $word;
	}

	private static function follow( $archive = false ) {
		$s   = KZV_Settings::get();
		$out = '<p class="kzv-follow">' . ( $archive ? 'Записи до апреля 2023 года и другие видео клуба: ' : 'Трансляции идут и в соцсетях клуба: ' )
			. '<a href="' . esc_url( $s['vk_public'] ) . '" target="_blank" rel="noopener">VK Видео</a>, '
			. '<a href="' . esc_url( $s['yt_public'] ) . '" target="_blank" rel="noopener">YouTube</a>'
			. ( $archive ? '' : ', <a href="' . esc_url( $s['tg_public'] ) . '" target="_blank" rel="noopener">Telegram</a>' ) . '.</p>';
		if ( ! $archive ) {
			$out .= '<p class="kzv-actions"><a class="kzv-btn kzv-btn--ghost" href="' . esc_url( self::archive_url() ) . '">Все записи концертов</a></p>';
		}
		return $out;
	}

	private static function config( $view, array $s, array $extra = array() ) {
		return array_merge(
			array(
				'view'  => $view,
				'rest'  => esc_url_raw( rest_url( 'kozlov-video/v1/' ) ),
				'live'  => (bool) $s['live'],
				'per'   => (int) KZV_Settings::get( 'per_page' ),
				'base'  => self::archive_url(),
				// в режиме «только редакторам» REST узнаёт редактора по nonce; для всех страница кэшируется, nonce не нужен
				'nonce' => ( 'all' !== KZV_Settings::get( 'mode' ) && is_user_logged_in() ) ? wp_create_nonce( 'wp_rest' ) : '',
			),
			$extra
		);
	}

	/** Разметка видео для поисковиков: список VideoObject (только записи с обложкой). */
	private static function schema( array $items ) {
		$tz   = KZV_Settings::tz();
		$list = array();
		$pos  = 0;
		foreach ( $items as $v ) {
			if ( '' === $v['img'] || ! $v['vk'] ) {
				continue;
			}
			$list[] = array(
				'@type'    => 'ListItem',
				'position' => ++$pos,
				'item'     => array(
					'@type'        => 'VideoObject',
					'name'         => $v['title'],
					'description'  => 'Запись концерта «' . $v['title'] . '» на Главной сцене Клуба Алексея Козлова, ' . $v['date'] . '.',
					'thumbnailUrl' => $v['img'],
					'uploadDate'   => ( new DateTimeImmutable( '@' . $v['started'] ) )->setTimezone( $tz )->format( 'c' ),
					'duration'     => KZV_Util::duration_iso( $v['duration'] ),
					'embedUrl'     => KZV_Util::vk_embed( $v['vk'][0], $v['vk'][1] ),
					'url'          => $v['href'],
				),
			);
		}
		if ( ! $list ) {
			return '';
		}
		$doc = array(
			'@context'        => 'https://schema.org',
			'@type'           => 'ItemList',
			'name'            => 'Записи трансляций Клуба Алексея Козлова',
			'itemListElement' => $list,
		);
		return '<script type="application/ld+json">' . wp_json_encode( $doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>';
	}
}
