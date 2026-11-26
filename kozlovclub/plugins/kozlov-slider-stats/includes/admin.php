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
		'kzsl_view'       => 'Слайдер был на экране хотя бы 1 секунду (раз за просмотр главной).',
		'kzsl_slide_view' => 'Слайд был текущим 1 секунду, пока слайдер на экране (каждый слайд раз за просмотр главной).',
		'kzsl_swipe'      => 'Посетитель сам листал слайдер: стрелки, вкладки, точки, свайп или перетаскивание.',
		'kzsl_click'      => 'Любой клик по слайду: картинка, название, кнопка.',
		'kzsl_buy'        => 'Нажатие кнопки «Купить билет», «Регистрация» или «Забронировать столик» прямо в слайдере. Это нажатие, а не оплата.',
		'kzsl_buy_next'   => 'После клика по слайдеру посетитель перешёл на другую страницу и нажал там кнопку покупки (обычно на странице концерта). Это нажатие, а не оплата.',
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
						<p class="description">Для этого небольшой скрипт (около 8 КБ в сжатом виде, загружается после страницы и не задерживает её) подключается на всех страницах сайта, а не только на главной. На страницах без клика по слайдеру он ничего не отправляет.</p></td>
				</tr>
			</table>
			<?php submit_button( 'Сохранить' ); ?>
		</form>

		<h2>Цели, которые нужно создать в Метрике</h2>
		<p>Метрика → <a href="<?php echo esc_url( $metrika ); ?>" target="_blank" rel="noopener">Цели</a> → Добавить цель → «JavaScript-событие» → условие «совпадает» → идентификатор из таблицы. <strong>Создайте все цели до установки плагина:</strong> цель считает события только с момента создания, а параметры события, пришедшего раньше цели, в отчёт не попадают.</p>
		<table class="widefat striped" style="max-width:960px">
			<thead><tr><th>Название цели</th><th>Идентификатор</th><th>Что считает</th></tr></thead>
			<tbody>
			<?php foreach ( $goals as $id => $name ) : ?>
				<tr><td><?php echo esc_html( $name ); ?></td><td><code><?php echo esc_html( $id ); ?></code></td><td><?php echo esc_html( isset( $about[ $id ] ) ? $about[ $id ] : '' ); ?></td></tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<h2>Как читать</h2>
		<p>Каждый слайд в параметрах назван адресом своей страницы: <code>nani-eva-skazochnyj-novyj-god</code>, <code>novyj-god</code>, <code>gazeta</code>. Адрес одинаковый на десктопе и телефоне и не повторяется у разных концертов.</p>
		<ol style="max-width:960px">
			<li><strong>Слайдер по устройствам.</strong> Отчёты → Технологии → Устройства, цели «Слайдер: показ» и «Слайдер: клик». CTR слайдера = визиты с кликом / визиты с показом.</li>
			<li><strong>Какой слайд работает.</strong> Отчёты → Содержание → «Параметры цели», цель «Слайдер: показ слайда», потом «Слайдер: клик». Внутри «Слайдер на главной»: вариант (Десктоп, Планшет, Телефон · фасад, Телефон · театр теней, Телефон · обычный слайдер) → действие → адрес слайда. CTR слайда = клики / показы этого слайда.</li>
			<li><strong>Оплаты.</strong> Кнопка покупки ещё не оплата. Оплата идёт в виджете Ticketscloud и часто попадает в отдельный визит, который начинается на ticketscloud.com (с 27.10 по 26.11.2026 так было у 55% визитов с оплатой). Поэтому смотрите по людям: в отчёте «+» рядом с «Люди» → Поведение → Параметры визита → «Слайдер на главной» → вариант → «Клик». Затем цели «Получение оплаты» и «Ecommerce: покупка». Для сравнения такой же сегмент по «Показ слайдера». Это связь, а не доказательство, что продажу дал именно слайдер.</li>
		</ol>

		<h2>Проверка без отправки в Метрику</h2>
		<p>Откройте <a href="<?php echo esc_url( $debug ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $debug ); ?></a> на телефоне или компьютере. Внизу экрана появится панель с событиями слайдера. Режим держится во вкладке и на следующих страницах (так видно и кнопку покупки на странице концерта), пока не откроете адрес с <code>?kzsl=stop</code>. В этом режиме в Метрику ничего не уходит, а клики по слайдеру запоминаются отдельно от настоящих.</p>
		<p class="description">Версия <?php echo esc_html( KZSL_VER ); ?>.</p>
	</div>
	<?php
}
