<?php
/**
 * Страница «Настройки → Kozlov Smart Search»: включение, режим проверки и состояние индекса.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'KSS_Admin' ) ) :

	class KSS_Admin {

		const PAGE  = 'kozlov-smart-search';
		const GROUP = 'kss_settings_group';

		public static function init() {
			if ( ! is_admin() ) {
				return;
			}
			add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
			add_action( 'admin_init', array( __CLASS__, 'register' ) );
			add_action( 'admin_post_kss_rebuild', array( __CLASS__, 'rebuild' ) );
			add_action( 'update_option_' . KSS_Settings::OPTION, array( 'KSS_Index', 'flush' ), 10, 0 );
			add_action( 'add_option_' . KSS_Settings::OPTION, array( 'KSS_Index', 'flush' ), 10, 0 );
			add_filter( 'plugin_action_links_' . plugin_basename( KSS_FILE ), array( __CLASS__, 'action_links' ) );
		}

		public static function menu() {
			try {
				add_options_page( 'Kozlov Smart Search', 'Kozlov Smart Search', 'manage_options', self::PAGE, array( __CLASS__, 'render' ) );
			} catch ( \Throwable $e ) {
				kss_log( $e );
			}
		}

		public static function register() {
			try {
				register_setting(
					self::GROUP,
					KSS_Settings::OPTION,
					array(
						'type'              => 'array',
						'sanitize_callback' => array( 'KSS_Settings', 'sanitize_form' ),
						'default'           => KSS_Settings::defaults(),
					)
				);
			} catch ( \Throwable $e ) {
				kss_log( $e );
			}
		}

		public static function action_links( $links ) {
			try {
				if ( is_array( $links ) ) {
					array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=' . self::PAGE ) ) . '">Настройки</a>' );
				}
			} catch ( \Throwable $e ) {
				kss_log( $e );
			}
			return $links;
		}

		public static function rebuild() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( 'Недостаточно прав.', '', array( 'response' => 403 ) );
			}
			check_admin_referer( 'kss_rebuild' );
			$ok = 0;
			try {
				KSS_Index::flush();
				$ok = is_array( KSS_Index::get( 'index', true ) ) ? 1 : 0;
			} catch ( \Throwable $e ) {
				kss_log( $e );
			}
			wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE . '&kss_rebuilt=' . $ok ) );
			exit;
		}

		protected static function bytes( $data ) {
			$json = json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			return is_string( $json ) ? strlen( $json ) : 0;
		}

		protected static function kb( $bytes ) {
			return number_format( $bytes / 1024, 1, ',', ' ' ) . ' КБ';
		}

		public static function render() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
			try {
				self::render_page();
			} catch ( \Throwable $e ) {
				kss_log( $e );
				echo '<div class="wrap"><h1>Kozlov Smart Search</h1><div class="notice notice-error"><p>Не удалось показать страницу настроек. Ошибка записана в журнал PHP. Сайт и афиша работают как обычно.</p></div></div>';
			}
		}

		protected static function render_page() {
			$s     = KSS_Settings::get();
			$index = KSS_Index::get( 'index' );
			$texts = KSS_Index::get( 'texts' );
			$stats = ( is_array( $index ) && isset( $index['stats'] ) && is_array( $index['stats'] ) ) ? $index['stats'] : array();
			$modes = array(
				'off'   => array( 'Выключен', 'Посетители и администраторы видят штатный поиск темы. Плагин ничего не выводит на сайте.' ),
				'admin' => array( 'Проверка: виден только администраторам и по секретной ссылке', 'Новый поиск работает у вошедшего администратора и у того, кто открыл ссылку для проверки. Посетители видят штатный поиск темы.' ),
				'on'    => array( 'Включён для всех', 'Новый поиск работает у всех посетителей.' ),
			);
			$stage_names = array(
				'g' => 'Главная сцена',
				'm' => 'Мансарда',
				'b' => 'The Beat',
				'u' => 'Unplugged',
			);
			?>
			<div class="wrap kss-admin">
				<h1>Kozlov Smart Search</h1>

				<?php if ( isset( $_GET['kss_rebuilt'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
					<div class="notice <?php echo '1' === $_GET['kss_rebuilt'] ? 'notice-success' : 'notice-error'; // phpcs:ignore ?> is-dismissible"><p><?php echo '1' === $_GET['kss_rebuilt'] ? 'Индекс пересобран.' : 'Индекс пересобрать не удалось. Ошибка записана в журнал PHP.'; // phpcs:ignore ?></p></div>
				<?php endif; ?>

				<form method="post" action="options.php" id="kss-settings-form">
					<?php settings_fields( self::GROUP ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">Режим</th>
							<td>
								<fieldset>
									<?php foreach ( $modes as $value => $label ) : ?>
										<p>
											<label>
												<input type="radio" name="<?php echo esc_attr( KSS_Settings::OPTION ); ?>[mode]" value="<?php echo esc_attr( $value ); ?>" <?php checked( $s['mode'], $value ); ?>>
												<strong><?php echo esc_html( $label[0] ); ?></strong>
											</label><br>
											<span class="description"><?php echo esc_html( $label[1] ); ?></span>
										</p>
									<?php endforeach; ?>
								</fieldset>
							</td>
						</tr>
						<tr>
							<th scope="row">Тексты анонсов</th>
							<td>
								<input type="hidden" name="<?php echo esc_attr( KSS_Settings::OPTION ); ?>[content]" value="0">
								<label>
									<input type="checkbox" name="<?php echo esc_attr( KSS_Settings::OPTION ); ?>[content]" value="1" <?php checked( ! empty( $s['content'] ) ); ?>>
									Искать не только в названии и жанре, но и в тексте анонса
								</label>
								<p class="description">Так находятся «фанк», «саксофон» или фамилия участника состава, даже если их нет в названии концерта.</p>
							</td>
						</tr>
					</table>
					<?php submit_button( 'Сохранить' ); ?>
				</form>

				<?php if ( 'admin' === $s['mode'] && '' !== $s['key'] ) : ?>
					<div class="notice notice-warning inline">
						<p><strong>Ссылка для проверки</strong> (работает без входа в админку, удобно открыть на телефоне или в приватном окне):</p>
						<p><code style="user-select:all"><?php echo esc_html( add_query_arg( 'kss_preview', $s['key'], home_url( '/' ) ) ); ?></code></p>
						<p><label><input type="checkbox" form="kss-settings-form" name="<?php echo esc_attr( KSS_Settings::OPTION ); ?>[new_key]" value="1"> Заменить ссылку новой при следующем сохранении (старая перестанет работать)</label></p>
						<p class="description">Поиск включается только на странице, открытой по этой ссылке. Если вы вошли в админку, верхняя панель WordPress может перекрывать строку поиска темы: по ссылке в приватном окне её нет. Ссылка перестаёт работать, как только режим проверки выключен.</p>
					</div>
				<?php endif; ?>

				<div class="notice notice-info inline">
					<p><strong>Выключение действует сразу</strong> и без сброса кэша. У посетителей, которые уже открывали поиск, он отключится в течение 5 минут.</p>
					<p>После перехода в режим «Включён для всех» сбросьте кэш страниц (Performance → Purge All Caches): иначе посетители, которым отдаётся сохранённая копия страницы, увидят новый поиск только после её обновления.</p>
				</div>

				<h2>Состояние индекса</h2>
				<?php if ( ! is_array( $index ) ) : ?>
					<div class="notice notice-error inline"><p>Индекс собрать не удалось. Ошибка записана в журнал PHP. На сайте работает штатный поиск темы.</p></div>
				<?php else : ?>
					<?php if ( empty( $stats['tables'] ) ) : ?>
						<div class="notice notice-warning inline"><p>Таблицы календаря (ai1ec_event_instances, ai1ec_events) не найдены: концертов в индексе нет.</p></div>
					<?php endif; ?>
					<table class="widefat striped" style="max-width:760px">
						<tbody>
							<tr><td style="width:300px">Будущих концертов (дат)</td><td><?php echo (int) ( isset( $stats['events'] ) ? $stats['events'] : 0 ); ?></td></tr>
							<tr><td>Событий</td><td><?php echo (int) ( isset( $stats['posts'] ) ? $stats['posts'] : 0 ); ?></td></tr>
							<tr><td>С временем окончания</td><td><?php echo (int) ( isset( $stats['ends'] ) ? $stats['ends'] : 0 ); ?></td></tr>
							<tr><td>С кнопкой «Купить билет»</td><td><?php echo (int) ( isset( $stats['tickets'] ) ? $stats['tickets'] : 0 ); ?></td></tr>
							<tr><td>С картинкой / из них в WebP</td><td><?php echo (int) ( isset( $stats['thumbs'] ) ? $stats['thumbs'] : 0 ); ?> / <?php echo (int) ( isset( $stats['webp'] ) ? $stats['webp'] : 0 ); ?></td></tr>
							<tr><td>Страниц сайта</td><td><?php echo (int) ( isset( $stats['pages'] ) ? $stats['pages'] : 0 ); ?></td></tr>
							<tr><td>Текстов анонсов / слов в словаре</td><td><?php echo (int) ( isset( $stats['texts'] ) ? $stats['texts'] : 0 ); ?> / <?php echo (int) ( isset( $stats['vocab'] ) ? $stats['vocab'] : 0 ); ?></td></tr>
							<tr><td>Размер индекса / текстов</td><td><?php echo esc_html( self::kb( self::bytes( array_diff_key( $index, array( 'stats' => 1, 'sig' => 1 ) ) ) ) ); ?> / <?php echo esc_html( is_array( $texts ) ? self::kb( self::bytes( $texts ) ) : 'нет' ); ?> (до сжатия)</td></tr>
							<tr><td>Собран</td><td><?php echo esc_html( wp_date( 'd.m.Y H:i:s', (int) $index['built'] ) ); ?>, за <?php echo (int) ( isset( $stats['ms'] ) ? $stats['ms'] : 0 ); ?> мс</td></tr>
							<tr><td>Обновление</td><td>Автоматически раз в <?php echo (int) ( KSS_Index::FRESH / 60 ); ?> минут и после сохранения события или страницы, но не чаще одного раза в <?php echo (int) KSS_Index::MIN_GAP; ?> секунд</td></tr>
							<tr><td>Защита от одновременной пересборки</td><td><?php echo 'file' === KSS_Index::lock_kind() ? 'файловая блокировка (flock)' : 'блокировка через кэш (файловая на этом сервере недоступна)'; ?>. Индекс всегда собирает один запрос, остальные получают готовую копию.</td></tr>
							<tr><td>Адрес индекса</td><td><code><?php echo esc_html( rest_url( KSS_Rest::NS . '/index' ) ); ?></code></td></tr>
						</tbody>
					</table>

					<?php self::render_compare( $index ); ?>

					<?php if ( ! empty( $stats['sample'] ) && is_array( $stats['sample'] ) ) : ?>
						<h3>Ближайшие концерты в индексе</h3>
						<p class="description">Сверьте с афишей на главной странице: дата, время, сцена и цена должны совпадать.</p>
						<table class="widefat striped" style="max-width:960px">
							<thead><tr><th>Дата</th><th>Время</th><th>Название</th><th>Сцена</th><th>Цена</th><th>Кнопка</th></tr></thead>
							<tbody>
								<?php foreach ( $stats['sample'] as $e ) : ?>
									<?php
									$names = array();
									foreach ( str_split( (string) $e[3] ) as $code ) {
										if ( isset( $stage_names[ $code ] ) ) {
											$names[] = $stage_names[ $code ];
										}
									}
									$btn = array( 'Подробнее', 'Купить билет', 'Регистрация' );
									$till = '';
									if ( isset( $e[12] ) && (int) $e[12] > 0 && preg_match( '/^(\d\d):(\d\d)$/', (string) $e[2], $hm ) ) {
										$mins = ( (int) $hm[1] * 60 + (int) $hm[2] + (int) $e[12] ) % 1440;
										$till = sprintf( '–%02d:%02d', intdiv( $mins, 60 ), $mins % 60 );
									}
									?>
									<tr>
										<td><?php echo esc_html( $e[1] ); ?></td>
										<td><?php echo esc_html( $e[2] . $till ); ?></td>
										<td><?php echo esc_html( $e[0] ); ?></td>
										<td><?php echo esc_html( implode( ', ', $names ) ); ?></td>
										<td><?php echo esc_html( $e[4] ); ?></td>
										<td><?php echo esc_html( isset( $btn[ (int) $e[7] ] ) ? $btn[ (int) $e[7] ] : '' ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				<?php endif; ?>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:16px">
					<input type="hidden" name="action" value="kss_rebuild">
					<?php wp_nonce_field( 'kss_rebuild' ); ?>
					<?php submit_button( 'Пересобрать индекс сейчас', 'secondary', 'submit', false ); ?>
				</form>

				<h2>Если что-то пошло не так</h2>
				<ol>
					<li>Выберите режим «Выключен» и сохраните: на сайте сразу останется штатный поиск темы.</li>
					<li>Если админка недоступна, добавьте в <code>wp-config.php</code> строку <code>define( 'KSS_DISABLE', true );</code> или удалите папку <code>wp-content/plugins/kozlov-smart-search</code>.</li>
				</ol>
				<p class="description">Плагин ничего не записывает в события, страницы и таблицы календаря. Концерты, закрытые паролем, в поиск не попадают. Собственные данные плагина: одна запись настроек (<code>kss_settings</code>), временный кэш индекса и пустой файл блокировки во временной папке сервера. При удалении плагина они стираются.</p>
			</div>
			<?php
		}

		/**
		 * Сверка с афишей: браузер администратора читает главную страницу сайта и сравнивает
		 * день и время на каждой карточке с индексом. Только чтение, работает только на странице настроек.
		 *
		 * @param array $index Индекс поиска.
		 */
		protected static function render_compare( $index ) {
			if ( empty( $index['ev'] ) || ! is_array( $index['ev'] ) ) {
				return;
			}
			$rows = array();
			foreach ( $index['ev'] as $e ) {
				if ( is_array( $e ) && isset( $e[0], $e[1], $e[2], $e[5] ) ) {
					$rows[] = array( (string) $e[5], (string) $e[1], (string) $e[2], (string) $e[0] );
				}
			}
			$json = wp_json_encode( array( 'home' => home_url( '/' ), 'rows' => $rows ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES );
			if ( ! is_string( $json ) || ! $rows ) {
				return;
			}
			?>
			<h3>Сверка с афишей</h3>
			<p class="description">Сравнивает день и время каждого концерта в поиске с карточками на главной странице сайта. Ничего не изменяет.</p>
			<p><button type="button" class="button" id="kss-compare">Сверить с афишей</button> <span id="kss-compare-state" aria-live="polite"></span></p>
			<div id="kss-compare-out" style="max-width:960px"></div>
			<script type="application/json" id="kss-compare-data"><?php echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></script>
			<script>
			(function () {
				var d = document, btn = d.getElementById('kss-compare'), out = d.getElementById('kss-compare-out'), state = d.getElementById('kss-compare-state'), src = d.getElementById('kss-compare-data');
				if (!btn || !out || !state || !src || !window.fetch || !window.DOMParser) { if (btn) btn.disabled = true; return; }
				var data;
				try { data = JSON.parse(src.textContent); } catch (e) { btn.disabled = true; return; }
				function slugOf(href) {
					var p = String(href || '').split('#')[0].split('?')[0].replace(/\/+$/, '').split('/');
					return (p[p.length - 1] || '').toLowerCase();
				}
				function line(text, bold) {
					var p = d.createElement('p');
					if (bold) { var b = d.createElement('strong'); b.textContent = text; p.appendChild(b); } else { p.textContent = text; }
					out.appendChild(p);
				}
				function list(items) {
					var ul = d.createElement('ul'), i, li;
					ul.style.listStyle = 'disc'; ul.style.marginLeft = '20px';
					for (i = 0; i < items.length; i++) { li = d.createElement('li'); li.textContent = items[i]; ul.appendChild(li); }
					out.appendChild(ul);
				}
				function show(kind) { out.className = 'notice inline notice-' + kind; state.textContent = ''; btn.disabled = false; }
				function compare(html) {
					var doc = new DOMParser().parseFromString(html, 'text/html'), nodes = doc.querySelectorAll('.event');
					var pool = {}, bySlug = {}, cards = 0, i, a, c, r, day, tm, slug, key;
					for (i = 0; i < nodes.length; i++) {
						a = nodes[i].querySelector('a.title'); c = nodes[i].querySelector('.cell_1'); r = nodes[i].querySelector('.row_1');
						if (!a || !c || !r) continue;
						day = (c.textContent || '').replace(/\D/g, ''); tm = (r.textContent || '').match(/(\d{1,2}):(\d\d)/);
						slug = slugOf(a.getAttribute('href'));
						if (!day || !tm || !slug) continue;
						day = ('0' + day).slice(-2); tm = ('0' + tm[1]).slice(-2) + ':' + tm[2];
						key = slug + '|' + day + '|' + tm;
						pool[key] = (pool[key] || 0) + 1;
						(bySlug[slug] = bySlug[slug] || []).push(day + ' число, ' + tm);
						cards++;
					}
					out.textContent = '';
					if (!cards) {
						line('Карточки афиши на главной странице не найдены. Сверка невозможна: возможно, изменилась разметка темы.', true);
						show('warning');
						return;
					}
					var same = 0, wrong = [], absent = [], row;
					for (i = 0; i < data.rows.length; i++) {
						row = data.rows[i]; slug = String(row[0]).toLowerCase(); key = slug + '|' + String(row[1]).slice(8, 10) + '|' + row[2];
						if (pool[key] > 0) { pool[key]--; same++; }
						else if (bySlug[slug]) wrong.push(row[3] + ': в поиске ' + row[1] + ' ' + row[2] + ', на афише ' + bySlug[slug].join(' и '));
						else absent.push(row[3] + ' (' + row[1] + ' ' + row[2] + ')');
					}
					if (wrong.length) {
						line('Расхождения с афишей: ' + wrong.length + ' из ' + data.rows.length + ' концертов.', true);
						list(wrong.slice(0, 30));
						if (wrong.length > 30) line('и ещё ' + (wrong.length - 30) + '.');
						line('Нажмите «Пересобрать индекс сейчас», обновите эту страницу и повторите сверку. Если расхождения остались, выберите режим «Выключен».');
					} else {
						line('Совпало: ' + same + ' из ' + data.rows.length + '. День и время каждого концерта в поиске такие же, как на афише.', true);
					}
					if (absent.length) {
						line('Концертов из поиска, которых нет на главной странице: ' + absent.length + '. Так бывает, если концерт только что начался или афиша изменилась после сборки индекса.');
						list(absent.slice(0, 10));
					}
					if (!wrong.length && cards > same) line('Карточек на афише, которых нет в поиске: ' + (cards - same) + ' (например, концерты, закрытые паролем, или добавленные после сборки индекса).');
					show(wrong.length ? 'error' : (absent.length ? 'warning' : 'success'));
				}
				btn.addEventListener('click', function () {
					btn.disabled = true; out.textContent = ''; out.className = ''; state.textContent = 'Читаю афишу…';
					window.fetch(data.home, { credentials: 'same-origin', cache: 'no-store' })
						.then(function (r) { if (!r.ok) throw new Error('http ' + r.status); return r.text(); })
						.then(compare)
						.catch(function () { out.textContent = ''; line('Не удалось открыть главную страницу сайта. Повторите позже.', true); show('warning'); });
				});
			})();
			</script>
			<?php
		}
	}

endif;
