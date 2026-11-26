<?php
/**
 * Admin: Настройки → «Слайдер в Метрике». Switch, counter number, the list of goals to create in Metrika.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const KZSL_CAP = 'manage_options';

add_action( 'admin_menu', 'kzsl_admin_menu' );
function kzsl_admin_menu() {
	add_options_page( 'Слайдер в Метрике', 'Слайдер в Метрике', KZSL_CAP, 'kzsl', 'kzsl_admin_page' );
}

add_filter( 'plugin_action_links_' . plugin_basename( KZSL_FILE ), 'kzsl_action_links' );
function kzsl_action_links( $links ) {
	$links = is_array( $links ) ? $links : array();
	array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=kzsl' ) ) . '">Настройки</a>' );
	return $links;
}

add_action( 'admin_post_kzsl_save', 'kzsl_admin_save' );
function kzsl_admin_save() {
	if ( ! current_user_can( KZSL_CAP ) ) {
		wp_die( 'Недостаточно прав.' );
	}
	check_admin_referer( 'kzsl_save' );

	$counter = isset( $_POST['kzsl_counter'] ) ? preg_replace( '/\D/', '', sanitize_text_field( wp_unslash( $_POST['kzsl_counter'] ) ) ) : '';
	$s       = array(
		'enabled'      => empty( $_POST['kzsl_enabled'] ) ? 0 : 1,
		'counter'      => ( '' !== $counter && strlen( $counter ) <= 12 ) ? $counter : KZSL_DEFAULT_COUNTER,
		'skip_editors' => empty( $_POST['kzsl_skip_editors'] ) ? 0 : 1,
		'next'         => empty( $_POST['kzsl_next'] ) ? 0 : 1,
	);
	update_option( 'kzsl_settings', $s, false );
	kzsl_purge_page_cache();

	wp_safe_redirect( add_query_arg( array( 'page' => 'kzsl', 'updated' => 1 ), admin_url( 'options-general.php' ) ) );
	exit;
}

function kzsl_admin_page() {
	if ( ! current_user_can( KZSL_CAP ) ) {
		return;
	}
	$s       = kzsl_settings();
	$goals   = kzsl_goals();
	$metrika = 'https://metrika.yandex.ru/goals?id=' . rawurlencode( $s['counter'] );
	$debug   = add_query_arg( 'kzsl', 'debug', home_url( '/' ) );
	$about   = array(
		'kzsl_view'     => 'Слайдер был на экране хотя бы 1 секунду (раз за просмотр главной).',
		'kzsl_swipe'    => 'Посетитель сам листал слайдер: стрелки, вкладки, точки или свайп.',
		'kzsl_click'    => 'Любой клик по слайду: картинка, название, кнопка.',
		'kzsl_buy'      => 'Клик по кнопке «Купить билет», «Регистрация» или «Забронировать столик» прямо в слайдере.',
		'kzsl_buy_next' => 'После клика по слайдеру посетитель нажал кнопку покупки на другой странице (обычно на странице концерта).',
	);
	?>
	<div class="wrap kzsl-admin">
		<h1>Слайдер в Метрике</h1>
		<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<div class="notice notice-success is-dismissible"><p>Сохранено. Кэш страниц сброшен.</p></div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="kzsl_save">
			<?php wp_nonce_field( 'kzsl_save' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Работа плагина</th>
					<td><label><input type="checkbox" name="kzsl_enabled" value="1" <?php checked( ! empty( $s['enabled'] ) ); ?>> Включить: отправлять события слайдера в Метрику</label>
						<p class="description">Выключено: на сайт не выводится ни одной строки плагина.</p></td>
				</tr>
				<tr>
					<th scope="row"><label for="kzsl_counter">Номер счётчика</label></th>
					<td><input type="text" id="kzsl_counter" name="kzsl_counter" value="<?php echo esc_attr( $s['counter'] ); ?>" class="regular-text" inputmode="numeric" pattern="[0-9]*"></td>
				</tr>
				<tr>
					<th scope="row">Редакторы</th>
					<td><label><input type="checkbox" name="kzsl_skip_editors" value="1" <?php checked( ! empty( $s['skip_editors'] ) ); ?>> Не отправлять события, когда на сайте вошедший редактор или администратор</label></td>
				</tr>
				<tr>
					<th scope="row">Покупка на следующей странице</th>
					<td><label><input type="checkbox" name="kzsl_next" value="1" <?php checked( ! empty( $s['next'] ) ); ?>> Учитывать кнопку покупки на странице концерта после клика по слайдеру</label>
						<p class="description">Для этого небольшой скрипт (около 6 КБ в сжатом виде, загружается после страницы и не задерживает её) подключается на всех страницах сайта, а не только на главной. На страницах без клика по слайдеру он ничего не отправляет.</p></td>
				</tr>
			</table>
			<?php submit_button( 'Сохранить' ); ?>
		</form>

		<h2>Цели, которые нужно создать в Метрике</h2>
		<p>Метрика → <a href="<?php echo esc_url( $metrika ); ?>" target="_blank" rel="noopener">Цели</a> → Добавить цель → «JavaScript-событие» → условие «совпадает» → идентификатор из таблицы. Цель считает события только с момента своего создания, поэтому создайте цели до установки плагина или сразу после.</p>
		<table class="widefat striped" style="max-width:960px">
			<thead><tr><th>Название цели</th><th>Идентификатор</th><th>Что считает</th></tr></thead>
			<tbody>
			<?php foreach ( $goals as $id => $name ) : ?>
				<tr><td><?php echo esc_html( $name ); ?></td><td><code><?php echo esc_html( $id ); ?></code></td><td><?php echo esc_html( isset( $about[ $id ] ) ? $about[ $id ] : '' ); ?></td></tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<h2>Как читать</h2>
		<ol style="max-width:960px">
			<li><strong>Клики и показы по устройствам.</strong> Отчёты → Технологии → Устройства, в настройке «Цели» выбрать «Слайдер: показ» и «Слайдер: клик». CTR слайдера = визиты с кликом / визиты с показом.</li>
			<li><strong>Какой слайд работает.</strong> Отчёты → Содержание → Параметры визитов → «Слайдер на главной». Внутри: вариант (Десктоп, Телефон · фасад, Телефон · театр теней, Планшет, Телефон · обычный слайдер) → «Показ слайда» и «Клик» по каждому слайду.</li>
			<li><strong>Оплаты.</strong> Оплата в виджете Ticketscloud часто попадает в отдельный визит, который начинается на ticketscloud.com (в ноябре 2026 так было с 55% визитов с оплатой). Поэтому оплаты смотрите по людям, а не по визитам: в отчёте нажмите «+» рядом с «Люди» → Поведение → Параметры визита → «Слайдер на главной» → нужный вариант → «Клик». Затем выберите цели «Получение оплаты» и «Ecommerce: покупка». Для сравнения сделайте такой же сегмент по «Показ слайдера»: это все, кто видел слайдер.</li>
		</ol>

		<h2>Проверка без отправки в Метрику</h2>
		<p>Откройте <a href="<?php echo esc_url( $debug ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $debug ); ?></a> на телефоне или компьютере. Внизу экрана появится панель с событиями слайдера. В этом режиме в Метрику ничего не уходит.</p>
		<p class="description">Версия <?php echo esc_html( KZSL_VER ); ?>.</p>
	</div>
	<?php
}
