<?php
// Temporary MU fixture, disposable local WordPress only. Never install on a real site.
if (!defined('ABSPATH') || wp_get_environment_type()!=='local' || wp_parse_url(home_url(),PHP_URL_HOST)!=='127.0.0.1') { return; }
add_action('plugins_loaded',static function() {
  $fail=get_option('es_test_manual_audit_failure');
  add_filter('query',static function($sql)use($fail) {
    if ($fail && strpos($sql,'INSERT INTO')===0 && strpos($sql,'es_revisions')!==false && strpos($sql,"'".$fail."'")!==false) { global $wpdb; $wpdb->suppress_errors(true); return 'INSERT INTO es_deliberately_missing_test_table (id) VALUES (1)'; }
    return $sql;
  });
});
add_action('wp_head',static function() {
    if (!\Kozlov\Standard\Runtime::front_request()) { return; }
    echo '<meta name="google-site-verification" content="ES_LOCAL_VERIFICATION"><link rel="canonical" href="https://invalid.example/wrong"><meta name="robots" content="index, follow"><meta property="og:title" content="Wrong page"><script type="application/ld+json">{"@context":"https://schema.org","@graph":[{"@type":"Organization","@id":"#es-local-org","name":"Local SEO fixture"},{"@type":"WebSite","@id":"#es-local-site","name":"Local SEO fixture"},{"@type":"WebPage","@id":"#wrong-page","name":"404 stale page"},{"@type":"BreadcrumbList","@id":"#wrong-breadcrumb"}]}</script>';
    echo '<script>window.esLocalHooks=["head"];</script><script src="https://es-integration.invalid/analytics.js"></script>';
});
foreach (['wp_body_open'=>'body','wp_footer'=>'footer','es_newspaper_head'=>'newspaper-head','es_newspaper_footer'=>'newspaper-footer'] as $hook=>$label) {
    add_action($hook,static function()use($label) { if (\Kozlov\Standard\Runtime::front_request()) { echo '<script>window.esLocalHooks=window.esLocalHooks||[];window.esLocalHooks.push("'.$label.'");</script>'; } });
}
