<?php
/**
 * Перенос содержимого старого сайта jazzartclub.ru одной кнопкой.
 *
 * Статьи, страницы и рубрики лежат в теме (data/content.json): они заранее очищены
 * и проверены. Кнопка появляется вверху админки сразу после включения темы.
 * Повторное нажатие безопасно: уже перенесённое не дублируется.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Файл с содержимым старого сайта. */
function jac_import_file() {
	return get_template_directory() . '/data/content.json';
}

/** Перенос статей, страниц и рубрик. Возвращает число созданных записей или WP_Error. */
function jac_import_content() {
	global $wpdb;

	$data = json_decode( (string) file_get_contents( jac_import_file() ), true );
	if ( empty( $data['items'] ) ) {
		return new WP_Error( 'jac_no_data', 'Файл с содержимым не найден или повреждён. Загрузите тему заново.' );
	}

	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 300 ); // phpcs:ignore
	}
	wp_defer_term_counting( true );
	kses_remove_filters(); // В статьях есть вставки видео: администратору их можно сохранять как есть.

	jac_remove_samples();

	// Рубрики.
	$term_ids = array();
	foreach ( $data['categories'] as $cat ) {
		$term = get_term_by( 'slug', $cat['slug'], 'category' );
		if ( ! $term ) {
			$made = wp_insert_term( $cat['name'], 'category', array( 'slug' => $cat['slug'], 'description' => $cat['description'] ) );
			$term = is_wp_error( $made ) ? null : get_term( $made['term_id'], 'category' );
		}
		if ( $term ) {
			$term_ids[ $cat['slug'] ] = (int) $term->term_id;
		}
	}
	if ( isset( $term_ids['novosti'] ) ) {
		update_option( 'default_category', $term_ids['novosti'] );
	}

	// Что уже перенесено (на случай повторного нажатия).
	$done = array();
	foreach ( $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_jac_old_id'" ) as $row ) {
		$done[ (int) $row->meta_value ] = (int) $row->post_id;
	}

	$author  = get_current_user_id();
	$created = 0;
	foreach ( $data['items'] as $item ) {
		$old_id = (int) $item['id'];
		if ( isset( $done[ $old_id ] ) ) {
			continue;
		}
		$post = array(
			'post_type'      => $item['type'],
			'post_status'    => $item['status'],
			'post_title'     => $item['title'],
			'post_name'      => $item['slug'],
			'post_content'   => $item['content'],
			'post_date'      => $item['date'],
			'post_date_gmt'  => $item['date_gmt'],
			'post_parent'    => $item['parent'] && isset( $done[ $item['parent'] ] ) ? $done[ $item['parent'] ] : 0,
			'menu_order'     => (int) $item['order'],
			'post_author'    => $author,
			'comment_status' => 'closed',
			'ping_status'    => 'closed',
		);
		if ( 'post' === $item['type'] ) {
			$post['post_category'] = array_values( array_intersect_key( $term_ids, array_flip( $item['cats'] ) ) );
		}
		if ( ! get_post( $old_id ) ) {
			$post['import_id'] = $old_id; // Сохраняем прежние номера записей, где это возможно.
		}
		$new_id = wp_insert_post( wp_slash( $post ), true );
		if ( is_wp_error( $new_id ) ) {
			continue;
		}
		add_post_meta( $new_id, '_jac_old_id', $old_id, true );
		foreach ( $item['old_slugs'] as $slug ) {
			add_post_meta( $new_id, '_wp_old_slug', $slug ); // Прежние адреса статей продолжают открываться.
		}
		$done[ $old_id ] = $new_id;
		++$created;
	}

	wp_defer_term_counting( false );
	kses_init_filters();

	if ( ! empty( $data['source'] ) && ! get_theme_mod( 'jac_old_site' ) ) {
		set_theme_mod( 'jac_old_site', esc_url_raw( $data['source'] ) );
	}

	jac_finish_setup();
	update_option( 'jac_import_done', array( 'time' => time(), 'created' => $created ) );
	return $created;
}

/** Учебные «Привет, мир!» и «Пример страницы» от новой установки WordPress. */
function jac_remove_samples() {
	$slugs = array( 'hello-world', 'sample-page', 'privet-mir', sanitize_title( 'Привет, мир!' ), sanitize_title( 'Пример страницы' ) );
	foreach ( array( 1 => 'post', 2 => 'page' ) as $id => $type ) {
		$sample = get_post( $id );
		if ( $sample && $type === $sample->post_type && in_array( $sample->post_name, $slugs, true ) && ! get_post_meta( $id, '_jac_old_id', true ) ) {
			wp_delete_post( $id, true );
		}
	}
}

/**
 * Создаёт меню и назначает его области темы.
 *
 * Каждый пункт: array( тип, адрес, подпись, номер родителя в этом же списке или null ).
 * Тип: home, page, post или category.
 */
function jac_make_menu( $location, $name, $entries ) {
	$menu = wp_get_nav_menu_object( $name );
	if ( ! $menu ) {
		$menu_id = wp_create_nav_menu( $name );
		if ( is_wp_error( $menu_id ) ) {
			return;
		}
		$made = array();
		foreach ( $entries as $index => $entry ) {
			list( $type, $slug, $label, $parent ) = array_pad( $entry, 4, null );
			$args = array(
				'menu-item-title'     => $label,
				'menu-item-status'    => 'publish',
				'menu-item-parent-id' => ( null !== $parent && isset( $made[ $parent ] ) ) ? $made[ $parent ] : 0,
			);
			if ( 'home' === $type ) {
				$args += array( 'menu-item-type' => 'custom', 'menu-item-url' => home_url( '/' ) );
			} elseif ( 'category' === $type ) {
				$term = get_term_by( 'slug', $slug, 'category' );
				if ( ! $term ) {
					continue;
				}
				$args += array( 'menu-item-type' => 'taxonomy', 'menu-item-object' => 'category', 'menu-item-object-id' => $term->term_id );
			} else {
				$target = get_page_by_path( $slug, OBJECT, $type );
				if ( ! $target || 'publish' !== $target->post_status ) {
					continue;
				}
				$args += array( 'menu-item-type' => 'post_type', 'menu-item-object' => $type, 'menu-item-object-id' => $target->ID );
			}
			$item = wp_update_nav_menu_item( $menu_id, 0, $args );
			if ( ! is_wp_error( $item ) ) {
				$made[ $index ] = $item;
			}
		}
	} else {
		$menu_id = $menu->term_id;
	}
	$locations              = (array) get_theme_mod( 'nav_menu_locations', array() );
	$locations[ $location ] = (int) $menu_id;
	set_theme_mod( 'nav_menu_locations', $locations );
}

/** Меню сайта. Названия новые, поэтому меню прежней версии темы остаются нетронутыми в «Внешний вид → Меню». */
function jac_make_menus() {
	jac_make_menu( 'primary', 'Меню сайта', array(
		array( 'home', '', 'Главная' ),
		array( 'page', 'anons', 'Афиша' ),
		array( 'post', 'vocaljazz', 'Фестиваль' ),
		array( 'post', 'jazz-paroxod', 'Джаз пароход' ),
		array( 'page', 'about', 'О клубе' ),
		array( 'page', 'xronika', 'Хроника', 4 ),
		array( 'page', 'autors', 'Авторы', 4 ),
		array( 'page', 'partners', 'Партнёры', 4 ),
		array( 'page', 'uslugi', 'Услуги', 4 ),
		array( 'category', 'novosti', 'Новости' ),
		array( 'page', 'contacts', 'Контакты' ),
	) );
	jac_make_menu( 'projects', 'Проекты', array(
		array( 'post', 'vocaljazz', 'Фестиваль вокального джаза' ),
		array( 'post', 'jazz-paroxod', 'Джаз пароход' ),
		array( 'post', 'jazz-opera', 'Джазовая опера' ),
		array( 'post', 'arxiv', 'Архив записей' ),
		array( 'post', 'art-gallery', 'Арт-галерея' ),
		array( 'post', 'collection', 'Коллекция дисков' ),
	) );
}

/**
 * Обновление сайта, перенесённого прежней версией темы: новое меню и настройки по умолчанию.
 * Статьи не трогаются. Срабатывает один раз после загрузки новой версии темы.
 */
add_action( 'init', function () {
	if ( (int) get_option( 'jac_schema' ) >= 2 || ! get_option( 'jac_setup_done' ) ) {
		return;
	}
	jac_make_menus();
	update_option( 'jac_schema', 2 );
}, 30 );

/** Меню, главная, адреса страниц, контакты. Можно запускать повторно. */
function jac_finish_setup() {
	global $wp_rewrite;

	jac_make_menus();
	update_option( 'jac_schema', 2 );

	// На главной показываем «Анонсы»: текущую программу концертов.
	$anons = get_page_by_path( 'anons' );
	if ( $anons && 'publish' === $anons->post_status && ! get_theme_mod( 'jac_home_page' ) ) {
		set_theme_mod( 'jac_home_page', $anons->ID );
	}

	// Адреса страниц как на старом сайте: jazzartclub.ru/название-статьи/
	if ( '/%postname%/' !== get_option( 'permalink_structure' ) ) {
		$wp_rewrite->set_permalink_structure( '/%postname%/' );
	}
	flush_rewrite_rules();

	// Комментарии закрыты: на старом сайте в них был только спам.
	update_option( 'default_comment_status', 'closed' );
	update_option( 'default_ping_status', 'closed' );

	// Подзаголовок сайта, если он остался стандартным.
	$tagline = (string) get_option( 'blogdescription' );
	if ( '' === $tagline || false !== stripos( $tagline, 'WordPress' ) ) {
		update_option( 'blogdescription', 'Объединение исполнителей и любителей джазовой музыки' );
	}

	// Контакты со страницы «Контакты» старого сайта. Проверьте в «Внешний вид → Настроить».
	$defaults = array(
		'jac_phone'   => '+7 499 191 8320',
		'jac_email'   => 'jazzartclub@gmail.com',
		'jac_address' => 'Москва, ул. Пятницкая, 27, стр. 3, джаз-кофейня «Эссе» (3 минуты от метро «Новокузнецкая»)',
		'jac_credit'  => 'Сценарий, тексты, редактирование: Александр Эйдельман',
	);
	foreach ( $defaults as $key => $value ) {
		if ( ! get_theme_mod( $key ) ) {
			set_theme_mod( $key, $value );
		}
	}

	update_option( 'jac_setup_done', 1 );
}

/* ------------------------------------------------------------
 * Кнопка в админке
 * ------------------------------------------------------------ */
add_action( 'admin_notices', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$done = get_option( 'jac_import_done' );
	if ( $done && isset( $_GET['jac_imported'] ) ) { // phpcs:ignore
		$counts = wp_count_posts();
		$pages  = wp_count_posts( 'page' );
		echo '<div class="notice notice-success"><p style="font-size:15px"><strong>Готово.</strong> Перенесено: статей ' . (int) $counts->publish . ', страниц ' . (int) $pages->publish . '. Меню, афиша и контакты настроены.</p>';
		echo '<p><a class="button button-primary button-hero" href="' . esc_url( home_url( '/' ) ) . '">Открыть сайт</a></p>';
		if ( jac_old_site() ) {
			echo '<p>Картинки сайт копирует со старого сайта сам, при первом показе каждой страницы. Скопировать все сразу: <a href="' . esc_url( admin_url( 'tools.php?page=jac-images' ) ) . '">Инструменты → Картинки со старого сайта</a>.</p>';
		}
		echo '</div>';
		return;
	}
	if ( $done || ! file_exists( jac_import_file() ) ) {
		return;
	}
	if ( isset( $_GET['jac_error'] ) ) { // phpcs:ignore
		echo '<div class="notice notice-error"><p>Перенос не завершился. Нажмите кнопку ещё раз: уже перенесённое не задвоится.</p></div>';
	}
	$url = wp_nonce_url( admin_url( 'admin-post.php?action=jac_import' ), 'jac_import' );
	echo '<div class="notice notice-info"><p style="font-size:15px"><strong>Тема «Джаз Арт Клуб» включена.</strong> Осталось одно действие: перенести статьи, страницы, рубрики и меню старого сайта. Это занимает до минуты.</p>';
	echo '<p><a class="button button-primary button-hero" href="' . esc_url( $url ) . '">Перенести содержимое старого сайта</a></p></div>';
} );

add_action( 'admin_post_jac_import', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Недостаточно прав.' );
	}
	check_admin_referer( 'jac_import' );
	$result = jac_import_content();
	wp_safe_redirect( admin_url( is_wp_error( $result ) ? 'index.php?jac_error=1' : 'index.php?jac_imported=1' ) );
	exit;
} );
