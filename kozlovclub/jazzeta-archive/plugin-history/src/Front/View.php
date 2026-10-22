<?php

namespace Kozlov\Gazeta\Front;

if (!defined('ABSPATH')) {
    exit;
}

/** Template renderer with a tiny escaping vocabulary. Templates receive one $v array. */
final class View
{
    public static function render(string $template, array $v): void
    {
        $file = ES_DIR . 'templates/' . $template . '.php';
        if (!preg_match('~^[a-z0-9/_-]+$~', $template) || !is_file($file)) {
            throw new \RuntimeException('Шаблон газеты не найден: ' . $template, 500);
        }
        (static function (string $__file, array $v) {
            require $__file;
        })($file, $v);
    }

    public static function capture(string $template, array $v): string
    {
        ob_start();
        try {
            self::render($template, $v);
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }

    public static function asset(string $path): string
    {
        return plugins_url('assets/' . ltrim($path, '/'), ES_FILE);
    }

    /** Asset URL with a cache-busting version derived from file contents. */
    public static function versioned(string $path): string
    {
        $file = ES_DIR . 'assets/' . ltrim($path, '/');
        $version = is_file($file) ? substr(md5_file($file), 0, 10) : ES_VERSION;
        return add_query_arg('ver', $version, self::asset($path));
    }

    /** Inline SVG from assets/img, already sanitised at build time (no scripts, no external refs). */
    public static function svg(string $name): string
    {
        if (!preg_match('/^[a-z0-9-]+$/', $name)) {
            return '';
        }
        $file = ES_DIR . 'assets/img/' . $name . '.svg';
        return is_file($file) ? (string) file_get_contents($file) : '';
    }
}
