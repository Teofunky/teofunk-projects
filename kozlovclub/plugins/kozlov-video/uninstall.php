<?php
/**
 * Удаление плагина. Записи архива и ручные правки (названия, скрытые записи, привязки к концертам)
 * сохраняются: таблица {prefix}kzv_videos и настройки остаются, при повторной установке всё на месте.
 * Убираются только задание по расписанию и временные данные. Страницы сайта не трогаются.
 * Удалить данные полностью: таблица wp_kzv_videos и опции kzv_settings, kzv_state, kzv_db_version.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

wp_clear_scheduled_hook( 'kzv_sync' );
foreach ( array( 'kzv_live_state', 'kzv_sync_lock', 'kzv_sync_soon' ) as $kzv_tr ) {
	delete_transient( $kzv_tr );
}
