<?php
/**
 * Админка: «Трансляции → Kozlov TV» (или отдельный пункт, если Kozlov StreamVi не установлен).
 */

defined( 'ABSPATH' ) || exit;

final class KZV_Admin {

	const SLUG = 'kozlov-video';
	const CAP  = 'manage_options';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
		add_action( 'admin_post_kzv_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_kzv_row', array( __CLASS__, 'row' ) );
		add_action( 'admin_post_kzv_rematch', array( __CLASS__, 'rematch' ) );
		add_action( 'admin_post_kzv_create_tv', array( __CLASS__, 'create_tv' ) );
		add_action( 'wp_ajax_kzv_batch', array( __CLASS__, 'batch' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	public static function menu() {
		global $admin_page_hooks;
		if ( isset( $admin_page_hooks['kozlov-streamvi'] ) ) {
			add_submenu_page( 'kozlov-streamvi', 'Kozlov TV', 'Kozlov TV', self::CAP, self::SLUG, array( __CLASS__, 'page' ) );
		} else {
			add_menu_page( 'Kozlov TV', 'Kozlov TV', self::CAP, self::SLUG, array( __CLASS__, 'page' ), 'dashicons-video-alt3', 58 );
		}
	}

	private static function url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	public static function notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( (string) $screen->id, self::SLUG ) ) {
			return;
		}
		$msg = isset( $_GET['kzv_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['kzv_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$map = array(
			'saved'   => 'Настройки сохранены.',
			'row'     => 'Запись обновлена.',
			'rematch' => 'Привязка к афише обновлена.',
			'tv'      => 'Страница Kozlov TV создана.',
			'nopost'  => 'Концерт не найден: вставьте ссылку на страницу концерта или его ID.',
		);
		if ( isset( $map[ $msg ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $map[ $msg ] ) . '</p></div>';
		}
	}

	/* ------------------------------------------------------------ страница */

	public static function page() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$s     = KZV_Settings::get();
		$st    = KZV_Settings::state();
		$stats = KZV_Store::stats();
		$tz    = KZV_Settings::tz();
		$ready = KZV_StreamVi::ready();
		$afi   = KZV_Concerts::available();
		$pend  = KZV_Store::pending_count();
		$fmt   = function ( $ts ) use ( $tz ) {
			return $ts ? ( new DateTimeImmutable( '@' . (int) $ts ) )->setTimezone( $tz )->format( 'd.m.Y H:i' ) : 'нет';
		};
		?>
		<div class="wrap kzv-admin">
			<h1>Kozlov TV</h1>
			<p>Одна страница для эфира и записей концертов главной сцены через плеер VK Видео. Записи и ссылки берутся из истории StreamVi, названия, фото и жанры из афиши. Концерты с отметкой «не транслируется» в Kozlov StreamVi в программу эфиров не попадают.</p>
			<?php $tv = (int) $s['tv_page']; ?>
			<table class="widefat striped" style="max-width:760px;margin-bottom:16px">
				<tbody>
					<tr><td style="width:220px">Страница Kozlov TV</td><td>
						<?php if ( $tv ) : ?>
							<a href="<?php echo esc_url( get_permalink( $tv ) ); ?>" target="_blank"><?php echo esc_html( (string) get_permalink( $tv ) ); ?></a>
							(<?php echo esc_html( 'publish' === get_post_status( $tv ) ? 'опубликована' : 'видна только редакторам' ); ?>)
						<?php else : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
								<?php wp_nonce_field( 'kzv_create_tv' ); ?>
								<input type="hidden" name="action" value="kzv_create_tv">
								<button class="button button-primary">Создать страницу /tv/</button>
							</form>
							<span class="description">Пока её нет, Kozlov TV показывается на страницах «Live» и «Видеоархив».</span>
						<?php endif; ?>
					</td></tr>
					<tr><td>Старые адреса</td><td><?php echo esc_html( KZV_Front::tv_public() ? '/live/ и /videoarxiv/ перенаправляются на /tv/ (301)' : 'перенаправление включится вместе с режимом «Всем посетителям»' ); ?></td></tr>
					<?php if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) : ?>
						<tr><td>Расписание</td><td>WP-Cron на сайте отключён. Архив догружается сам, пока страницу Kozlov TV кто-то открывает (не чаще раза в 10 минут), и по кнопке ниже. Для ночных часов можно добавить в cron хостинга вызов <code><?php echo esc_html( site_url( 'wp-cron.php' ) ); ?></code> раз в 10 минут.</td></tr>
					<?php endif; ?>
				</tbody>
			</table>

			<h2>Состояние</h2>
			<table class="widefat striped" style="max-width:760px">
				<tbody>
					<tr><td>StreamVi</td><td><?php echo $ready ? 'подключён через «Трансляции» (Kozlov StreamVi)' : '<strong>не подключён</strong>: подключите StreamVi в разделе «Трансляции»'; // phpcs:ignore WordPress.Security.EscapeOutput ?></td></tr>
					<tr><td>Афиша главной сцены</td><td><?php echo $afi ? 'доступна' : '<strong>нет</strong> (нужен Kozlov StreamVi): записи будут без названий концертов'; // phpcs:ignore WordPress.Security.EscapeOutput ?></td></tr>
					<tr><td>Трансляций в StreamVi</td><td><?php echo esc_html( (string) (int) $st['total'] ); ?></td></tr>
					<tr><td>Загружено в архив</td><td><?php echo esc_html( $stats['total'] . ' (ждут ссылок: ' . $pend . ', не удалось: ' . $stats['failed'] . ')' ); ?></td></tr>
					<tr><td>С видео в VK</td><td><?php echo esc_html( $stats['with_vk'] . ' (без VK после трёх дней проверок: ' . (int) ( $stats['no_vk'] ?? 0 ) . ')' ); ?></td></tr>
					<tr><td>Привязано к концертам</td><td><?php echo esc_html( (string) $stats['matched'] ); ?></td></tr>
					<tr><td>Показывается на сайте</td><td><?php echo esc_html( $stats['public'] . ' (скрыто вручную: ' . $stats['hidden'] . ', короче ' . (int) $s['min_minutes'] . ' мин: ' . $stats['short_ones'] . ')' ); ?></td></tr>
					<tr><td>Самая ранняя запись</td><td><?php echo esc_html( $fmt( $stats['first_ts'] ) ); ?></td></tr>
					<tr><td>Синхронизация</td><td><?php echo esc_html( 'последний запуск ' . $fmt( $st['last_run'] ) . ', без ошибок ' . $fmt( $st['last_ok'] ) . ( $st['backfill_done'] ? ', вся история перебрана' : ', старая история: ' . (int) $st['backfill'] . ' из ' . (int) $st['total'] ) ); ?></td></tr>
				</tbody>
			</table>
			<?php if ( ! empty( $st['errors'] ) ) : ?>
				<details style="margin-top:8px"><summary>Последние ошибки (<?php echo esc_html( (string) count( $st['errors'] ) ); ?>)</summary>
					<ul>
						<?php foreach ( array_reverse( $st['errors'] ) as $er ) : ?>
							<li><?php echo esc_html( $fmt( $er['t'] ?? 0 ) . ': ' . ( $er['m'] ?? '' ) ); ?></li>
						<?php endforeach; ?>
					</ul>
				</details>
			<?php endif; ?>

			<p style="margin-top:12px">
				<button type="button" class="button button-primary" id="kzv-run" <?php disabled( ! $ready ); ?>>Загрузить архив сейчас</button>
				<span id="kzv-run-status" style="margin-left:8px"></span>
			</p>
			<p class="description">Без кнопки архив догружается сам: каждые 10 минут по 25 трансляций. Кнопка делает то же пачками, пока не дойдёт до конца (около 10 минут на всю историю). Если VK-ссылка появляется не сразу, запись проверяется каждый час три дня после эфира.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:8px">
				<?php wp_nonce_field( 'kzv_rematch' ); ?>
				<input type="hidden" name="action" value="kzv_rematch">
				<button class="button">Привязать записи без концерта к афише заново</button>
			</form>

			<h2 style="margin-top:28px">Настройки</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'kzv_save' ); ?>
				<input type="hidden" name="action" value="kzv_save">
				<table class="form-table" role="presentation">
					<tr><th scope="row">Кому показывать</th><td>
						<?php
						$modes = array(
							'off'    => 'Выключено: страницы как раньше',
							'admins' => 'Только редакторам (проверка), посетители видят старые страницы',
							'all'    => 'Всем посетителям: страница /tv/ публикуется, /live/ и /videoarxiv/ ведут на неё',
						);
						foreach ( $modes as $k => $label ) {
							echo '<label style="display:block;margin:4px 0"><input type="radio" name="mode" value="' . esc_attr( $k ) . '" ' . checked( $s['mode'], $k, false ) . '> ' . esc_html( $label ) . '</label>';
						}
						?>
					</td></tr>
					<tr><th scope="row">Страница Kozlov TV</th><td><?php wp_dropdown_pages( array( 'name' => 'tv_page', 'selected' => (int) $s['tv_page'], 'show_option_none' => 'не выбрана', 'option_none_value' => 0, 'post_status' => array( 'publish', 'private' ) ) ); ?></td></tr>
					<tr><th scope="row">Страница эфира</th><td><?php wp_dropdown_pages( array( 'name' => 'live_page', 'selected' => (int) $s['live_page'], 'show_option_none' => 'не выбрана', 'option_none_value' => 0 ) ); ?> <a href="<?php echo esc_url( KZV_Render::live_url() ); ?>" target="_blank">открыть</a></td></tr>
					<tr><th scope="row">Страница архива</th><td><?php wp_dropdown_pages( array( 'name' => 'archive_page', 'selected' => (int) $s['archive_page'], 'show_option_none' => 'не выбрана', 'option_none_value' => 0 ) ); ?> <a href="<?php echo esc_url( KZV_Render::archive_url() ); ?>" target="_blank">открыть</a></td></tr>
					<tr><th scope="row"><label for="kzv-owner">Группа VK</label></th><td><input id="kzv-owner" name="vk_owner" type="number" value="<?php echo esc_attr( (string) $s['vk_owner'] ); ?>" class="regular-text"><p class="description">ID группы со знаком минус. Клуб: -77211165. Если в трансляции своей группы нет, берётся видео из другой группы VK.</p></td></tr>
					<tr><th scope="row"><label for="kzv-min">Не показывать короче</label></th><td><input id="kzv-min" name="min_minutes" type="number" min="0" max="180" value="<?php echo esc_attr( (string) $s['min_minutes'] ); ?>"> мин <p class="description">Отсекает перезапуски эфира и проверки звука.</p></td></tr>
					<tr><th scope="row"><label for="kzv-per">Записей на странице</label></th><td><input id="kzv-per" name="per_page" type="number" min="6" max="60" value="<?php echo esc_attr( (string) $s['per_page'] ); ?>"></td></tr>
					<tr><th scope="row">Ссылки на каналы</th><td>
						<p><input name="vk_public" type="url" value="<?php echo esc_attr( $s['vk_public'] ); ?>" class="regular-text"> VK Видео</p>
						<p><input name="yt_public" type="url" value="<?php echo esc_attr( $s['yt_public'] ); ?>" class="regular-text"> YouTube</p>
						<p><input name="tg_public" type="url" value="<?php echo esc_attr( $s['tg_public'] ); ?>" class="regular-text"> Telegram</p>
					</td></tr>
				</table>
				<?php submit_button( 'Сохранить' ); ?>
			</form>

			<h2>Последние записи</h2>
			<p class="description">Название можно поправить, запись можно скрыть с сайта. Ссылки ведут на площадки.</p>
			<table class="widefat striped">
				<thead><tr><th>Эфир</th><th>Название на сайте</th><th>Длит.</th><th>Ссылки</th><th>На сайте</th></tr></thead>
				<tbody>
				<?php
				foreach ( KZV_Store::recent( 40 ) as $r ) :
					$bid   = (int) $r['broadcast_id'];
					$v     = KZV_Render::present( $r );
					$short = (int) $r['duration'] < (int) $s['min_minutes'] * 60;
					if ( ! (int) $r['detailed'] ) {
						$state = (int) $r['tries'] >= 5 ? 'сбои StreamVi, проверка ' . $fmt( $r['next_try'] ?? 0 ) : 'ждёт ссылок';
					} elseif ( (int) $r['hidden'] ) {
						$state = 'скрыта';
					} elseif ( ! (int) $r['vk_id'] ) {
						$state = 'нет видео VK';
					} elseif ( $short ) {
						$state = 'короткая';
					} else {
						$state = 'да';
					}
					?>
					<tr>
						<td><?php echo esc_html( $fmt( $r['started_at'] ) ); ?></td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:6px">
								<?php wp_nonce_field( 'kzv_row_' . $bid ); ?>
								<input type="hidden" name="action" value="kzv_row">
								<input type="hidden" name="bid" value="<?php echo esc_attr( (string) $bid ); ?>">
								<input type="hidden" name="op" value="title">
								<input type="text" name="title" value="<?php echo esc_attr( (string) $r['title_custom'] ); ?>" placeholder="<?php echo esc_attr( $v['title'] ); ?>" style="width:100%;min-width:220px">
								<button class="button">ОК</button>
							</form>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:6px;margin-top:4px;align-items:center">
								<?php wp_nonce_field( 'kzv_row_' . $bid ); ?>
								<input type="hidden" name="action" value="kzv_row">
								<input type="hidden" name="bid" value="<?php echo esc_attr( (string) $bid ); ?>">
								<input type="hidden" name="op" value="concert">
								<?php if ( '' !== $v['concert'] ) : ?>
									<a href="<?php echo esc_url( $v['concert'] ); ?>" target="_blank">концерт</a>
								<?php endif; ?>
								<input type="text" name="concert" value="" placeholder="<?php echo esc_attr( '' !== $v['concert'] ? 'другой концерт: ссылка или ID' : 'привязать концерт: ссылка или ID' ); ?>" style="width:100%;min-width:180px">
								<button class="button">ОК</button>
							</form>
						</td>
						<td><?php echo esc_html( KZV_Util::duration_label( (int) $r['duration'] ) ); ?></td>
						<td>
							<?php if ( (int) $r['vk_id'] ) : ?>
								<a href="<?php echo esc_url( KZV_Util::vk_watch( $r['vk_oid'], $r['vk_id'] ) ); ?>" target="_blank">VK</a>
							<?php endif; ?>
							<?php if ( '' !== (string) $r['yt_id'] ) : ?>
								<a href="<?php echo esc_url( KZV_Util::yt_watch( $r['yt_id'] ) ); ?>" target="_blank">YouTube</a>
							<?php endif; ?>
						</td>
						<td>
							<?php echo esc_html( $state ); ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
								<?php wp_nonce_field( 'kzv_row_' . $bid ); ?>
								<input type="hidden" name="action" value="kzv_row">
								<input type="hidden" name="bid" value="<?php echo esc_attr( (string) $bid ); ?>">
								<input type="hidden" name="op" value="<?php echo (int) $r['hidden'] ? 'show' : 'hide'; ?>">
								<button class="button-link"><?php echo (int) $r['hidden'] ? 'показать' : 'скрыть'; ?></button>
							</form>
							<?php if ( (int) $r['detailed'] && ! (int) $r['vk_id'] ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
									<?php wp_nonce_field( 'kzv_row_' . $bid ); ?>
									<input type="hidden" name="action" value="kzv_row">
									<input type="hidden" name="bid" value="<?php echo esc_attr( (string) $bid ); ?>">
									<input type="hidden" name="op" value="recheck">
									<button class="button-link">проверить ссылки снова</button>
								</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<script>
		(function () {
			var btn = document.getElementById('kzv-run');
			var out = document.getElementById('kzv-run-status');
			if (!btn) { return; }
			var nonce = <?php echo wp_json_encode( wp_create_nonce( 'kzv_batch' ) ); ?>;
			var url = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			var stop = false;
			function step(n) {
				var body = new FormData();
				body.append('action', 'kzv_batch');
				body.append('_ajax_nonce', nonce);
				fetch(url, { method: 'POST', body: body, credentials: 'same-origin' })
					.then(function (r) { return r.json(); })
					.then(function (j) {
						if (!j || !j.success) { throw new Error(j && j.data ? j.data : 'ошибка'); }
						var d = j.data;
						out.textContent = 'Проход ' + n + ': загружено ' + d.stats.total + ', ждут ссылок ' + d.pending + ', на сайте ' + d.stats.public + (d.report.errors.length ? '. Ошибка: ' + d.report.errors[0] : '');
						if (d.report.errors.length || d.done || stop) {
							btn.disabled = false;
							btn.textContent = 'Загрузить архив сейчас';
							out.textContent += d.done ? '. Готово, обновите страницу.' : '';
							return;
						}
						setTimeout(function () { step(n + 1); }, d.report.locked ? 15000 : 800);
					})
					.catch(function (e) { out.textContent = 'Ошибка: ' + e.message; btn.disabled = false; });
			}
			btn.addEventListener('click', function () {
				btn.disabled = true;
				btn.textContent = 'Загружаю…';
				step(1);
			});
		})();
		</script>
		<?php
	}

	/* ------------------------------------------------------------ действия */

	public static function save() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Нет доступа', 403 );
		}
		check_admin_referer( 'kzv_save' );
		$in = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		KZV_Settings::update(
			array(
				'mode'         => (string) ( $in['mode'] ?? 'off' ),
				'tv_page'      => (int) ( $in['tv_page'] ?? 0 ),
				'live_page'    => (int) ( $in['live_page'] ?? 0 ),
				'archive_page' => (int) ( $in['archive_page'] ?? 0 ),
				'vk_owner'     => (int) ( $in['vk_owner'] ?? 0 ),
				'min_minutes'  => (int) ( $in['min_minutes'] ?? 30 ),
				'per_page'     => (int) ( $in['per_page'] ?? 24 ),
				'vk_public'    => (string) ( $in['vk_public'] ?? '' ),
				'yt_public'    => (string) ( $in['yt_public'] ?? '' ),
				'tg_public'    => (string) ( $in['tg_public'] ?? '' ),
			)
		);
		delete_transient( KZV_Live::CACHE );
		self::sync_tv_status();
		self::purge_pages();
		wp_safe_redirect( self::url( array( 'kzv_msg' => 'saved' ) ) );
		exit;
	}

	public static function row() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Нет доступа', 403 );
		}
		$bid = isset( $_POST['bid'] ) ? absint( $_POST['bid'] ) : 0;
		check_admin_referer( 'kzv_row_' . $bid );
		$op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		if ( $bid && KZV_Store::get( $bid ) ) {
			if ( 'hide' === $op || 'show' === $op ) {
				KZV_Store::set_hidden( $bid, 'hide' === $op );
			} elseif ( 'title' === $op ) {
				KZV_Store::set_custom_title( $bid, sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) ) );
			} elseif ( 'recheck' === $op ) {
				KZV_Store::recheck( $bid );
			} elseif ( 'concert' === $op ) {
				$raw = trim( sanitize_text_field( wp_unslash( $_POST['concert'] ?? '' ) ) );
				$pid = ctype_digit( $raw ) ? (int) $raw : ( '' !== $raw ? (int) url_to_postid( $raw ) : 0 );
				if ( $pid <= 0 || ! get_post( $pid ) ) {
					wp_safe_redirect( self::url( array( 'kzv_msg' => 'nopost' ) ) );
					exit;
				}
				KZV_Store::set_match( $bid, $pid, KZV_Util::clean_title( get_the_title( $pid ) ), KZV_Concerts::genres( $pid ) );
			}
			self::purge_pages();
		}
		wp_safe_redirect( self::url( array( 'kzv_msg' => 'row' ) ) );
		exit;
	}

	public static function rematch() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Нет доступа', 403 );
		}
		check_admin_referer( 'kzv_rematch' );
		KZV_Sync::rematch( 300 );
		self::purge_pages();
		wp_safe_redirect( self::url( array( 'kzv_msg' => 'rematch' ) ) );
		exit;
	}

	public static function batch() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( 'Нет доступа', 403 );
		}
		check_ajax_referer( 'kzv_batch' );
		try {
			$report  = KZV_Sync::run( 20 );
			$pending = KZV_Store::pending_count();
			$st      = KZV_Settings::state();
			if ( $report['new'] || $report['old'] || $report['detailed'] ) {
				self::purge_pages();
			}
			wp_send_json_success(
				array(
					'report'  => $report,
					'stats'   => KZV_Store::stats(),
					'pending' => $pending,
					'done'    => ( 0 === $pending && ! empty( $st['backfill_done'] ) ),
				)
			);
		} catch ( \Throwable $e ) {
			kzv_log_exception( $e );
			wp_send_json_error( $e->getMessage() );
		}
	}

	/**
	 * Создать страницу /tv/. Пока Kozlov TV не включён для всех, она видна только редакторам
	 * (статус «личная»), поисковики и посетители её не видят.
	 */
	public static function create_tv() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Нет доступа', 403 );
		}
		check_admin_referer( 'kzv_create_tv' );
		KZV_Settings::forget_pages();
		if ( ! (int) KZV_Settings::get( 'tv_page' ) ) {
			$id = wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_title'   => 'Kozlov TV',
					'post_name'    => 'tv',
					'post_status'  => 'all' === KZV_Settings::get( 'mode' ) ? 'publish' : 'private',
					'post_content' => '<p>Концерты Главной сцены Клуба Алексея Козлова в прямом эфире и в записи: <a href="https://vkvideo.ru/@kozlovclubfan">VK Видео</a>, <a href="https://www.youtube.com/@kozlovclubtv">YouTube</a>.</p>',
					'comment_status' => 'closed',
					'ping_status'    => 'closed',
				),
				true
			);
			if ( ! is_wp_error( $id ) ) {
				KZV_Settings::update( array( 'tv_page' => (int) $id ) );
			} else {
				KZV_Settings::note_error( 'страница /tv/: ' . $id->get_error_message() );
			}
		}
		wp_safe_redirect( self::url( array( 'kzv_msg' => 'tv' ) ) );
		exit;
	}

	/** Режим «всем»: страница /tv/ опубликована; иначе видна только редакторам. */
	private static function sync_tv_status() {
		$tv = (int) KZV_Settings::get( 'tv_page' );
		if ( ! $tv || ! get_post( $tv ) ) {
			return;
		}
		$want = 'all' === KZV_Settings::get( 'mode' ) ? 'publish' : 'private';
		$have = get_post_status( $tv );
		if ( $have !== $want && in_array( $have, array( 'publish', 'private', 'draft' ), true ) ) {
			wp_update_post(
				array(
					'ID'          => $tv,
					'post_status' => $want,
				)
			);
		}
	}

	public static function purge_pages() {
		KZV_Front::purge();
	}
}
