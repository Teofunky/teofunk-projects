<?php

namespace Kozlov\Gazeta\Runtime;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cheap request classification used before WordPress parses the query.
 * Kept free of database access: it runs on every page of the club site.
 */
final class Request
{
    public const BASE = 'gazeta';
    public const REST_NAMESPACE = 'ezhednevny-standard/v1';

    /** Request path relative to the site home, URL-decoded, e.g. "/gazeta/2026-10-21/". */
    public static function path(): string
    {
        $uri = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
        $path = rawurldecode((string) wp_parse_url($uri, PHP_URL_PATH));
        $base = rtrim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');
        if ($base !== '' && strpos($path, $base . '/') === 0) {
            $path = substr($path, strlen($base));
        }
        return $path === '' ? '/' : $path;
    }

    public static function isNewspaper(): bool
    {
        return (bool) preg_match('~^/' . self::BASE . '(?:/|$)~', self::path()) || isset($_GET['es_view']);
    }

    public static function isRestRoute($route): bool
    {
        return is_string($route) && (bool) preg_match('~^/' . preg_quote(self::REST_NAMESPACE, '~') . '(?:/|$)~', $route);
    }

    public static function isNewspaperRest(): bool
    {
        $prefix = '/' . rest_get_url_prefix();
        $path = self::path();
        if (strpos($path, $prefix . '/') === 0 && self::isRestRoute(substr($path, strlen($prefix)))) {
            return true;
        }
        return isset($_GET['rest_route']) && self::isRestRoute($_GET['rest_route']);
    }

    public static function method(): string
    {
        return isset($_SERVER['REQUEST_METHOD']) && is_string($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
    }

    /** A logged-in browser session for the current user (not Application Password, REST or cron). */
    public static function isInteractiveSession(): bool
    {
        $user = get_current_user_id();
        if ($user <= 0) {
            return false;
        }
        if ((defined('REST_REQUEST') && REST_REQUEST) || (defined('DOING_CRON') && DOING_CRON) || (defined('WP_CLI') && WP_CLI)) {
            return false;
        }
        return (int) wp_validate_auth_cookie('', 'logged_in') === $user;
    }
}
