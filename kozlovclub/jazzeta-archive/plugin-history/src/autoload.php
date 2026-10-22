<?php
/**
 * Minimal PSR-4 style loader: Kozlov\Gazeta\Front\Seo → src/Front/Seo.php.
 * No Composer at runtime: the plugin is installed by uploading a ZIP.
 */

namespace Kozlov\Gazeta;

if (!defined('ABSPATH')) {
    exit;
}

spl_autoload_register(static function (string $class): void {
    $prefix = __NAMESPACE__ . '\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    if (!preg_match('/^[A-Za-z0-9_\\\\]+$/', $relative)) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
