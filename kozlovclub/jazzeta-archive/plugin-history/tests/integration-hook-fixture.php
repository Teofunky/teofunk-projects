<?php
// Temporary MU-plugin installed by audit-browser.cjs ONLY on its disposable local WordPress.
if (!defined('ABSPATH') || wp_get_environment_type()!=='local' || wp_parse_url(home_url(),PHP_URL_HOST)!=='127.0.0.1') { return; }
add_action('es_newspaper_head',static function(){ echo '<meta name="es-audit-head" content="local-test">'; });
add_action('es_newspaper_footer',static function(){ echo '<div hidden id="es-audit-footer">local-test</div>'; });
add_action('wp_head',static function(){ echo '<meta name="es-unwanted-theme-hook" content="local-test">'; });
