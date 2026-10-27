<?php
/**
 * Хранение, проверка и история данных.
 *
 * Всё лежит в трёх опциях (autoload = no): kny_data, kny_history, kny_settings.
 * Плагин не создаёт таблиц и не трогает чужие данные.
 *
 * @package Kozlov_New_Year
 */

defined( 'ABSPATH' ) || exit;

const KNY_HISTORY_MAX = 20;

/**
 * Текущие данные страницы (сохранённые поверх исходных).
 *
 * @return array
 */
function kny_get_data() {
	$stored = get_option( 'kny_data', null );
	if ( ! is_array( $stored ) ) {
		return kny_default_data();
	}
	return kny_merge( kny_default_data(), $stored );
}

/**
 * Рекурсивно накладывает сохранённые значения на исходные.
 * Списки (повторители) берутся из сохранённых целиком.
 *
 * @param array $base   Исходные.
 * @param array $stored Сохранённые.
 * @return array
 */
function kny_merge( $base, $stored ) {
	foreach ( $base as $key => $value ) {
		if ( ! array_key_exists( $key, $stored ) ) {
			continue;
		}
		if ( is_array( $value ) && kny_is_assoc( $value ) && is_array( $stored[ $key ] ) ) {
			$base[ $key ] = kny_merge( $value, $stored[ $key ] );
		} else {
			$base[ $key ] = $stored[ $key ];
		}
	}
	return $base;
}

/**
 * Ассоциативный массив или список.
 *
 * @param array $arr Массив.
 * @return bool
 */
function kny_is_assoc( $arr ) {
	if ( array() === $arr ) {
		return false;
	}
	return array_keys( $arr ) !== range( 0, count( $arr ) - 1 );
}

/**
 * Разрешённые теги в полях «rich».
 *
 * @return array
 */
function kny_allowed_html() {
	return array(
		'a'      => array(
			'href'   => true,
			'target' => true,
			'rel'    => true,
			'title'  => true,
		),
		'b'      => array(),
		'strong' => array(),
		'i'      => array(),
		'em'     => array(),
		'br'     => array(),
		'sup'    => array(),
		'sub'    => array(),
		'small'  => array(),
		'span'   => array( 'class' => true ),
		'nobr'   => array(),
	);
}

/**
 * Проверяет и чистит данные по схеме.
 *
 * @param array $input  Пришедшие данные.
 * @param array $schema Схема (kny_schema()).
 * @return array
 */
function kny_sanitize_data( $input, $schema = null ) {
	if ( null === $schema ) {
		$schema = kny_schema();
	}
	$defaults = kny_default_data();
	$out      = array();
	foreach ( $schema as $section => $def ) {
		$in              = ( isset( $input[ $section ] ) && is_array( $input[ $section ] ) ) ? $input[ $section ] : array();
		$out[ $section ] = kny_sanitize_group( $def['fields'], $in, isset( $defaults[ $section ] ) ? $defaults[ $section ] : array() );
	}
	return $out;
}

/**
 * Чистит группу полей.
 *
 * @param array $fields   Поля схемы.
 * @param array $in       Данные.
 * @param array $fallback Значения по умолчанию.
 * @return array
 */
function kny_sanitize_group( $fields, $in, $fallback ) {
	$out = array();
	foreach ( $fields as $key => $field ) {
		if ( 'sub' === $field['type'] ) {
			continue;
		}
		$default = array_key_exists( $key, $fallback ) ? $fallback[ $key ] : kny_field_default( $field );
		if ( ! array_key_exists( $key, $in ) ) {
			$out[ $key ] = $default;
			continue;
		}
		$out[ $key ] = kny_sanitize_value( $field, $in[ $key ], $default );
	}
	return $out;
}

/**
 * Пустое значение поля для нового элемента повторителя.
 *
 * @param array $field Поле.
 * @return mixed
 */
function kny_field_default( $field ) {
	if ( isset( $field['default'] ) ) {
		return $field['default'];
	}
	switch ( $field['type'] ) {
		case 'checkbox':
			return false;
		case 'repeater':
			return array();
		case 'select':
		case 'radio':
			$keys = array_keys( $field['options'] );
			return $keys[0];
		default:
			return '';
	}
}

/**
 * Чистит одно значение.
 *
 * @param array $field   Поле.
 * @param mixed $value   Значение.
 * @param mixed $default По умолчанию.
 * @return mixed
 */
function kny_sanitize_value( $field, $value, $default ) {
	switch ( $field['type'] ) {
		case 'checkbox':
			return (bool) $value;

		case 'select':
		case 'radio':
			$value = is_scalar( $value ) ? (string) $value : '';
			return array_key_exists( $value, $field['options'] ) ? $value : $default;

		case 'datetime':
			$value = is_scalar( $value ) ? trim( (string) $value ) : '';
			return preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value ) ? $value : $default;

		case 'url':
			$value = is_scalar( $value ) ? trim( (string) $value ) : '';
			if ( '' === $value ) {
				return '';
			}
			if ( '#' === $value[0] ) {
				return '#' . preg_replace( '/[^A-Za-z0-9_-]/', '', substr( $value, 1 ) );
			}
			return esc_url_raw( $value, array( 'http', 'https', 'tel', 'mailto' ) );

		case 'image':
			$value = is_scalar( $value ) ? trim( (string) $value ) : '';
			if ( 0 === strpos( $value, 'plugin:' ) ) {
				$file = basename( substr( $value, 7 ) );
				return file_exists( KNY_DIR . 'assets/img/' . $file ) ? 'plugin:' . $file : $default;
			}
			return '' === $value ? '' : esc_url_raw( $value, array( 'http', 'https' ) );

		case 'text':
			return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : $default;

		case 'lines':
		case 'textarea':
			return is_scalar( $value ) ? trim( sanitize_textarea_field( str_replace( "\r\n", "\n", (string) $value ) ) ) : $default;

		case 'rich':
			if ( ! is_scalar( $value ) ) {
				return $default;
			}
			$value = str_replace( "\r\n", "\n", (string) $value );
			return trim( wp_kses( $value, kny_allowed_html() ) );

		case 'repeater':
			if ( ! is_array( $value ) ) {
				return $default;
			}
			$max   = isset( $field['max'] ) ? (int) $field['max'] : 50;
			$items = array();
			$empty = array();
			foreach ( $field['fields'] as $k => $f ) {
				if ( 'sub' !== $f['type'] ) {
					$empty[ $k ] = kny_field_default( $f );
				}
			}
			foreach ( array_values( $value ) as $item ) {
				if ( count( $items ) >= $max ) {
					break;
				}
				if ( is_array( $item ) ) {
					$items[] = kny_sanitize_group( $field['fields'], $item, $empty );
				}
			}
			return $items;
	}
	return $default;
}

/**
 * Сохраняет данные, предыдущую версию кладёт в историю.
 *
 * @param array  $data  Проверенные данные.
 * @param string $label Пометка для истории.
 */
function kny_save_data( $data, $label = 'Сохранение' ) {
	$current = get_option( 'kny_data', null );
	// Первое сохранение: в историю уходит исходный макет, чтобы к нему всегда можно было вернуться.
	kny_push_history( is_array( $current ) ? $current : kny_default_data(), $label );
	update_option( 'kny_data', $data, false );
	$settings = kny_get_settings();
	if ( ! empty( $settings['page_id'] ) ) {
		kny_purge_cache( (int) $settings['page_id'] );
	}
}

/**
 * Добавляет версию в историю.
 *
 * @param array  $data  Данные.
 * @param string $label Пометка.
 */
function kny_push_history( $data, $label ) {
	$history = get_option( 'kny_history', array() );
	if ( ! is_array( $history ) ) {
		$history = array();
	}
	$user = wp_get_current_user();
	array_unshift(
		$history,
		array(
			'time'  => time(),
			'user'  => $user && $user->exists() ? $user->display_name : '',
			'label' => $label,
			'data'  => $data,
		)
	);
	$history = array_slice( $history, 0, KNY_HISTORY_MAX );
	update_option( 'kny_history', $history, false );
}

/**
 * История версий.
 *
 * @return array
 */
function kny_get_history() {
	$history = get_option( 'kny_history', array() );
	return is_array( $history ) ? $history : array();
}

/**
 * Технические настройки.
 *
 * @return array
 */
function kny_get_settings() {
	$s = get_option( 'kny_settings', array() );
	return array_merge( kny_default_settings(), is_array( $s ) ? $s : array() );
}

/**
 * Сохраняет настройки.
 *
 * @param array $s Настройки.
 */
function kny_save_settings( $s ) {
	update_option( 'kny_settings', array_merge( kny_default_settings(), $s ), false );
}

/**
 * Создаёт черновик страницы.
 *
 * @return int ID страницы или 0.
 */
function kny_create_page() {
	$content = '<p>Новый год 2027 в Клубе Алексея Козлова: концерты 31 декабря, джазовый бранч, диджеи и новогодняя ночь до 04:00.</p>'
		. '<p>Программа и билеты в <a href="' . esc_url( home_url( '/?date1=2026-12-31' ) ) . '">афише клуба</a>. Бронирование по телефону <a href="tel:+74952961200">+7 (495) 296-12-00</a>.</p>';
	$id      = wp_insert_post(
		array(
			'post_type'      => 'page',
			'post_status'    => 'draft',
			'post_title'     => 'Новый год 2027',
			'post_name'      => 'novyj-god',
			'post_content'   => $content,
			'comment_status' => 'closed',
			'ping_status'    => 'closed',
		),
		true
	);
	return is_wp_error( $id ) ? 0 : (int) $id;
}

/**
 * Сбрасывает кэш страницы в популярных плагинах кэширования (если они есть).
 *
 * @param int $page_id ID страницы.
 */
function kny_purge_cache( $page_id ) {
	if ( ! $page_id ) {
		return;
	}
	clean_post_cache( $page_id );
	if ( function_exists( 'wp_cache_post_change' ) ) {
		wp_cache_post_change( $page_id ); // WP Super Cache.
	}
	if ( function_exists( 'rocket_clean_post' ) ) {
		rocket_clean_post( $page_id ); // WP Rocket.
	}
	if ( function_exists( 'w3tc_flush_post' ) ) {
		w3tc_flush_post( $page_id ); // W3 Total Cache.
	}
	if ( function_exists( 'w3tc_flush_url' ) ) {
		$url = get_permalink( $page_id );
		if ( $url ) {
			w3tc_flush_url( $url ); // W3 Total Cache: точечно по адресу.
		}
	}
	if ( class_exists( 'WpFastestCache' ) && isset( $GLOBALS['wp_fastest_cache'] ) && method_exists( $GLOBALS['wp_fastest_cache'], 'singleDeleteCache' ) ) {
		$GLOBALS['wp_fastest_cache']->singleDeleteCache( false, $page_id ); // WP Fastest Cache.
	}
	do_action( 'litespeed_purge_post', $page_id ); // LiteSpeed Cache.
	do_action( 'kny_purge_cache', $page_id );
}

/**
 * Ищет ID счётчика Яндекс Метрики в файлах темы и в опциях сайта. Только чтение.
 *
 * @return string ID или пустая строка.
 */
function kny_detect_metrika() {
	$found = array();
	$dirs  = array_unique( array( get_stylesheet_directory(), get_template_directory() ) );
	$count = 0;
	foreach ( $dirs as $dir ) {
		if ( ! is_dir( $dir ) ) {
			continue;
		}
		try {
			$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::LEAVES_ONLY, RecursiveIteratorIterator::CATCH_GET_CHILD );
			foreach ( $it as $file ) {
				if ( ++$count > 600 ) {
					break 2;
				}
				if ( 'php' !== strtolower( $file->getExtension() ) || ! $file->isReadable() || $file->getSize() > 1048576 ) {
					continue;
				}
				$found = array_merge( $found, kny_extract_metrika_ids( (string) file_get_contents( $file->getPathname() ) ) );
			}
		} catch ( Exception $e ) {
			continue; // Нечитаемая папка темы: просто пропускаем.
		}
	}
	global $wpdb;
	$rows = $wpdb->get_col( "SELECT option_value FROM {$wpdb->options} WHERE option_name NOT LIKE 'kny\_%' AND (option_value LIKE '%mc.yandex.ru%' OR option_value LIKE '%yaCounter%') LIMIT 30" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	foreach ( (array) $rows as $row ) {
		$found = array_merge( $found, kny_extract_metrika_ids( (string) $row ) );
	}
	if ( ! $found ) {
		return '';
	}
	$counts = array_count_values( $found );
	arsort( $counts );
	return (string) key( $counts );
}

/**
 * Достаёт ID счётчиков из текста.
 *
 * @param string $text Текст.
 * @return array
 */
function kny_extract_metrika_ids( $text ) {
	$ids      = array();
	$patterns = array(
		'/\bym\(\s*["\']?(\d{5,12})["\']?\s*,\s*["\']init/',
		'/mc\.yandex\.ru\/watch\/(\d{5,12})/',
		'/tag\.js\?id=(\d{5,12})/',
		'/yaCounter(\d{5,12})/',
	);
	foreach ( $patterns as $re ) {
		if ( preg_match_all( $re, $text, $m ) ) {
			$ids = array_merge( $ids, $m[1] );
		}
	}
	return $ids;
}
