<?php

namespace Kozlov\Gazeta\Runtime;

use Kozlov\Gazeta\Data\Db;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fault containment. Any uncaught error inside newspaper code pauses the newspaper,
 * records a short fault note (no stack, no data) and answers with a neutral page.
 */
final class Guard
{
    /** @var bool */
    private static $failing = false;
    /** @var string|null Memory reserve released on fatal errors. */
    private static $reserve = null;

    public static function arm(): void
    {
        if (self::$reserve === null) {
            self::$reserve = str_repeat('x', 65536);
            register_shutdown_function([self::class, 'onShutdown']);
        }
    }

    /** Wrap a callback: output is buffered so a half-rendered page is never sent. */
    public static function wrap(callable $callback): \Closure
    {
        return static function (...$args) use ($callback) {
            $level = ob_get_level();
            ob_start();
            try {
                $result = $callback(...$args);
                while (ob_get_level() > $level) {
                    ob_end_flush();
                }
                return $result;
            } catch (\Throwable $e) {
                while (ob_get_level() > $level) {
                    ob_end_clean();
                }
                self::fault($e);
                self::deny(503);
            }
            return null;
        };
    }

    public static function fault(\Throwable $e): void
    {
        if (self::$failing) {
            return;
        }
        self::$failing = true;
        self::$reserve = null;
        if (class_exists(Db::class, false)) {
            Db::rollback();
        }
        $note = ['at' => gmdate('c'), 'type' => get_class($e), 'file' => basename($e->getFile()), 'line' => $e->getLine()];
        update_option('es_runtime_fault', $note, false);
        update_option('es_runtime', ['mode' => Mode::PAUSED, 'changed_by' => 0, 'changed_at' => gmdate('c')], false);
        error_log('Kozlov Gazeta stopped: ' . $note['type'] . ' ' . $note['file'] . ':' . $note['line']);
        self::$failing = false;
    }

    public static function onShutdown(): void
    {
        $error = error_get_last();
        if (!$error || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }
        if (strpos(wp_normalize_path((string) $error['file']), wp_normalize_path(ES_DIR)) !== 0) {
            return;
        }
        self::$reserve = null;
        // Best effort: no PHP handler can recover from every out-of-memory or server failure.
        if (class_exists(Db::class, false)) {
            Db::rollback();
        }
        update_option('es_runtime_fault', ['at' => gmdate('c'), 'type' => 'fatal', 'file' => basename((string) $error['file']), 'line' => (int) $error['line']], false);
        update_option('es_runtime', ['mode' => Mode::PAUSED, 'changed_by' => 0, 'changed_at' => gmdate('c')], false);
    }

    public static function privateHeaders(): void
    {
        if (headers_sent()) {
            return;
        }
        nocache_headers();
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        header('Vary: Cookie', false);
        header('Referrer-Policy: no-referrer');
    }

    /** Neutral answer that reveals nothing about drafts or the reason. */
    public static function deny(int $status): void
    {
        self::privateHeaders();
        status_header($status);
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=UTF-8');
            header('X-Content-Type-Options: nosniff');
        }
        echo '<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Страница недоступна</title></head>'
            . '<body style="font:18px/1.5 Georgia,serif;margin:12vh auto;max-width:34rem;padding:0 1.5rem;color:#191d1b;background:#f3f0e7">'
            . '<p>Страница недоступна.</p><p><a href="' . esc_url(home_url('/')) . '" style="color:#8b352d">На главную клуба</a></p></body></html>';
        exit;
    }
}
