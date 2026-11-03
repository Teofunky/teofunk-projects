<?php
// Данные концертов при удалении плагина НЕ удаляются: это контент сайта. Удаляются только настройки плагина.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }
delete_option( 'kc_settings' );
delete_option( 'kc_version' );
