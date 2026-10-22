<?php

namespace Kozlov\Gazeta;

use Kozlov\Gazeta\Admin\Admin;
use Kozlov\Gazeta\Data\Installer;
use Kozlov\Gazeta\Front\Controller;
use Kozlov\Gazeta\Http\RestApi;
use Kozlov\Gazeta\Http\Router;
use Kozlov\Gazeta\Runtime\Guard;
use Kozlov\Gazeta\Runtime\Mode;
use Kozlov\Gazeta\Runtime\Request;
use Kozlov\Gazeta\Settings\Appearance;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Wiring. On an ordinary page of the club site only a few hooks are registered and
 * no newspaper data is read; modules load on demand for /gazeta/, its REST namespace and wp-admin.
 */
final class Plugin
{
    public static function activate(bool $networkWide): void
    {
        try {
            Installer::activate($networkWide);
        } catch (\Throwable $e) {
            Guard::fault($e);
            wp_die('Газета не активирована: ошибка её модулей. Другие плагины и сайт не затронуты. Проверьте журнал сервера.');
        }
    }

    public static function boot(): void
    {
        if ((Request::isNewspaper() || Request::isNewspaperRest()) && !defined('DONOTCACHEPAGE')) {
            // W3 Total Cache and similar page caches must never store newspaper responses.
            define('DONOTCACHEPAGE', true);
        }
        add_action('init', [Router::class, 'registerRules']);
        add_filter('query_vars', [Router::class, 'queryVars']);
        add_action('parse_request', [self::class, 'gateRequest'], 0);
        add_filter('rest_pre_dispatch', [self::class, 'gateRest'], 1, 3);
        add_filter('rest_post_dispatch', [self::class, 'restHeaders'], 99, 3);
        add_filter('robots_txt', [self::class, 'robotsTxt'], 10, 2);
        add_shortcode('kozlov_jazzeta', [self::class, 'shortcode']);
        add_action('plugins_loaded', [self::class, 'start']);
    }

    public static function start(): void
    {
        $admin = is_admin() && !wp_doing_ajax();
        $cli = defined('WP_CLI') && WP_CLI;
        $newspaper = Request::isNewspaper();
        $rest = Request::isNewspaperRest();
        if (!$admin && !$cli && !$newspaper && !$rest) {
            return;
        }
        Guard::arm();
        if ($admin) {
            Admin::registerRecovery();
        }
        if (Mode::fault()) {
            return;
        }
        if (!$admin && !$cli && (Mode::current() === Mode::PAUSED || ($newspaper && !Mode::canRead()))) {
            return;
        }
        try {
            if (!Mode::ready()) {
                return;
            }
            if ($admin) {
                Admin::init();
            }
            RestApi::init();
            Controller::init();
        } catch (\Throwable $e) {
            Guard::fault($e);
        }
    }

    /** Runs before WordPress queries posts: unreadable newspaper requests stop here. */
    public static function gateRequest($wp): void
    {
        $view = is_object($wp) && isset($wp->query_vars['es_view']) ? $wp->query_vars['es_view'] : '';
        if (!Request::isNewspaper() && $view === '') {
            return;
        }
        if (!Mode::canRead()) {
            Guard::deny(404);
        }
        if (!Mode::ready()) {
            Guard::deny(503);
        }
        if (Mode::current() !== Mode::OPEN) {
            Guard::privateHeaders();
        }
    }

    public static function gateRest($result, $server, $request)
    {
        if (!is_object($request) || !Request::isRestRoute($request->get_route())) {
            return $result;
        }
        if (Mode::current() === Mode::PAUSED) {
            return new \WP_Error('es_unavailable', 'Страница недоступна.', ['status' => 404]);
        }
        if (!Mode::ready()) {
            return new \WP_Error('es_unavailable', 'Газета временно недоступна.', ['status' => 503]);
        }
        return $result;
    }

    public static function restHeaders($response, $server, $request)
    {
        if (is_object($request) && Request::isRestRoute($request->get_route()) && $response instanceof \WP_HTTP_Response) {
            $response->header('Cache-Control', 'private, no-store, max-age=0');
            $response->header('Vary', 'Cookie');
            $response->header('X-Robots-Tag', 'noindex, nofollow, noarchive');
        }
        return $response;
    }

    public static function robotsTxt($text, $public)
    {
        if (!$public || Mode::current() !== Mode::OPEN || !Mode::ready()) {
            return $text;
        }
        return $text . "\nSitemap: " . Router::sitemap() . "\n";
    }

    public static function shortcode(): string
    {
        if (!Mode::canRead() || !Mode::ready()) {
            return '';
        }
        return '<p class="es-entry"><a href="' . esc_url(Router::home()) . '">Читать «' . esc_html(Appearance::name()) . '» →</a></p>';
    }
}
