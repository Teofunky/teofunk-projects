<?php
/**
 * Kozlov TV: одна страница для эфира и записей.
 * Шапка с лампой «В эфире», экран, программа эфиров главной сцены, библиотека записей с поиском и жанрами.
 * Весь HTML экранируется здесь; REST отдаёт те же куски разметки.
 */

defined( 'ABSPATH' ) || exit;

final class KZV_TV {

	/**
	 * Жанры для фильтра записей. Коды те же, что в карточках концертов (поле «Жанры» афиши,
	 * по нему же работают фильтры афиши и поиск): кнопка объединяет близкие жанры главной сцены.
	 */
	const GENRES = array(
		'jazz'   => array( 'Джаз', array( 'jazz' ) ),
		'blues'  => array( 'Блюз', array( 'blues' ) ),
		'funk'   => array( 'Фанк и соул', array( 'funk', 'soul', 'gospel' ) ),
		'fusion' => array( 'Фьюжн', array( 'fusion', 'jazz-rock' ) ),
		'rock'   => array( 'Рок', array( 'rock' ) ),
		'world'  => array( 'Этника', array( 'ethnics', 'latin', 'manouche', 'folk', 'flamenco' ) ),
		'pop'    => array( 'Поп', array( 'pop' ) ),
	);

	/** Коды жанров афиши для кнопки фильтра. */
	public static function genre_codes( $slug ) {
		return isset( self::GENRES[ $slug ] ) ? self::GENRES[ $slug ][1] : array();
	}

	/* ------------------------------------------------------------ данные */

	/**
	 * Программа эфиров главной сцены: идущий концерт и ближайшие (без отметки «не транслируется»).
	 *
	 * @return array[] [ title, url, start, time, day, now ]
	 */
	public static function program( $now, $limit = 6 ) {
		$tz     = KZV_Settings::tz();
		$events = KZV_Concerts::events( $now - 3 * HOUR_IN_SECONDS, $now + 10 * DAY_IN_SECONDS );
		usort(
			$events,
			function ( $a, $b ) {
				return $a['start'] <=> $b['start'];
			}
		);
		$out = array();
		foreach ( $events as $e ) {
			if ( $e['skip'] ) {
				continue;
			}
			$end = $e['end'] > $e['start'] ? $e['end'] : $e['start'] + 3 * HOUR_IN_SECONDS;
			if ( $end <= $now ) {
				continue;
			}
			$out[] = array(
				'title' => KZV_Util::clean_title( $e['title'] ),
				'url'   => (string) $e['url'],
				'start' => (int) $e['start'],
				'time'  => self::time( (int) $e['start'], $tz ),
				'day'   => self::day( (int) $e['start'], $now, $tz ),
				'now'   => $e['start'] <= $now + 10 * MINUTE_IN_SECONDS && $end > $now,
			);
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}

	/** Ночные концерты в афише стоят на 23:59: показываем «00:00» и «ночь на …». */
	private static function time( $ts, DateTimeZone $tz ) {
		$t = KZV_Util::time_label( $ts, $tz );
		return '23:59' === $t ? '00:00' : $t;
	}

	private static function day( $ts, $now, DateTimeZone $tz ) {
		if ( '23:59' === KZV_Util::time_label( $ts, $tz ) ) {
			$next = ( new DateTimeImmutable( '@' . ( (int) $ts + 120 ) ) )->setTimezone( $tz );
			return 'Ночь на ' . KZV_Util::WEEKDAYS[ (int) $next->format( 'N' ) ];
		}
		return KZV_Util::day_label( $ts, $now, $tz );
	}

	/** Запись для страницы: общий вид + месяц и жанры. */
	public static function item( array $row, $now ) {
		$v           = KZV_Render::present( $row, $now );
		$v['month'] = KZV_Util::month_label( (int) $row['started_at'], KZV_Settings::tz() );
		return $v;
	}

	/** Данные для скрипта: переключение экрана по карточке без запроса к серверу. */
	public static function client( array $v ) {
		return array(
			'id'      => $v['id'],
			'title'   => $v['title'],
			'meta'    => $v['date'] . ' · ' . $v['dur'],
			'img'     => $v['img'],
			'vk'      => $v['vk'] ? KZV_Util::vk_embed( $v['vk'][0], $v['vk'][1], true ) : '',
			'vkWatch' => $v['vk'] ? KZV_Util::vk_watch( $v['vk'][0], $v['vk'][1] ) : '',
			'yt'      => '' !== $v['yt'] ? KZV_Util::yt_watch( $v['yt'] ) : '',
			'concert' => $v['concert'],
			'up'      => $v['up'],
			'href'    => $v['href'],
		);
	}

	/* ------------------------------------------------------------ страница */

	public static function page( array $args = array() ) {
		$now  = time();
		$per  = (int) KZV_Settings::get( 'per_page' );
		$q    = mb_substr( (string) ( $args['q'] ?? '' ), 0, 60 );
		$g    = isset( self::GENRES[ $args['genre'] ?? '' ] ) ? $args['genre'] : '';
		$res  = KZV_Store::query( 0, $per, $q, self::genre_codes( $g ) );
		$list = array();
		foreach ( $res['items'] as $row ) {
			$list[] = self::item( $row, $now );
		}
		$sel = null;
		if ( ! empty( $args['v'] ) ) {
			$row = KZV_Store::get_public( (int) $args['v'] );
			$sel = $row ? self::item( $row, $now ) : null;
		}
		$live = KZV_Live::state();
		$prog = self::program( $now );
		if ( ! $sel && ! $live['live'] && $list ) {
			$sel = $list[0];
		}
		$cfg = array(
			'rest'  => esc_url_raw( rest_url( 'kozlov-video/v1/' ) ),
			'ver'   => KZV_VERSION,
			'live'  => (bool) $live['live'],
			'per'   => $per,
			'total' => (int) $res['total'],
			'q'     => $q,
			'genre' => $g,
			'base'  => KZV_Render::archive_url(),
			'nonce' => ( 'all' !== KZV_Settings::get( 'mode' ) && is_user_logged_in() ) ? wp_create_nonce( 'wp_rest' ) : '',
			'focus' => ( $args['focus'] ?? '' ) === 'archive' ? 'archive' : '',
		);
		ob_start();
		?>
		<div class="kzv kzv-tv<?php echo $live['live'] ? ' is-live' : ''; ?><?php echo ( $live['live'] && ! $sel && $live['vk_id'] ) ? ' is-playing' : ''; ?>" data-kzv="<?php echo esc_attr( wp_json_encode( $cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); ?>">
			<?php echo self::mast( $live, $prog, $now ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<section class="kzv-theater" aria-label="Экран">
				<div class="kzv-main">
					<?php echo self::screen( $live, $sel ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				</div>
				<?php echo self::guide( $prog, $live ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</section>
			<?php echo self::library( $list, (int) $res['total'], $q, $g ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<?php echo self::foot(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<div class="kzv-toast" role="status" aria-live="polite" hidden data-kzv-toast></div>
			<?php echo self::schema( $list ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/** Состояние для скрипта страницы (REST /live): шапка и программа заново, данные плеера эфира. */
	public static function live_payload() {
		$now  = time();
		$live = KZV_Live::state();
		$prog = self::program( $now );
		$out  = array(
			'live'   => (bool) $live['live'],
			'signal' => (string) ( $live['signal'] ?? '' ),
			'mast'   => self::mast( $live, $prog, $now ),
			'guide'  => self::guide( $prog, $live ),
			'embed'  => '',
			'title'  => '',
			'url'    => '',
			'watch'  => '',
			'yt'     => '',
		);
		if ( $live['live'] ) {
			$out['embed'] = $live['vk_id'] ? KZV_Util::vk_embed( $live['vk_oid'], $live['vk_id'], true ) : '';
			$out['title'] = '' !== $live['title'] ? $live['title'] : 'Прямой эфир с Главной сцены';
			$out['url']   = (string) $live['url'];
			$out['watch'] = $live['vk_id'] ? KZV_Util::vk_watch( $live['vk_oid'], $live['vk_id'] ) : (string) KZV_Settings::get( 'vk_public' );
			$out['yt']    = '' !== $live['yt_id'] ? KZV_Util::yt_watch( $live['yt_id'] ) : '';
		}
		return $out;
	}

	/** Подпись лампы: StreamVi различает идущий эфир, паузу и потерю сигнала. */
	private static function signal_label( $signal ) {
		if ( 'pause' === $signal ) {
			return 'Перерыв в эфире';
		}
		if ( 'lost' === $signal ) {
			return 'Эфир прервался, ждём сигнал';
		}
		return 'В эфире';
	}

	/** Шапка: название канала и лампа эфира. */
	public static function mast( array $live, array $prog, $now ) {
		$next = null;
		foreach ( $prog as $p ) {
			if ( ! $p['now'] ) {
				$next = $p;
				break;
			}
		}
		ob_start();
		?>
		<header class="kzv-mast" data-kzv-mast>
			<div class="kzv-brand">
				<p class="kzv-eyebrow">Клуб Алексея Козлова</p>
				<h1 class="kzv-logo">Kozlov <span class="kzv-logo-tv">TV</span></h1>
				<p class="kzv-tagline">Концерты Главной сцены в прямом эфире и в записи. Смотреть можно бесплатно.</p>
			</div>
			<?php if ( $live['live'] ) : ?>
				<a class="kzv-lamp is-on" href="#kzv-screen" data-kzv-go-live>
					<span class="kzv-lamp-dot" aria-hidden="true"></span>
					<span class="kzv-lamp-text"><strong><?php echo esc_html( self::signal_label( (string) ( $live['signal'] ?? '' ) ) ); ?></strong><?php echo '' !== $live['title'] ? '<span>' . esc_html( $live['title'] ) . '</span>' : ''; ?></span>
				</a>
			<?php elseif ( $next ) : ?>
				<div class="kzv-lamp">
					<span class="kzv-lamp-dot" aria-hidden="true"></span>
					<span class="kzv-lamp-text"><strong>Эфир <?php echo esc_html( mb_strtolower( $next['day'] ) . ' в ' . $next['time'] ); ?></strong><span><?php echo esc_html( $next['title'] ); ?></span></span>
				</div>
			<?php endif; ?>
		</header>
		<?php
		return (string) ob_get_clean();
	}

	/** Экран: эфир, выбранная запись или последняя запись. */
	public static function screen( array $live, ?array $sel ) {
		$is_live = $live['live'] && ! $sel;
		$embed   = '';
		$poster  = '';
		$title   = '';
		$meta    = '';
		$c       = null;
		if ( $is_live ) {
			$embed = $live['vk_id'] ? KZV_Util::vk_embed( $live['vk_oid'], $live['vk_id'], true ) : '';
			$title = '' !== $live['title'] ? $live['title'] : 'Прямой эфир с Главной сцены';
			$c     = array(
				'concert' => $live['url'],
				'vkWatch' => $live['vk_id'] ? KZV_Util::vk_watch( $live['vk_oid'], $live['vk_id'] ) : (string) KZV_Settings::get( 'vk_public' ),
				'yt'      => '' !== $live['yt_id'] ? KZV_Util::yt_watch( $live['yt_id'] ) : '',
				'up'      => null,
				'href'    => KZV_Render::archive_url(),
			);
		} elseif ( $sel ) {
			$c      = self::client( $sel );
			$embed  = $c['vk'];
			$poster = $sel['img'];
			$title  = $sel['title'];
			$meta   = $c['meta'];
		}
		ob_start();
		?>
		<div class="kzv-screen-box" id="kzv-screen">
			<div class="kzv-screen" data-kzv-screen data-src="<?php echo esc_attr( $embed ); ?>" data-id="<?php echo esc_attr( $sel ? (string) $sel['id'] : ( $is_live ? 'live' : '' ) ); ?>">
				<?php if ( $is_live && '' !== $embed ) : ?>
					<iframe src="<?php echo esc_url( $embed ); ?>" title="<?php echo esc_attr( $title ); ?>" allow="autoplay; encrypted-media; fullscreen; picture-in-picture; screen-wake-lock" allowfullscreen></iframe>
				<?php elseif ( $is_live ) : ?>
					<div class="kzv-screen-msg">Эфир идёт. Плеер появится через минуту, а пока <a href="<?php echo esc_url( $c['vkWatch'] ); ?>" target="_blank" rel="noopener">смотрите в VK Видео</a>.</div>
				<?php elseif ( $sel ) : ?>
					<button type="button" class="kzv-poster" data-kzv-play aria-label="<?php echo esc_attr( 'Смотреть: ' . $title ); ?>">
						<?php if ( '' !== $poster ) : ?>
							<img src="<?php echo esc_url( $poster ); ?>" alt="" decoding="async" fetchpriority="high">
						<?php endif; ?>
						<span class="kzv-poster-shade" aria-hidden="true"></span>
						<span class="kzv-play" aria-hidden="true"><svg viewBox="0 0 24 24" width="28" height="28"><path d="M8 5.5v13l11-6.5z" fill="currentColor"/></svg></span>
					</button>
				<?php else : ?>
					<div class="kzv-screen-msg">Записи появятся здесь после первой загрузки архива.</div>
				<?php endif; ?>
				<div class="kzv-dock-bar" aria-hidden="true">
					<span class="kzv-dock-title" data-kzv-dock-title><?php echo esc_html( $title ); ?></span>
					<button type="button" class="kzv-dock-btn" data-kzv-undock aria-label="Вернуться к плееру"><svg viewBox="0 0 24 24" width="16" height="16"><path d="M12 5l-7 7h4v7h6v-7h4z" fill="currentColor"/></svg></button>
					<button type="button" class="kzv-dock-btn" data-kzv-close aria-label="Закрыть плеер"><svg viewBox="0 0 24 24" width="16" height="16"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg></button>
				</div>
			</div>
		</div>
		<div class="kzv-now" data-kzv-now>
			<p class="kzv-now-meta">
				<span class="kzv-chip-state<?php echo $is_live ? ' is-live' : ''; ?>" data-kzv-state><?php echo $is_live ? 'В эфире' : 'Запись эфира'; ?></span>
				<span data-kzv-meta><?php echo esc_html( $is_live ? 'Главная сцена' : $meta ); ?></span>
			</p>
			<h2 class="kzv-now-title" data-kzv-title><?php echo esc_html( $title ); ?></h2>
			<div class="kzv-now-actions" data-kzv-actions><?php echo $c ? self::actions( $c ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/** Кнопки под экраном. Принимает client(). */
	public static function actions( array $c ) {
		$out = '';
		if ( ! empty( $c['up'] ) ) {
			$out .= '<a class="kzv-btn kzv-btn--accent" href="' . esc_url( $c['up']['url'] ) . '">' . esc_html( $c['up']['label'] ) . '</a>';
		}
		if ( ! empty( $c['concert'] ) ) {
			$out .= '<a class="kzv-btn" href="' . esc_url( $c['concert'] ) . '">О концерте</a>';
		}
		$out .= '<button type="button" class="kzv-btn kzv-btn--icon" data-kzv-share data-url="' . esc_attr( $c['href'] ) . '" aria-label="Поделиться"><svg viewBox="0 0 24 24" width="18" height="18"><path d="M12 3v12M7 8l5-5 5 5M5 13v6h14v-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg><span>Поделиться</span></button>';
		$links = array();
		if ( ! empty( $c['vkWatch'] ) ) {
			$links[] = '<a href="' . esc_url( $c['vkWatch'] ) . '" target="_blank" rel="noopener">VK Видео</a>';
		}
		if ( ! empty( $c['yt'] ) ) {
			$links[] = '<a href="' . esc_url( $c['yt'] ) . '" target="_blank" rel="noopener">YouTube</a>';
		}
		if ( $links ) {
			$out .= '<span class="kzv-also">Смотреть в ' . implode( ' · ', $links ) . '</span>';
		}
		return $out;
	}

	/** Программа эфиров. */
	public static function guide( array $prog, array $live ) {
		ob_start();
		?>
		<aside class="kzv-guide" aria-label="Программа эфиров" data-kzv-guide>
			<div class="kzv-guide-head">
				<h3 class="kzv-guide-title">Программа эфиров</h3>
				<span class="kzv-guide-sub">Главная сцена</span>
			</div>
			<?php if ( $prog ) : ?>
				<ol class="kzv-guide-list">
					<?php foreach ( $prog as $p ) : ?>
						<?php $on = $p['now'] && $live['live']; ?>
						<li class="kzv-guide-item<?php echo $on ? ' is-now' : ''; ?>">
							<a href="<?php echo esc_url( $p['url'] ); ?>">
								<span class="kzv-guide-when"><span class="kzv-guide-day"><?php echo esc_html( $on ? 'Сейчас' : $p['day'] ); ?></span><time datetime="<?php echo esc_attr( gmdate( 'c', $p['start'] ) ); ?>"><?php echo esc_html( $p['time'] ); ?></time></span>
								<span class="kzv-guide-name"><?php echo esc_html( $p['title'] ); ?></span>
								<span class="kzv-guide-cta"><?php echo $on ? 'В эфире' : 'Билеты'; ?></span>
							</a>
						</li>
					<?php endforeach; ?>
				</ol>
				<p class="kzv-guide-note">Эфир включится на этой странице сам, обновлять её не нужно.</p>
			<?php else : ?>
				<p class="kzv-guide-note">Ближайшие эфиры появятся здесь, как только концерты будут в афише.</p>
			<?php endif; ?>
		</aside>
		<?php
		return (string) ob_get_clean();
	}

	/** Библиотека записей. */
	public static function library( array $list, $total, $q, $genre ) {
		ob_start();
		?>
		<section class="kzv-library" id="kzv-archive" aria-labelledby="kzv-lib-title">
			<div class="kzv-lib-head">
				<h2 class="kzv-lib-title" id="kzv-lib-title">Записи эфиров</h2>
				<span class="kzv-lib-count" data-kzv-count><?php echo esc_html( KZV_Render::count_label( $total ) ); ?></span>
			</div>
			<div class="kzv-tools">
				<form class="kzv-search" role="search" action="<?php echo esc_url( KZV_Render::archive_url() ); ?>" method="get" data-kzv-search>
					<label class="kzv-sr" for="kzv-q">Поиск по записям</label>
					<svg class="kzv-search-ico" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><circle cx="11" cy="11" r="6.5" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M16 16l4.5 4.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
					<input id="kzv-q" name="q" type="search" value="<?php echo esc_attr( $q ); ?>" placeholder="Артист или концерт" maxlength="60" autocomplete="off" enterkeyhint="search">
					<?php if ( '' !== $genre ) : ?>
						<input type="hidden" name="genre" value="<?php echo esc_attr( $genre ); ?>">
					<?php endif; ?>
				</form>
				<div class="kzv-genres" role="group" aria-label="Жанр" data-kzv-genres>
					<button type="button" class="kzv-genre" data-genre="" aria-pressed="<?php echo '' === $genre ? 'true' : 'false'; ?>">Все</button>
					<?php foreach ( self::GENRES as $slug => $def ) : ?>
						<button type="button" class="kzv-genre" data-genre="<?php echo esc_attr( $slug ); ?>" aria-pressed="<?php echo $genre === $slug ? 'true' : 'false'; ?>"><?php echo esc_html( $def[0] ); ?></button>
					<?php endforeach; ?>
				</div>
			</div>
			<ul class="kzv-grid" data-kzv-grid><?php echo self::cards( $list ); // phpcs:ignore WordPress.Security.EscapeOutput ?></ul>
			<div class="kzv-lib-more"<?php echo $total > count( $list ) ? '' : ' hidden'; ?> data-kzv-more-wrap>
				<button type="button" class="kzv-btn kzv-btn--wide" data-kzv-more>Показать ещё</button>
			</div>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/** Карточки с заголовками месяцев (заголовок занимает всю строку сетки). */
	public static function cards( array $list, $prev_month = '' ) {
		$out   = '';
		$month = (string) $prev_month;
		foreach ( $list as $v ) {
			if ( $v['month'] !== $month ) {
				$month = $v['month'];
				$out  .= '<li class="kzv-month" data-month="' . esc_attr( $month ) . '"><span>' . esc_html( $month ) . '</span></li>';
			}
			$out .= '<li class="kzv-cell"><a class="kzv-card" href="' . esc_url( $v['href'] ) . '" data-kzv-id="' . (int) $v['id'] . '" data-kzv-card="' . esc_attr( wp_json_encode( self::client( $v ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) . '">'
				. '<span class="kzv-thumb">'
				. ( '' !== $v['img'] ? '<img src="' . esc_url( $v['img'] ) . '" alt="" loading="lazy" decoding="async">' : '<span class="kzv-thumb-mono" aria-hidden="true">K</span>' )
				. '<span class="kzv-thumb-play" aria-hidden="true"><svg viewBox="0 0 24 24" width="20" height="20"><path d="M8 5.5v13l11-6.5z" fill="currentColor"/></svg></span>'
				. '<span class="kzv-playing" aria-hidden="true"><i></i><i></i><i></i></span>'
				. '<span class="kzv-dur">' . esc_html( $v['dur'] ) . '</span></span>'
				. '<span class="kzv-card-body"><span class="kzv-card-date">' . esc_html( $v['date'] ) . '</span>'
				. '<span class="kzv-card-title">' . esc_html( $v['title'] ) . '</span></span>'
				. '</a></li>';
		}
		return $out;
	}

	private static function foot() {
		$s = KZV_Settings::get();
		return '<footer class="kzv-foot"><span>Kozlov TV идёт и в соцсетях клуба:</span> '
			. '<a href="' . esc_url( $s['vk_public'] ) . '" target="_blank" rel="noopener">VK Видео</a>'
			. '<a href="' . esc_url( $s['yt_public'] ) . '" target="_blank" rel="noopener">YouTube</a>'
			. '<a href="' . esc_url( $s['tg_public'] ) . '" target="_blank" rel="noopener">Telegram</a>'
			. '<span class="kzv-foot-note">Записи до апреля 2023 года на YouTube-канале клуба.</span></footer>';
	}

	/** Разметка видео для поисковиков (только записи с обложкой). */
	private static function schema( array $list ) {
		$tz    = KZV_Settings::tz();
		$items = array();
		foreach ( $list as $v ) {
			if ( '' === $v['img'] || ! $v['vk'] ) {
				continue;
			}
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => count( $items ) + 1,
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
		if ( ! $items ) {
			return '';
		}
		return '<script type="application/ld+json">' . wp_json_encode(
			array(
				'@context'        => 'https://schema.org',
				'@type'           => 'ItemList',
				'name'            => 'Kozlov TV: записи концертов Клуба Алексея Козлова',
				'itemListElement' => $items,
			),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		) . '</script>';
	}
}
