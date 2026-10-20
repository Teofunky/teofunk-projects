<?php
/**
 * Plugin Name: Kozlov Editor Helpers
 * Description: Помощь редакторам афиши. Когда в анонсе вписывают код события Ticketscloud, поле «Ticketscloud ID» заполняется стандартным значением. Работает только в админке, тему, Magic Fields и данные концертов не меняет.
 * Version: 1.0.1
 * Requires at least: 5.0
 * Requires PHP: 7.0
 * Author: Kozlov Club
 * License: GPLv2 or later
 * Text Domain: kozlov-editor-helpers
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'KEH_VERSION', '1.0.1' );
define( 'KEH_FILE', __FILE__ );

final class KEH_Plugin {

	const OPTION        = 'keh_settings';
	const PAGE          = 'kozlov-editor-helpers';
	const DEFAULT_TC_ID = 'eyJhbGciOiJIUzI1NiIsImlzcyI6InRpY2tldHNjbG91ZC5ydSIsInR5cCI6IkpXVCJ9';

	/** @var bool */
	private static $print_config = false;

	public static function init() {
		if ( ! is_admin() ) {
			return; // На публичных страницах плагин не делает ничего.
		}
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

	/** Только чтение: какие значения ID уже сохранены у анонсов (до 5 самых частых). */
	private static function diagnose_values() {
		global $wpdb;
		$f    = self::fields();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_value AS v, COUNT(*) AS n FROM {$wpdb->postmeta} WHERE meta_key = %s GROUP BY meta_value ORDER BY n DESC LIMIT 5",
				$f['id']
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
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
								Если Ticketscloud поменяет значение, впишите новое и сохраните. Уже сохранённые концерты не меняются.
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
			<?php $vals = self::diagnose_values(); ?>
			<?php if ( empty( $vals ) ) : ?>
				<p>Сохранённых значений нет.</p>
			<?php else : ?>
				<table class="widefat striped" style="max-width:780px">
					<thead><tr><th>Значение</th><th>Анонсов</th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $vals as $v ) : ?>
						<tr>
							<td><code style="word-break:break-all"><?php echo '' === (string) $v['v'] ? '(пусто)' : esc_html( $v['v'] ); ?></code></td>
							<td><?php echo (int) $v['n']; ?></td>
							<td><?php echo ( (string) $v['v'] === $s['tc_id'] ) ? '<strong>совпадает с настройкой</strong>' : ''; ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description">Нормально: почти все анонсы с ID хранят одно значение, и оно совпадает с настройкой. Если значений несколько, не включайте подстановку, пока не разберёмся.</p>
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
