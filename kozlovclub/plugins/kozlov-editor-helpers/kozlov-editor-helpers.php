<?php
/**
 * Plugin Name: Kozlov Editor Helpers
 * Description: Помощь редакторам афиши. Когда в анонсе вписывают код события Ticketscloud, поле «Ticketscloud ID» заполняется стандартным значением. Работает только в админке, тему, Magic Fields и данные концертов не меняет.
 * Version: 1.0.2
 * Requires at least: 5.0
 * Requires PHP: 7.0
 * Author: Kozlov Club
 * License: GPLv2 or later
 * Text Domain: kozlov-editor-helpers
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'KEH_VERSION', '1.0.2' );
define( 'KEH_FILE', __FILE__ );

final class KEH_Plugin {

	const OPTION        = 'keh_settings';
	const PAGE          = 'kozlov-editor-helpers';
	// Полный токен виджета клуба: тот же, что тема выводит в шапке сайта (window.ticketscloudid).
	const DEFAULT_TC_ID = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiIsImlzcyI6InRpY2tldHNjbG91ZC5ydSJ9.eyJwIjoiNWNlZmE4MTUxMzFjMGQwMDBmOWU5YmI1In0.UO8dYRJNhMsXTk2fspJJuF_yGo4LggOkQadFqtsXZWc';
	// Значение по умолчанию в 1.0.0–1.0.1: только заголовок токена.
	const OLD_DEFAULT_TC_ID = 'eyJhbGciOiJIUzI1NiIsImlzcyI6InRpY2tldHNjbG91ZC5ydSIsInR5cCI6IkpXVCJ9';

	/** @var bool */
	private static $print_config = false;

	public static function init() {
		if ( ! is_admin() ) {
			return; // На публичных страницах плагин не делает ничего.
		}
		add_action( 'admin_init', array( __CLASS__, 'maybe_upgrade' ), 5 );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_footer', array( __CLASS__, 'print_config' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( KEH_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/* ---------- Настройки ---------- */

	public static function defaults() {
		return array(
			'enabled' => 1,
			'tc_id'   => self::DEFAULT_TC_ID,
		);
	}

	public static function settings() {
		$saved = get_option( self::OPTION );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return array_merge( self::defaults(), array_intersect_key( $saved, self::defaults() ) );
	}

	/** Если в настройке осталось старое короткое значение по умолчанию, меняем его на полный токен. Пишет только свою настройку. */
	public static function maybe_upgrade() {
		$o = get_option( self::OPTION );
		if ( is_array( $o ) && isset( $o['tc_id'] ) && self::OLD_DEFAULT_TC_ID === $o['tc_id'] ) {
			$o['tc_id'] = self::DEFAULT_TC_ID;
			update_option( self::OPTION, $o );
		}
	}

	/** Организатор из токена Ticketscloud (поле p в середине JWT). null, если токен без этой части. */
	public static function organizer( $token ) {
		$parts = explode( '.', (string) $token );
		if ( count( $parts ) < 2 ) {
			return null;
		}
		$b64  = strtr( $parts[1], '-_', '+/' );
		$json = base64_decode( $b64 . str_repeat( '=', ( 4 - strlen( $b64 ) % 4 ) % 4 ), true );
		$data = $json ? json_decode( $json, true ) : null;
		return ( is_array( $data ) && isset( $data['p'] ) && is_string( $data['p'] ) ) ? $data['p'] : null;
	}

	/** Имена полей Magic Fields. Совпадают с ключами в wp_postmeta. */
	public static function fields() {
		$f = apply_filters(
			'keh_ticketscloud_fields',
			array(
				'code' => 'ticketscloud_link',
				'id'   => 'ticketcloudid',
			)
		);
		return array(
			'code' => isset( $f['code'] ) ? (string) $f['code'] : 'ticketscloud_link',
			'id'   => isset( $f['id'] ) ? (string) $f['id'] : 'ticketcloudid',
		);
	}

	public static function post_types() {
		return (array) apply_filters( 'keh_post_types', array( 'ai1ec_event' ) );
	}

	public static function register_settings() {
		register_setting(
			self::PAGE,
			self::OPTION,
			array( 'sanitize_callback' => array( __CLASS__, 'sanitize' ) )
		);
	}

	public static function sanitize( $input ) {
		$current = self::settings();
		$out     = $current;
		if ( ! is_array( $input ) ) {
			return $out;
		}

		$out['enabled'] = empty( $input['enabled'] ) ? 0 : 1;

		$raw = isset( $input['tc_id'] ) ? trim( wp_unslash( (string) $input['tc_id'] ) ) : '';
		if ( '' === $raw ) {
			add_settings_error( self::OPTION, 'keh_empty', 'Значение Ticketscloud ID не может быть пустым. Оставлено прежнее.' );
		} elseif ( strlen( $raw ) > 512 || ! preg_match( '/^[A-Za-z0-9_\-\.=\+\/]+$/', $raw ) ) {
			add_settings_error( self::OPTION, 'keh_invalid', 'В значении Ticketscloud ID есть недопустимые символы (пробелы, кавычки, кириллица). Оставлено прежнее.' );
		} else {
			$out['tc_id'] = $raw;
		}
		return $out;
	}

	public static function add_menu() {
		add_options_page(
			'Kozlov: помощь редакторам',
			'Kozlov: редакторам',
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function action_links( $links ) {
		$url = admin_url( 'options-general.php?page=' . self::PAGE );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">Настройки</a>' );
		return $links;
	}

	/** Только чтение: ищет оба поля в таблице Magic Fields, чтобы на живом сайте было видно, что имена верны. */
	private static function diagnose_fields() {
		global $wpdb;
		$table = defined( 'MF_TABLE_CUSTOM_FIELDS' ) ? MF_TABLE_CUSTOM_FIELDS : $wpdb->prefix . 'mf_custom_fields';
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $found !== $table ) {
			return null;
		}
		$f    = self::fields();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT name, label, type, post_type FROM `{$table}` WHERE name IN (%s, %s)",
				$f['code'],
				$f['id']
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	private static function events_table() {
		global $wpdb;
		$t = $wpdb->prefix . 'ai1ec_events';
		return ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t ) ? $t : null;
	}

	/** Только чтение: какие значения ID сохранены у анонсов, всего и у будущих опубликованных. */
	private static function diagnose_values() {
		global $wpdb;
		$f      = self::fields();
		$events = self::events_table();
		if ( $events ) {
			$sql = $wpdb->prepare(
				"SELECT m.meta_value AS v, COUNT(*) AS n,
					SUM(CASE WHEN p.post_status = 'publish' AND e.start > %d THEN 1 ELSE 0 END) AS future
				FROM {$wpdb->postmeta} m
				LEFT JOIN {$wpdb->posts} p ON p.ID = m.post_id
				LEFT JOIN `{$events}` e ON e.post_id = m.post_id
				WHERE m.meta_key = %s
				GROUP BY m.meta_value ORDER BY n DESC LIMIT 8",
				time(),
				$f['id']
			);
		} else {
			$sql = $wpdb->prepare(
				"SELECT meta_value AS v, COUNT(*) AS n, NULL AS future FROM {$wpdb->postmeta} WHERE meta_key = %s GROUP BY meta_value ORDER BY n DESC LIMIT 8",
				$f['id']
			);
		}
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/** Только чтение: будущие опубликованные концерты, у которых в ID токен другого организатора. */
	private static function future_other_org( $vals, $settings_org ) {
		global $wpdb;
		$events = self::events_table();
		if ( ! $events || null === $settings_org ) {
			return array();
		}
		$f   = self::fields();
		$out = array();
		foreach ( $vals as $v ) {
			$org = self::organizer( $v['v'] );
			if ( null === $org || $org === $settings_org || (int) $v['future'] < 1 ) {
				continue;
			}
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT p.ID AS id, p.post_title AS title, e.start AS start
					FROM {$wpdb->postmeta} m
					JOIN {$wpdb->posts} p ON p.ID = m.post_id
					JOIN `{$events}` e ON e.post_id = m.post_id
					WHERE m.meta_key = %s AND m.meta_value = %s AND p.post_status = 'publish' AND e.start > %d
					ORDER BY e.start LIMIT 20",
					$f['id'],
					$v['v'],
					time()
				),
				ARRAY_A
			);
			if ( is_array( $rows ) ) {
				$out = array_merge( $out, $rows );
			}
		}
		return $out;
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s    = self::settings();
		$f    = self::fields();
		$rows = self::diagnose_fields();
		?>
		<div class="wrap">
			<h1>Kozlov: помощь редакторам</h1>
			<?php settings_errors( self::OPTION ); ?>
			<form method="post" action="options.php">
				<?php settings_fields( self::PAGE ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Автоподстановка</th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[enabled]" value="1" <?php checked( 1, (int) $s['enabled'] ); ?> />
								Заполнять «Ticketscloud ID», когда редактор вписывает код события
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="keh-tc-id">Ticketscloud ID по умолчанию</label></th>
						<td>
							<input type="text" id="keh-tc-id" class="large-text code" name="<?php echo esc_attr( self::OPTION ); ?>[tc_id]" value="<?php echo esc_attr( $s['tc_id'] ); ?>" />
							<p class="description">
								Стандартное значение: полный токен виджета клуба, тот же, что стоит в шапке сайта. Если Ticketscloud его поменяет, впишите новое и сохраните. Уже сохранённые концерты не меняются.
								<button type="button" class="button-link" onclick="document.getElementById('keh-tc-id').value=this.getAttribute('data-v');" data-v="<?php echo esc_attr( self::DEFAULT_TC_ID ); ?>">Вернуть стандартное</button>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2>Как работает</h2>
			<ul style="list-style:disc;padding-left:20px;max-width:780px">
				<li>Редактор вписывает код события в поле «<?php echo esc_html( $f['code'] ); ?>», а поле «<?php echo esc_html( $f['id'] ); ?>» пустое: оно заполняется значением выше. Значение видно и его можно изменить вручную.</li>
				<li>Редактор стирает код события: ID, который плагин сам подставил в этом сеансе, убирается. ID, который был раньше, не трогается: под полем появляется предупреждение и кнопка «Очистить ID».</li>
				<li>ID подставляется только в однозначную пару полей (та же группа Magic Fields). Если пара неоднозначна, плагин ничего не делает.</li>
				<li>При открытии анонса ничего не меняется. Срабатывает только на действие редактора с полем кода. Сохраняет как обычно Magic Fields, плагин сам в базу концертов не пишет.</li>
			</ul>

			<h2>Проверка полей</h2>
			<?php if ( null === $rows ) : ?>
				<p>Таблица Magic Fields не найдена. Подстановка работать не будет, сайт это не затрагивает.</p>
			<?php else : ?>
				<table class="widefat striped" style="max-width:780px">
					<thead><tr><th>Поле</th><th>Подпись</th><th>Тип</th><th>Тип записи</th></tr></thead>
					<tbody>
					<?php
					foreach ( array( $f['code'], $f['id'] ) as $name ) {
						$hit = null;
						foreach ( $rows as $r ) {
							if ( $r['name'] === $name ) {
								$hit = $r;
								break;
							}
						}
						echo '<tr><td><code>' . esc_html( $name ) . '</code></td>';
						if ( $hit ) {
							echo '<td>' . esc_html( $hit['label'] ) . '</td><td>' . esc_html( $hit['type'] ) . '</td><td>' . esc_html( $hit['post_type'] ) . '</td>';
						} else {
							echo '<td colspan="3"><strong style="color:#b32d2e">не найдено</strong></td>';
						}
						echo '</tr>';
					}
					?>
					</tbody>
				</table>
				<p class="description">Нормально: оба поля найдены, тип <code>textbox</code>, тип записи <code>ai1ec_event</code>.</p>
			<?php endif; ?>

			<h2>Значения ID, уже сохранённые в анонсах</h2>
			<?php
			$vals         = self::diagnose_values();
			$settings_org = self::organizer( $s['tc_id'] );
			?>
			<?php if ( empty( $vals ) ) : ?>
				<p>Сохранённых значений нет.</p>
			<?php else : ?>
				<table class="widefat striped" style="max-width:980px">
					<thead><tr><th>Значение</th><th>Что это</th><th>Всего</th><th>Будущих</th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $vals as $v ) : ?>
						<?php
						$val = (string) $v['v'];
						$org = self::organizer( $val );
						if ( '' === $val ) {
							$what = 'пусто: без продажи';
						} elseif ( null === $org ) {
							$what = 'неполный токен (только заголовок)';
						} elseif ( $org === $settings_org ) {
							$what = 'организатор ' . esc_html( substr( $org, 0, 8 ) ) . '…, как в настройке';
						} else {
							$what = '<strong style="color:#b32d2e">другой организатор ' . esc_html( substr( $org, 0, 8 ) ) . '…</strong>';
						}
						?>
						<tr>
							<td><code style="word-break:break-all;font-size:11px"><?php echo '' === $val ? '(пусто)' : esc_html( $val ); ?></code></td>
							<td><?php echo $what; // Собрано выше из экранированных частей. ?></td>
							<td><?php echo (int) $v['n']; ?></td>
							<td><?php echo null === $v['future'] ? '—' : (int) $v['future']; ?></td>
							<td><?php echo ( $val === $s['tc_id'] ) ? '<strong>совпадает с настройкой</strong>' : ''; ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description">Для кнопки «Купить» тема берёт общий токен из шапки сайта, а поле в анонсе служит флажком «показать кнопку». Поэтому разные значения в старых анонсах продаже не мешают. Важна только строка «другой организатор» у будущих концертов: см. ниже.</p>

				<h2>Будущие концерты с токеном другого организатора</h2>
				<?php $other = self::future_other_org( $vals, $settings_org ); ?>
				<?php if ( null === self::events_table() ) : ?>
					<p>Таблица событий календаря не найдена, проверить нельзя.</p>
				<?php elseif ( empty( $other ) ) : ?>
					<p><strong>Нет.</strong> Все такие анонсы в прошлом, делать ничего не нужно.</p>
				<?php else : ?>
					<p>Эти концерты могут продаваться через другой аккаунт Ticketscloud. Проверьте кнопку «Купить» на их страницах.</p>
					<ul style="list-style:disc;padding-left:20px">
					<?php foreach ( $other as $e ) : ?>
						<li><a href="<?php echo esc_url( get_edit_post_link( (int) $e['id'] ) ); ?>"><?php echo esc_html( $e['title'] ); ?></a>, <?php echo esc_html( gmdate( 'd.m.Y H:i', (int) $e['start'] ) ); ?></li>
					<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ---------- Редактор анонса ---------- */

	private static function is_event_editor( $hook ) {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return false;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen && in_array( $screen->post_type, self::post_types(), true );
	}

	public static function enqueue( $hook ) {
		$s = self::settings();
		if ( empty( $s['enabled'] ) || '' === $s['tc_id'] || ! self::is_event_editor( $hook ) ) {
			return;
		}
		self::$print_config = true;
		wp_enqueue_script(
			'keh-editor',
			plugins_url( 'assets/keh-editor.js', KEH_FILE ),
			array(),
			KEH_VERSION,
			true
		);
	}

	public static function print_config() {
		if ( ! self::$print_config ) {
			return;
		}
		$s   = self::settings();
		$f   = self::fields();
		$cfg = array(
			'codeField'   => $f['code'],
			'idField'     => $f['id'],
			'defaultId'   => $s['tc_id'],
			'settingsUrl' => current_user_can( 'manage_options' ) ? admin_url( 'options-general.php?page=' . self::PAGE ) : '',
		);
		echo '<script type="application/json" id="keh-config">' . wp_json_encode( $cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . '</script>' . "\n";
	}
}

add_action( 'plugins_loaded', array( 'KEH_Plugin', 'init' ) );
