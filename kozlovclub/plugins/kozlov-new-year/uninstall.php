<?php
/**
 * Удаление плагина: стираются только его опции. Страница WordPress остаётся (её можно удалить вручную).
 *
 * @package Kozlov_New_Year
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'kny_data' );
delete_option( 'kny_history' );
delete_option( 'kny_settings' );
delete_option( 'kny_version' );
