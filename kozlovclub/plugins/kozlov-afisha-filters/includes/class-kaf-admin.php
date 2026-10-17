<?php
/**
 * Страница настроек: Настройки → Kozlov Afisha Filters. Режим панели и состояние связки с Kozlov Smart Search.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'KAF_Admin' ) ) :

	class KAF_Admin {

		const SLUG = 'kozlov-afisha-filters';

		public static function init() {
			add_action( 'admin_init', array( __CLASS__, 'register' ) );
			add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
			add_filter( 'plugin_action_links_' . plugin_basename( KAF_FILE ), array( __CLASS__, 'links' ) );
			add_action( 'add_option_' . KAF_Settings::OPTION, array( __CLASS__, 'added' ), 10, 2 );
			add_action( 'update_option_' . KAF_Settings::OPTION, array( __CLASS__, 'updated' ), 10, 2 );
		}

		/** Первое сохранение настроек. */
		public static function added( $option, $value ) {
			self::mode_changed( 'off', $value );
		}

		/** Повторное сохранение настроек. */
		public static function updated( $old, $value ) {
			self::mode_changed( is_array( $old ) && isset( $old['mode'] ) ? (string) $old['mode'] : 'off', $value );
		}

		/**
		 * Панель попадает в кэш страниц только в режиме «Включена для всех».
		 * Когда этот режим включают или выключают, кэш страниц сбрасывается, чтобы посетители сразу увидели изменение.
		 */
		protected static function mode_changed( $old_mode, $value ) {
			try {
				$new_mode = is_array( $value ) && isset( $value['mode'] ) ? (string) $value['mode'] : 'off';
				if ( ( 'on' === $old_mode ) === ( 'on' === $new_mode ) ) {
					return;
				}
				$done = kaf_purge_page_cache();
				if ( function_exists( 'add_settings_error' ) ) {
					add_settings_error(
						'kaf',
						'kaf-cache',
						$done ? 'Кэш страниц W3 Total Cache сброшен: посетители сразу увидят изменение.' : 'Если на сайте включён кэш страниц, сбросьте его, чтобы посетители увидели изменение.',
						$done ? 'updated' : 'warning'
					);
				}
			} catch ( \Throwable $e ) {
				kaf_log( $e );
			}
		}

		public static function register() {
			try {
				register_setting(
					'kaf',
					KAF_Settings::OPTION,
					array(
						'type'              => 'array',
						'sanitize_callback' => array( 'KAF_Settings', 'sanitize' ),
						'default'           => KAF_Settings::defaults(),
						'show_in_rest'      => false,
					)
				);
			} catch ( \Throwable $e ) {
				kaf_log( $e );
			}
		}

		public static function menu() {
			add_options_page( 'Kozlov Afisha Filters', 'Kozlov Afisha Filters', 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
		}

		public static function links( $links ) {
			if ( is_array( $links ) ) {
				array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=' . self::SLUG ) ) . '">Настройки</a>' );
			}
			return $links;
		}

		public static function render() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
			try {
				$mode     = KAF_Settings::mode();
				$kss_ok   = KAF_Settings::kss_ok();
				$kss_mode = KAF_Settings::kss_mode();
				$kss_name = array(
					'off'   => 'Выключен',
					'admin' => 'Проверка (только администраторы)',
					'on'    => 'Включён для всех',
				);
				$modes    = array(
					'off'     => array( 'Выключена', 'Панели на сайте нет. Афиша главной страницы такая же, как без плагина.' ),
					'preview' => array( 'Проверка: видна только администраторам', 'Панель видит только вошедший администратор. Посетители видят обычную афишу.' ),
					'on'      => array( 'Включена для всех', 'Панель видят все посетители главной страницы.' ),
				);
				?>
				<div class="wrap">
					<h1>Kozlov Afisha Filters</h1>
					<p>Панель фильтров под слайдером главной страницы: Сегодня, Завтра, Выходные, Дата, Жанр, Цена, Сцена и поиск по названию.
						Панель только прячет и показывает карточки афиши темы, ничего не меняет в событиях, теме и базе.</p>

					<?php if ( ! $kss_ok ) : ?>
						<div class="notice notice-warning inline"><p><strong>Нужен Kozlov Smart Search версии <?php echo esc_html( KAF_Settings::KSS_NEED ); ?> или новее.</strong>
							Панель берёт концерты из его индекса. Пока его нет, панель на сайте не появится в любом режиме.</p></div>
					<?php elseif ( 'off' === $kss_mode ) : ?>
						<div class="notice notice-warning inline"><p><strong>Kozlov Smart Search выключен.</strong> Без него панель не появится: включите поиск хотя бы в режиме «Проверка».</p></div>
					<?php endif; ?>

					<form method="post" action="options.php">
						<?php settings_fields( 'kaf' ); ?>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row">Режим</th>
								<td>
									<fieldset>
										<?php foreach ( $modes as $value => $text ) : ?>
											<p><label>
												<input type="radio" name="<?php echo esc_attr( KAF_Settings::OPTION ); ?>[mode]" value="<?php echo esc_attr( $value ); ?>" <?php checked( $mode, $value ); ?>>
												<strong><?php echo esc_html( $text[0] ); ?></strong>
											</label><br><span class="description"><?php echo esc_html( $text[1] ); ?></span></p>
										<?php endforeach; ?>
									</fieldset>
									<p>Когда режим «Включена для всех» включают или выключают, плагин сам сбрасывает кэш страниц W3 Total Cache.
										Если кэш на сайте другой, сбросьте его вручную.</p>
								</td>
							</tr>
						</table>
						<?php submit_button( 'Сохранить' ); ?>
					</form>

					<h2>Состояние</h2>
					<table class="widefat striped" style="max-width:960px">
						<tbody>
							<tr><td style="width:300px">Версия панели</td><td><?php echo esc_html( KAF_VERSION ); ?></td></tr>
							<tr><td>Kozlov Smart Search</td><td><?php echo esc_html( defined( 'KSS_VERSION' ) ? KSS_VERSION : 'не найден' ); ?><?php echo $kss_ok ? '' : ' (нужна ' . esc_html( KAF_Settings::KSS_NEED ) . ' или новее)'; ?></td></tr>
							<tr><td>Режим поиска</td><td><?php echo esc_html( isset( $kss_name[ $kss_mode ] ) ? $kss_name[ $kss_mode ] : 'неизвестен' ); ?></td></tr>
							<tr><td>Кто видит панель сейчас</td><td>
								<?php
								if ( 'off' === $mode || ! $kss_ok || ( 'on' !== $kss_mode && 'admin' !== $kss_mode ) ) {
									echo 'никто';
								} elseif ( 'preview' === $mode || 'admin' === $kss_mode ) {
									echo 'только вошедшие администраторы';
								} else {
									echo 'все посетители главной страницы';
								}
								?>
							</td></tr>
						</tbody>
					</table>

					<h2>Как проверить</h2>
					<ol>
						<li>Режим «Проверка» → Сохранить → откройте <a href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank" rel="noopener">главную страницу</a> в этом же браузере.</li>
						<li>Под слайдером должна появиться панель. Нажмите «Сегодня», «Выходные», выберите дату, жанр, цену и сцену: под панелью остаются только подходящие карточки.</li>
						<li>«Сбросить фильтры» возвращает афишу целиком, как без плагина. Проверьте телефон: кнопки в две строки, окна открываются шторкой снизу.</li>
						<li>Скопируйте адрес страницы с выбранными фильтрами и откройте в новой вкладке: выбор сохранится.</li>
					</ol>

					<h2>Если что-то пошло не так</h2>
					<ol>
						<li>Выберите режим «Выключена» и сохраните: на сайте сразу останется обычная афиша.</li>
						<li>Если админка недоступна, добавьте в <code>wp-config.php</code> строку <code>define( 'KAF_DISABLE', true );</code> или удалите папку <code>wp-content/plugins/kozlov-afisha-filters</code>, затем сбросьте кэш страниц (Performance → Purge All Caches).</li>
						<li>Если Kozlov Smart Search выключен или отвечает ошибкой, панель сама убирается, в том числе в уже открытых вкладках: афиша остаётся обычной.</li>
					</ol>
					<p>Плагин ничего не записывает в события, страницы и таблицы календаря и не делает своих запросов к базе на сайте.
						Его данные: одна запись настроек (<code><?php echo esc_html( KAF_Settings::OPTION ); ?></code>). При удалении плагина она стирается.</p>
				</div>
				<?php
			} catch ( \Throwable $e ) {
				kaf_log( $e );
				echo '<div class="notice notice-error"><p>Страница настроек не смогла открыться. Подробности в журнале ошибок PHP.</p></div>';
			}
		}
	}

endif;
