<?php
// Удаление плагина: стираем только свою настройку. Данные концертов не трогаем.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
delete_option( 'keh_settings' );
