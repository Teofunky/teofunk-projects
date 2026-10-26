<?php
/**
 * Plugin Name: Kozlov — режим ошибок для основного сайта
 * Description: Только фатальные ошибки, без вывода на страницу, лог вне public_html. Кеш размеров папок не разрастается.
 *
 * Положить в wp-content/mu-plugins/ (создать папку, если её нет).
 * Лог: на уровень выше public_html, файл php-fatal.log
 * (для kozlovclub.ru: /home/t/trubkabb/kozlovclub.ru/php-fatal.log).
 * Не ловит ошибки, случившиеся до загрузки mu-плагинов (ядро, drop-in кеша).
 * На тестовом сайте НЕ ставить: там нужны все предупреждения.
 */

defined( 'ABSPATH' ) || exit;

@ini_set( 'display_errors', '0' );
@ini_set( 'log_errors', '1' );
@ini_set( 'error_log', dirname( ABSPATH ) . '/php-fatal.log' );

error_reporting( E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR );

// Кеш размеров папок (_transient_dirsize_cache) сохраняется пустым, иначе он разрастается до ~27 МБ и грузится в каждый запрос.
add_filter( 'pre_set_transient_dirsize_cache', '__return_empty_array' );
