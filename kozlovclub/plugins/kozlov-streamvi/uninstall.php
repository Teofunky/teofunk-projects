<?php
/**
 * Удаление плагина: настройки, ключи, журнал и расписание.
 * Поля «Трансляция StreamVi» в концертах (своё название, описание, «не транслируется») НЕ удаляются:
 * это работа редакторов, при повторной установке она вернётся. Доступ в кабинете StreamVi отзывается там же.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

foreach ( array( 'kzsv_settings', 'kzsv_secrets', 'kzsv_state', 'kzsv_next_due', 'kzsv_log', 'kzsv_lock' ) as $kzsv_opt ) {
	delete_option( $kzsv_opt );
}
foreach ( array( 'kzsv_heartbeat', 'kzsv_exact', 'kzsv_soon' ) as $kzsv_hook ) {
	wp_clear_scheduled_hook( $kzsv_hook );
}
delete_transient( 'kzsv_peek' );
delete_transient( 'kzsv_catchup' );
