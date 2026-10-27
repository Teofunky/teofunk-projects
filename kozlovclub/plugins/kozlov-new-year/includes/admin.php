<?php
/**
 * Редактор страницы в админке: Меню «Новый год».
 *
 * Права: редактировать содержимое могут роли с edit_pages (редактор, администратор),
 * технические настройки и коды счётчиков только администратор.
 *
 * @package Kozlov_New_Year
 */

defined( 'ABSPATH' ) || exit;

const KNY_SLUG = 'kozlov-new-year';

add_action( 'admin_menu', 'kny_admin_menu' );
add_action( 'admin_init', 'kny_maybe_setup' );
add_action( 'admin_enqueue_scripts', 'kny_admin_assets' );
add_filter( 'plugin_action_links_' . plugin_basename( KNY_FILE ), 'kny_action_links' );
add_filter( 'display_post_states', 'kny_post_state', 10, 2 );

foreach ( array( 'save', 'preview', 'preview_version', 'restore', 'export', 'import', 'reset', 'settings', 'publish', 'create_page', 'detect' ) as $kny_action ) {
	add_action( 'admin_post_kny_' . $kny_action, 'kny_handle_' . $kny_action );
}
unset( $kny_action );

/**
 * Если плагин скопировали файлами без активации, донастраиваемся при первом заходе в админку.
 */
function kny_maybe_setup() {
	if ( false === get_option( 'kny_settings', false ) && current_user_can( 'activate_plugins' ) ) {
		kny_activate();
	}
	// После обновления плагина убираем из кэша старую копию страницы.
	if ( KNY_VERSION !== get_option( 'kny_version' ) && current_user_can( 'edit_pages' ) ) {
		$s = kny_get_settings();
		kny_flush_object_caches();
		kny_purge_cache( (int) $s['page_id'] );
		update_option( 'kny_version', KNY_VERSION, false );
	}
}

/**
 * Пункт меню.
 */
function kny_admin_menu() {
	add_menu_page( 'Новый год', 'Новый год', 'edit_pages', KNY_SLUG, 'kny_admin_page', 'dashicons-star-filled', 21 );
}

/**
 * Ссылка «Редактировать» в списке плагинов.
 *
 * @param array $links Ссылки.
 * @return array
 */
function kny_action_links( $links ) {
	array_unshift( $links, '<a href="' . esc_url( kny_admin_url() ) . '">Редактировать страницу</a>' );
	return $links;
}

/**
 * Пометка в списке страниц.
 *
 * @param array   $states Пометки.
 * @param WP_Post $post   Страница.
 * @return array
 */
function kny_post_state( $states, $post ) {
	$s = kny_get_settings();
	if ( (int) $s['page_id'] === (int) $post->ID ) {
		$states['kny'] = 'Новогодняя страница (меню «Новый год»)';
	}
	return $states;
}

/**
 * Адрес страницы редактора.
 *
 * @param array $args Параметры.
 * @return string
 */
function kny_admin_url( $args = array() ) {
	return add_query_arg( array_merge( array( 'page' => KNY_SLUG ), $args ), admin_url( 'admin.php' ) );
}

/**
 * Стили и скрипты только на странице редактора.
 *
 * @param string $hook Экран.
 */
function kny_admin_assets( $hook ) {
	if ( 'toplevel_page_' . KNY_SLUG !== $hook ) {
		return;
	}
	wp_enqueue_media();
	$ver = KNY_VERSION . '.' . (int) filemtime( KNY_DIR . 'assets/js/admin.js' );
	wp_enqueue_style( 'kny-admin', KNY_URL . 'assets/css/admin.css', array(), $ver );
	wp_enqueue_script( 'kny-admin', KNY_URL . 'assets/js/admin.js', array(), $ver, true );
	wp_add_inline_script( 'kny-admin', 'window.KNY_ADMIN=' . wp_json_encode( array( 'img' => KNY_URL . 'assets/img/' ) ) . ';', 'before' );
}

/* ---------- Отрисовка полей ---------- */

/**
 * Поля группы.
 *
 * @param array $fields   Поля схемы.
 * @param array $values   Значения.
 * @param array $defaults Исходные значения (для кнопки «Исходная картинка»).
 * @return string
 */
function kny_admin_fields( $fields, $values, $defaults = array() ) {
	$html = '<div class="kny-grid">';
	foreach ( $fields as $key => $field ) {
		$value   = array_key_exists( $key, $values ) ? $values[ $key ] : kny_field_default( $field );
		$default = array_key_exists( $key, $defaults ) ? $defaults[ $key ] : '';
		$html   .= kny_admin_field( $key, $field, $value, $default );
	}
	return $html . '</div>';
}

/**
 * Одно поле.
 *
 * @param string $key     Ключ.
 * @param array  $field   Схема поля.
 * @param mixed  $value   Значение.
 * @param mixed  $default Исходное значение.
 * @return string
 */
function kny_admin_field( $key, $field, $value, $default ) {
	static $uid = 0;
	$uid++;
	$id    = 'kny-f' . $uid;
	$type  = $field['type'];
	$label = esc_html( $field['label'] );
	$help  = isset( $field['help'] ) ? '<p class="kny-help">' . esc_html( $field['help'] ) . '</p>' : '';
	$ph    = isset( $field['placeholder'] ) ? ' placeholder="' . esc_attr( $field['placeholder'] ) . '"' : '';
	$wide  = ! empty( $field['wide'] ) || in_array( $type, array( 'rich', 'repeater', 'sub' ), true );
	$cls   = 'kny-field kny-t-' . $type . ( $wide ? ' kny-wide' : '' );
	$open  = '<div class="' . esc_attr( $cls ) . '" data-key="' . esc_attr( $key ) . '" data-type="' . esc_attr( $type ) . '">';

	switch ( $type ) {
		case 'sub':
			return '<h3 class="kny-sub">' . $label . '</h3>';

		case 'text':
		case 'url':
			$extra  = isset( $field['datalist'] ) ? ' list="' . esc_attr( $field['datalist'] ) . '"' : '';
			$extra .= 'url' === $type ? ' inputmode="url" spellcheck="false"' : '';
			$extra .= isset( $field['counter'] ) ? ' data-counter="' . (int) $field['counter'] . '"' : '';
			return $open . '<label for="' . $id . '">' . $label . '</label><input id="' . $id . '" class="kny-control" type="text" value="' . esc_attr( (string) $value ) . '"' . $ph . $extra . '>' . $help . '</div>';

		case 'datetime':
			return $open . '<label for="' . $id . '">' . $label . '</label><input id="' . $id . '" class="kny-control" type="datetime-local" value="' . esc_attr( (string) $value ) . '">' . $help . '</div>';

		case 'lines':
		case 'textarea':
		case 'rich':
			$rows   = max( 2, min( 12, substr_count( (string) $value, "\n" ) + ( 'rich' === $type ? 3 : 2 ) ) );
			$extra  = isset( $field['counter'] ) ? ' data-counter="' . (int) $field['counter'] . '"' : '';
			$extra .= 'rich' === $type ? ' data-rich="1"' : '';
			return $open . '<label for="' . $id . '">' . $label . '</label><textarea id="' . $id . '" class="kny-control" rows="' . (int) $rows . '"' . $ph . $extra . '>' . esc_textarea( (string) $value ) . '</textarea>' . $help . '</div>';

		case 'select':
			$opts = '';
			foreach ( $field['options'] as $ov => $ol ) {
				$opts .= '<option value="' . esc_attr( $ov ) . '"' . selected( (string) $value, (string) $ov, false ) . '>' . esc_html( $ol ) . '</option>';
			}
			return $open . '<label for="' . $id . '">' . $label . '</label><select id="' . $id . '" class="kny-control">' . $opts . '</select>' . $help . '</div>';

		case 'radio':
			$name = 'kny-r' . $uid;
			$opts = '';
			foreach ( $field['options'] as $ov => $ol ) {
				$opts .= '<label class="kny-radio"><input type="radio" class="kny-control" name="' . esc_attr( $name ) . '" value="' . esc_attr( $ov ) . '"' . checked( (string) $value, (string) $ov, false ) . '> <span>' . esc_html( $ol ) . '</span></label>';
			}
			return $open . '<span class="kny-label">' . $label . '</span><div class="kny-radios" role="radiogroup" aria-label="' . esc_attr( $field['label'] ) . '">' . $opts . '</div><p class="kny-hint" hidden></p>' . $help . '</div>';

		case 'checkbox':
			return $open . '<label class="kny-check"><input class="kny-control" type="checkbox"' . checked( (bool) $value, true, false ) . '> ' . $label . '</label>' . $help . '</div>';

		case 'image':
			$src = $value ? kny_img( $value ) : '';
			return $open . '<label>' . $label . '</label><div class="kny-image' . ( $src ? '' : ' is-empty' ) . '"><div class="kny-thumb">' . ( $src ? '<img src="' . esc_url( $src ) . '" alt="">' : '<span>Нет картинки</span>' ) . '</div><div class="kny-image-side">'
				. '<span class="kny-image-name">' . esc_html( kny_image_caption( $value ) ) . '</span>'
				. '<input class="kny-control kny-image-url" type="text" value="' . esc_attr( (string) $value ) . '" spellcheck="false" placeholder="или вставьте адрес картинки" aria-label="' . esc_attr( $field['label'] ) . ': адрес картинки">'
				. '<div class="kny-image-buttons"><button type="button" class="button kny-pick">Выбрать в медиатеке</button>'
				. ( $default ? '<button type="button" class="button-link kny-restore-img" data-default="' . esc_attr( (string) $default ) . '">Вернуть исходную</button>' : '' )
				. '<button type="button" class="button-link kny-clear-img">Убрать</button></div></div></div>' . $help . '</div>';

		case 'repeater':
			$empty = array();
			foreach ( $field['fields'] as $k => $f ) {
				if ( 'sub' !== $f['type'] ) {
					$empty[ $k ] = kny_field_default( $f );
				}
			}
			$items = '';
			foreach ( (array) $value as $item ) {
				$items .= kny_admin_item( $field, is_array( $item ) ? $item : array(), true );
			}
			$title = isset( $field['title'] ) ? implode( ',', $field['title'] ) : '';
			$max   = isset( $field['max'] ) ? (int) $field['max'] : 50;
			return $open . '<div class="kny-rep-head"><h3 class="kny-sub">' . $label . ' <span class="kny-count"></span></h3><button type="button" class="button-link kny-expand-all">Развернуть все</button></div>' . $help
				. '<div class="kny-items" data-title="' . esc_attr( $title ) . '" data-max="' . $max . '">' . $items . '</div>'
				. '<template class="kny-tpl">' . kny_admin_item( $field, $empty, false ) . '</template>'
				. '<button type="button" class="button kny-add">+ ' . esc_html( isset( $field['add'] ) ? $field['add'] : 'Добавить' ) . '</button></div>';
	}
	return '';
}

/**
 * Подпись к картинке в редакторе.
 *
 * @param string $value Значение.
 * @return string
 */
function kny_image_caption( $value ) {
	$value = (string) $value;
	if ( '' === $value ) {
		return 'Картинка не выбрана';
	}
	if ( 0 === strpos( $value, 'plugin:' ) ) {
		return 'Картинка из макета';
	}
	return 'Из медиатеки: ' . rawurldecode( basename( (string) wp_parse_url( $value, PHP_URL_PATH ) ) );
}

/**
 * Элемент повторителя.
 *
 * @param array $field  Повторитель.
 * @param array $values Значения.
 * @param bool  $closed Свернуть.
 * @return string
 */
function kny_admin_item( $field, $values, $closed ) {
	return '<div class="kny-item' . ( $closed ? ' is-closed' : '' ) . '" data-scope="1">'
		. '<div class="kny-item-bar"><button type="button" class="kny-toggle" aria-expanded="' . ( $closed ? 'false' : 'true' ) . '"><span class="kny-arrow" aria-hidden="true">▸</span><span class="kny-item-title"></span></button>'
		. '<span class="kny-tools"><button type="button" class="kny-up" title="Выше" aria-label="Выше">↑</button><button type="button" class="kny-down" title="Ниже" aria-label="Ниже">↓</button><button type="button" class="kny-copy" title="Копия" aria-label="Сделать копию">⧉</button><button type="button" class="kny-del" title="Удалить" aria-label="Удалить">✕</button></span></div>'
		. '<div class="kny-item-body">' . kny_admin_fields( $field['fields'], $values ) . '</div></div>';
}

/* ---------- Экран редактора ---------- */

/**
 * Страница редактора.
 */
function kny_admin_page() {
	if ( ! current_user_can( 'edit_pages' ) ) {
		wp_die( 'Недостаточно прав.' );
	}
	$schema   = kny_schema();
	$data     = kny_get_data();
	$defaults = kny_default_data();
	$s        = kny_get_settings();
	$page     = $s['page_id'] ? get_post( (int) $s['page_id'] ) : null;
	$is_admin = current_user_can( 'manage_options' );
	$history  = kny_get_history();

	echo '<div class="wrap kny-wrap">';
	echo '<div class="kny-top"><div><h1>Новый год в Клубе Козлова</h1><p class="kny-lead">Все тексты, концерты, цены и фото новогодней страницы. Сохранили, и страница обновилась. Тема и остальной сайт не затрагиваются.</p></div>';
	echo '<div class="kny-status">';
	if ( $page && 'page' === $page->post_type && 'trash' !== $page->post_status ) {
		$is_pub = 'publish' === $page->post_status;
		$link   = $is_pub ? get_permalink( $page ) : get_preview_post_link( $page );
		echo '<span class="kny-chip ' . ( $is_pub ? 'is-live' : 'is-draft' ) . '">' . ( $is_pub ? 'Опубликована' : 'Черновик: на сайте не видна' ) . '</span>';
		echo '<a class="button" href="' . esc_url( $link ) . '" target="_blank" rel="noopener">' . ( $is_pub ? 'Открыть страницу ↗' : 'Посмотреть черновик ↗' ) . '</a>';
		if ( ! $is_pub && current_user_can( 'publish_pages' ) ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="kny-inline" onsubmit="return confirm(\'Опубликовать страницу? Она станет доступна всем по своему адресу.\')">';
			wp_nonce_field( 'kny_publish' );
			echo '<input type="hidden" name="action" value="kny_publish"><button class="button button-primary">Опубликовать</button></form>';
		}
		echo '<span class="kny-url">Адрес: <code>' . esc_html( $is_pub ? get_permalink( $page ) : home_url( '/' . $page->post_name . '/' ) ) . '</code> <a href="' . esc_url( get_edit_post_link( $page->ID ) ) . '">изменить</a></span>';
		if ( empty( $s['enabled'] ) ) {
			echo '<span class="kny-chip is-off">Страница выключена в настройках</span>';
		}
	} else {
		echo '<span class="kny-chip is-off">Страница не выбрана</span>';
	}
	echo '</div></div><hr class="wp-header-end">';

	kny_admin_notices( $s, $page );

	// Вкладки.
	echo '<nav class="kny-tabs" role="tablist">';
	foreach ( $schema as $section => $def ) {
		echo '<button type="button" role="tab" class="kny-tab" data-tab="' . esc_attr( $section ) . '">' . esc_html( $def['tab'] ) . '</button>';
	}
	echo '<button type="button" role="tab" class="kny-tab kny-tab-aux" data-tab="history">История и копии</button>';
	if ( $is_admin ) {
		echo '<button type="button" role="tab" class="kny-tab kny-tab-aux" data-tab="settings">Настройки</button>';
	}
	echo '</nav>';

	// Подсказки сцен.
	$stages = kny_stage_list( $data['program']['stages'], $data['program']['events'] );
	echo '<datalist id="kny-stages">';
	foreach ( $stages as $stage ) {
		echo '<option value="' . esc_attr( $stage ) . '"></option>';
	}
	echo '</datalist>';

	// Основная форма содержимого.
	echo '<form id="kny-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	wp_nonce_field( 'kny_save' );
	echo '<input type="hidden" name="action" value="kny_save"><input type="hidden" name="kny_json" id="kny-json" value=""><input type="hidden" name="kny_tab" id="kny-tab-field" value="">';
	foreach ( $schema as $section => $def ) {
		echo '<section class="kny-panel" data-panel="' . esc_attr( $section ) . '" data-section="' . esc_attr( $section ) . '" data-scope="1" hidden>';
		echo kny_admin_fields( $def['fields'], isset( $data[ $section ] ) ? $data[ $section ] : array(), isset( $defaults[ $section ] ) ? $defaults[ $section ] : array() ); // phpcs:ignore WordPress.Security.EscapeOutput -- всё экранировано внутри.
		echo '</section>';
	}
	echo '</form>';

	// Предпросмотр несохранённых изменений.
	echo '<form id="kny-preview-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" target="kny-preview">';
	wp_nonce_field( 'kny_preview' );
	echo '<input type="hidden" name="action" value="kny_preview"><input type="hidden" name="kny_json" value=""></form>';

	// История.
	echo '<section class="kny-panel" data-panel="history" hidden><div class="kny-card"><h2>История изменений</h2><p class="kny-help">Перед каждым сохранением прошлая версия откладывается сюда (хранятся последние ' . (int) KNY_HISTORY_MAX . '). Любую можно посмотреть и вернуть.</p>';
	if ( $history ) {
		echo '<table class="widefat striped kny-history"><thead><tr><th>Отложена</th><th>Кто изменил</th><th>Изменение</th><th></th></tr></thead><tbody>';
		foreach ( $history as $i => $h ) {
			$when = function_exists( 'wp_date' ) ? wp_date( 'j F Y, H:i', (int) $h['time'] ) : date_i18n( 'j F Y, H:i', (int) $h['time'] );
			echo '<tr><td>' . esc_html( $when ) . '</td><td>' . esc_html( $h['user'] ) . '</td><td>' . esc_html( $h['label'] ) . '</td><td class="kny-history-actions">';
			echo '<a class="button" target="_blank" rel="noopener" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=kny_preview_version&i=' . (int) $i ), 'kny_preview_version' ) ) . '">Посмотреть эту версию ↗</a> ';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="kny-inline" onsubmit="return confirm(\'Вернуть эту версию? Текущая сохранится в истории.\')">';
			wp_nonce_field( 'kny_restore' );
			echo '<input type="hidden" name="action" value="kny_restore"><input type="hidden" name="i" value="' . (int) $i . '"><button class="button">Вернуть</button></form></td></tr>';
		}
		echo '</tbody></table>';
	} else {
		echo '<p>Пока пусто: история появится после первого сохранения.</p>';
	}
	echo '</div><div class="kny-card"><h2>Резервная копия</h2><p class="kny-help">Файл со всеми текстами и ссылками на картинки. Пригодится, чтобы перенести страницу или сделать из неё следующий праздник.</p>';
	echo '<p><a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=kny_export' ), 'kny_export' ) ) . '">Скачать копию (.json)</a></p>';
	echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'Загрузить содержимое из файла? Текущее сохранится в истории.\')">';
	wp_nonce_field( 'kny_import' );
	echo '<input type="hidden" name="action" value="kny_import"><input type="file" name="kny_file" accept=".json,application/json" required> <button class="button">Загрузить из файла</button></form>';
	echo '<hr><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'Вернуть всё содержимое к исходному макету? Текущее сохранится в истории.\')">';
	wp_nonce_field( 'kny_reset' );
	echo '<input type="hidden" name="action" value="kny_reset"><button class="button button-link-delete">Вернуть исходное содержимое макета</button></form></div></section>';

	// Настройки (только администратор).
	if ( $is_admin ) {
		kny_admin_settings_panel( $s );
	}

	// Нижняя панель.
	echo '<div class="kny-bar"><span class="kny-dirty" hidden>Есть несохранённые изменения</span><button type="button" class="button kny-preview-btn">Предпросмотр ↗</button><button type="submit" form="kny-form" class="button button-primary button-large kny-save-btn">Сохранить</button></div>';
	echo '</div>';
}

/**
 * Сообщения после действий и предупреждения.
 *
 * @param array        $s    Настройки.
 * @param WP_Post|null $page Страница.
 */
function kny_admin_notices( $s, $page ) {
	$msg  = isset( $_GET['kny_msg'] ) ? sanitize_key( wp_unslash( $_GET['kny_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$warn = isset( $_GET['kny_warn'] ) ? sanitize_text_field( wp_unslash( $_GET['kny_warn'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$map  = array(
		'saved'     => array( 'success', 'Сохранено. Страница на сайте обновлена.' ),
		'restored'  => array( 'success', 'Версия возвращена.' ),
		'imported'  => array( 'success', 'Содержимое загружено из файла.' ),
		'reset'     => array( 'success', 'Содержимое возвращено к исходному макету.' ),
		'settings'  => array( 'success', 'Настройки сохранены.' ),
		'published' => array( 'success', 'Страница опубликована.' ),
		'created'   => array( 'success', 'Создан черновик страницы.' ),
		'detected'  => array( 'success', 'Счётчик Метрики найден и подставлен.' ),
		'notfound'  => array( 'warning', 'Счётчик Метрики в теме не найден. Впишите номер вручную.' ),
		'badjson'   => array( 'error', 'Не удалось прочитать данные. Ничего не изменено.' ),
		'badfile'   => array( 'error', 'Файл не похож на копию этой страницы. Ничего не изменено.' ),
	);
	if ( 'saved' === $msg ) {
		$time = function_exists( 'wp_date' ) ? wp_date( 'H:i' ) : date_i18n( 'H:i' );
		$link = ( $page && 'publish' === $page->post_status ) ? ' <a href="' . esc_url( get_permalink( $page ) ) . '" target="_blank" rel="noopener">Открыть страницу ↗</a>' : '';
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( 'Сохранено в ' . $time . '. На сайте правки видны сразу.' ) . $link . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		$msg = '';
	}
	if ( isset( $map[ $msg ] ) ) {
		echo '<div class="notice notice-' . esc_attr( $map[ $msg ][0] ) . ' is-dismissible"><p>' . esc_html( $map[ $msg ][1] ) . '</p></div>';
	}
	if ( $warn ) {
		echo '<div class="notice notice-warning"><p>Без ссылки на афишу (кнопка ведёт на звонок): ' . esc_html( $warn ) . '.</p></div>';
	}
	if ( ! $page || 'trash' === $page->post_status ) {
		echo '<div class="notice notice-error"><p>Страница для новогоднего лендинга не выбрана или удалена.' . ( current_user_can( 'manage_options' ) ? ' Выберите или создайте её на вкладке «Настройки».' : ' Обратитесь к администратору.' ) . '</p></div>';
	}
	if ( '' === (string) $s['metrika_id'] && false === stripos( (string) $s['head_code'], 'mc.yandex.ru' ) ) {
		echo '<div class="notice notice-warning"><p>Яндекс Метрика не подключена: визиты на эту страницу не попадут в статистику.' . ( current_user_can( 'manage_options' ) ? ' Укажите номер счётчика на вкладке «Настройки».' : '' ) . '</p></div>';
	}
}

/**
 * Панель настроек.
 *
 * @param array $s Настройки.
 */
function kny_admin_settings_panel( $s ) {
	$post = admin_url( 'admin-post.php' );
	echo '<section class="kny-panel" data-panel="settings" hidden><div class="kny-card"><h2>Страница и счётчики</h2>';
	echo '<form method="post" action="' . esc_url( $post ) . '">';
	wp_nonce_field( 'kny_settings' );
	echo '<input type="hidden" name="action" value="kny_settings"><table class="form-table" role="presentation">';
	echo '<tr><th scope="row">Новогодняя страница</th><td><label><input type="checkbox" name="enabled" value="1"' . checked( ! empty( $s['enabled'] ), true, false ) . '> Показывать новогодний дизайн</label><p class="description">Выключите, и по этому адресу откроется обычная страница темы с коротким текстом. Ничего не удаляется.</p></td></tr>';
	echo '<tr><th scope="row"><label for="kny-page">Страница WordPress</label></th><td>';
	wp_dropdown_pages(
		array(
			'name'              => 'page_id',
			'id'                => 'kny-page',
			'selected'          => (int) $s['page_id'],
			'show_option_none'  => '— не выбрана —',
			'option_none_value' => '0',
			'post_status'       => array( 'publish', 'draft', 'private', 'pending' ),
		)
	);
	echo '<p class="description">На какой странице показывать лендинг. Адрес меняется в свойствах самой страницы.</p></td></tr>';
	echo '<tr><th scope="row"><label for="kny-ym">Яндекс Метрика</label></th><td><input id="kny-ym" type="text" name="metrika_id" value="' . esc_attr( $s['metrika_id'] ) . '" class="regular-text" inputmode="numeric" placeholder="номер счётчика"><p class="description">Тот же счётчик, что на всём сайте. Цели по кликам: <code>ny_ticket</code> (купить билет), <code>ny_booking</code> (бронь на странице концерта), <code>ny_call</code> (звонок). Их можно завести в Метрике как цели «JavaScript-событие».</p>';
	echo '<label><input type="checkbox" name="webvisor" value="1"' . checked( ! empty( $s['webvisor'] ), true, false ) . '> Вебвизор</label></td></tr>';
	if ( current_user_can( 'unfiltered_html' ) ) {
		echo '<tr><th scope="row"><label for="kny-head">Код в &lt;head&gt;</label></th><td><textarea id="kny-head" name="head_code" rows="4" class="large-text code" spellcheck="false">' . esc_textarea( $s['head_code'] ) . '</textarea><p class="description">Другие счётчики и пиксели (VK, Top.Mail.ru). Если вставить сюда полный код Метрики, номер выше не используется.</p></td></tr>';
		echo '<tr><th scope="row"><label for="kny-body">Код в начале &lt;body&gt;</label></th><td><textarea id="kny-body" name="body_code" rows="3" class="large-text code" spellcheck="false">' . esc_textarea( $s['body_code'] ) . '</textarea></td></tr>';
	}
	echo '</table><p><button class="button button-primary">Сохранить настройки</button></p></form>';
	echo '<div class="kny-row-actions"><form method="post" action="' . esc_url( $post ) . '" class="kny-inline">';
	wp_nonce_field( 'kny_detect' );
	echo '<input type="hidden" name="action" value="kny_detect"><button class="button">Найти номер Метрики в теме</button></form> ';
	echo '<form method="post" action="' . esc_url( $post ) . '" class="kny-inline" onsubmit="return confirm(\'Создать новую страницу-черновик и показывать лендинг на ней?\')">';
	wp_nonce_field( 'kny_create_page' );
	echo '<input type="hidden" name="action" value="kny_create_page"><button class="button">Создать новую страницу-черновик</button></form></div>';
	echo '</div><div class="kny-card"><h2>Как устроено</h2><ul class="kny-list"><li>Страница выводится своим шаблоном: стили и скрипты темы на неё не грузятся, её стили не попадают на остальной сайт.</li><li>Если при выводе что-то сломается, по адресу откроется обычная страница темы, а ошибка запишется в журнал PHP.</li><li>Данные хранятся в трёх опциях WordPress (kny_data, kny_history, kny_settings). Таблицы, афиша и файлы темы не меняются.</li><li>Title, description и картинка для соцсетей задаются на вкладке «Общее и SEO»; настройки Yoast/Rank Math у этой страницы не используются.</li><li>Страница не кэшируется (ни плагинами кэша, ни браузером), а после сохранения сбрасывается кэш объектов и базы (Redis в W3 Total Cache): правки видны сразу.</li><li>После сохранения кэш страницы дополнительно сбрасывается в WP Super Cache, WP Rocket, W3 Total Cache, LiteSpeed, WP Fastest Cache, если они стоят.</li></ul></div></section>';
}

/* ---------- Обработчики ---------- */

/**
 * Проверка прав и nonce.
 *
 * @param string $action Действие.
 * @param string $cap    Право.
 */
function kny_guard( $action, $cap = 'edit_pages' ) {
	if ( ! current_user_can( $cap ) ) {
		wp_die( 'Недостаточно прав.', 403 );
	}
	check_admin_referer( $action );
}

/**
 * Возврат на экран редактора.
 *
 * @param array $args Параметры.
 */
function kny_back( $args ) {
	$tab = isset( $_POST['kny_tab'] ) ? sanitize_key( wp_unslash( $_POST['kny_tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$url = kny_admin_url( $args );
	if ( isset( $args['tab'] ) ) {
		$tab = $args['tab'];
	}
	wp_safe_redirect( $url . ( $tab ? '#' . $tab : '' ) );
	exit;
}

/**
 * Данные из формы (JSON).
 *
 * @return array|null
 */
function kny_posted_data() {
	$raw  = isset( $_POST['kny_json'] ) ? wp_unslash( $_POST['kny_json'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
	$data = json_decode( (string) $raw, true );
	return is_array( $data ) ? kny_sanitize_data( $data ) : null;
}

/**
 * Концерты без ссылки при кнопке «на страницу концерта».
 *
 * @param array $data Данные.
 * @return string
 */
function kny_link_warnings( $data ) {
	$names = array();
	foreach ( $data['program']['events'] as $e ) {
		if ( empty( $e['hidden'] ) && 'book_phone' !== $e['action'] && '' === $e['url'] ) {
			$names[] = $e['title'] ? $e['title'] : 'без названия';
		}
	}
	return implode( ', ', $names );
}

/** Сохранение содержимого. */
function kny_handle_save() {
	kny_guard( 'kny_save' );
	$data = kny_posted_data();
	if ( null === $data ) {
		kny_back( array( 'kny_msg' => 'badjson' ) );
	}
	kny_save_data( $data, 'Сохранение' );
	$warn = kny_link_warnings( $data );
	kny_back( array_filter( array( 'kny_msg' => 'saved', 'kny_warn' => $warn ) ) );
}

/** Предпросмотр несохранённых изменений. */
function kny_handle_preview() {
	kny_guard( 'kny_preview' );
	$data = kny_posted_data();
	if ( null === $data ) {
		wp_die( 'Не удалось прочитать данные для предпросмотра.' );
	}
	kny_output_preview( $data );
}

/** Предпросмотр версии из истории. */
function kny_handle_preview_version() {
	kny_guard( 'kny_preview_version' );
	$history = kny_get_history();
	$i       = isset( $_GET['i'] ) ? absint( $_GET['i'] ) : -1; // phpcs:ignore WordPress.Security.NonceVerification
	if ( ! isset( $history[ $i ] ) ) {
		wp_die( 'Версия не найдена.' );
	}
	kny_output_preview( kny_merge( kny_default_data(), $history[ $i ]['data'] ) );
}

/**
 * Выводит предпросмотр.
 *
 * @param array $data Данные.
 */
function kny_output_preview( $data ) {
	nocache_headers();
	header( 'X-Robots-Tag: noindex, nofollow' );
	$s = kny_get_settings();
	if ( ! kny_safe_render(
		$data,
		array(
			'url'     => $s['page_id'] ? get_permalink( (int) $s['page_id'] ) : '',
			'noindex' => true,
			'preview' => true,
		)
	) ) {
		wp_die( 'Ошибка при построении предпросмотра. Подробности в журнале PHP.' );
	}
	exit;
}

/** Возврат версии из истории. */
function kny_handle_restore() {
	kny_guard( 'kny_restore' );
	$history = kny_get_history();
	$i       = isset( $_POST['i'] ) ? absint( $_POST['i'] ) : -1;
	if ( isset( $history[ $i ] ) ) {
		$when = function_exists( 'wp_date' ) ? wp_date( 'j F H:i', (int) $history[ $i ]['time'] ) : '';
		kny_save_data( kny_sanitize_data( kny_merge( kny_default_data(), $history[ $i ]['data'] ) ), 'Возврат версии от ' . $when );
	}
	kny_back( array( 'kny_msg' => 'restored', 'tab' => 'history' ) );
}

/** Скачивание копии. */
function kny_handle_export() {
	kny_guard( 'kny_export' );
	$payload = array(
		'plugin'  => 'kozlov-new-year',
		'version' => KNY_VERSION,
		'date'    => gmdate( 'c' ),
		'data'    => kny_get_data(),
	);
	nocache_headers();
	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="kozlov-new-year-' . gmdate( 'Y-m-d-His' ) . '.json"' );
	echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput
	exit;
}

/** Загрузка копии. */
function kny_handle_import() {
	kny_guard( 'kny_import' );
	$file = isset( $_FILES['kny_file']['tmp_name'] ) ? $_FILES['kny_file']['tmp_name'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$json = ( $file && is_uploaded_file( $file ) && filesize( $file ) < 2097152 ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
	if ( is_array( $json ) && isset( $json['data'] ) && is_array( $json['data'] ) ) {
		$json = $json['data'];
	}
	if ( ! is_array( $json ) || ! isset( $json['program'] ) ) {
		kny_back( array( 'kny_msg' => 'badfile', 'tab' => 'history' ) );
	}
	kny_save_data( kny_sanitize_data( $json ), 'Загрузка из файла' );
	kny_back( array( 'kny_msg' => 'imported', 'tab' => 'history' ) );
}

/** Возврат к исходному макету. */
function kny_handle_reset() {
	kny_guard( 'kny_reset' );
	kny_save_data( kny_default_data(), 'Сброс к исходному' );
	kny_back( array( 'kny_msg' => 'reset', 'tab' => 'history' ) );
}

/** Настройки. */
function kny_handle_settings() {
	kny_guard( 'kny_settings', 'manage_options' );
	$s       = kny_get_settings();
	$old     = (int) $s['page_id'];
	$page_id = isset( $_POST['page_id'] ) ? absint( $_POST['page_id'] ) : 0;
	if ( $page_id && 'page' !== get_post_type( $page_id ) ) {
		$page_id = $old;
	}
	$s['enabled']    = ! empty( $_POST['enabled'] );
	$s['page_id']    = $page_id;
	$s['metrika_id'] = isset( $_POST['metrika_id'] ) ? preg_replace( '/\D/', '', wp_unslash( $_POST['metrika_id'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$s['webvisor']   = ! empty( $_POST['webvisor'] );
	if ( current_user_can( 'unfiltered_html' ) ) {
		$s['head_code'] = isset( $_POST['head_code'] ) ? trim( wp_unslash( $_POST['head_code'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- произвольный код счётчиков, только для unfiltered_html.
		$s['body_code'] = isset( $_POST['body_code'] ) ? trim( wp_unslash( $_POST['body_code'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}
	kny_save_settings( $s );
	kny_purge_cache( $old );
	kny_purge_cache( $page_id );
	kny_back( array( 'kny_msg' => 'settings', 'tab' => 'settings' ) );
}

/** Публикация страницы. */
function kny_handle_publish() {
	kny_guard( 'kny_publish', 'publish_pages' );
	$s = kny_get_settings();
	if ( $s['page_id'] && 'page' === get_post_type( (int) $s['page_id'] ) ) {
		wp_update_post(
			array(
				'ID'          => (int) $s['page_id'],
				'post_status' => 'publish',
			)
		);
		kny_purge_cache( (int) $s['page_id'] );
	}
	kny_back( array( 'kny_msg' => 'published' ) );
}

/** Новый черновик страницы. */
function kny_handle_create_page() {
	kny_guard( 'kny_create_page', 'manage_options' );
	$s            = kny_get_settings();
	$s['page_id'] = kny_create_page();
	kny_save_settings( $s );
	kny_back( array( 'kny_msg' => 'created', 'tab' => 'settings' ) );
}

/** Поиск номера Метрики. */
function kny_handle_detect() {
	kny_guard( 'kny_detect', 'manage_options' );
	$id = kny_detect_metrika();
	if ( $id ) {
		$s               = kny_get_settings();
		$s['metrika_id'] = $id;
		kny_save_settings( $s );
		kny_purge_cache( (int) $s['page_id'] );
	}
	kny_back( array( 'kny_msg' => $id ? 'detected' : 'notfound', 'tab' => 'settings' ) );
}
