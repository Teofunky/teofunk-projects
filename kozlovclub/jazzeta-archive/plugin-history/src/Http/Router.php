<?php

namespace Kozlov\Gazeta\Http;

use Kozlov\Gazeta\Runtime\Request;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Public addresses of the newspaper. The patterns and generated URLs are the 0.3.5 contract:
 * /gazeta/, /gazeta/YYYY-MM-DD/, /gazeta/material/{slug}/, /gazeta/archive/[page/N/],
 * /gazeta/preview/{id}/, sitemaps, and the legacy /gazeta/issue/YYYY-MM-DD/ redirect.
 * 0.5.1 adds /gazeta/search/[page/N/]?q=… (never indexed, never in sitemaps);
 * 0.5.2 adds /gazeta/crossword/ (all crosswords) and /gazeta/crossword/YYYY-MM-DD/.
 */
final class Router
{
    public const BASE = Request::BASE;
    public const VIEWS = ['latest', 'issue', 'legacy_issue', 'article', 'archive', 'preview', 'sitemap', 'sitemap_part', 'search', 'crossword', 'crosswords'];

    public static function rules(): array
    {
        return [
            '^gazeta/?$' => 'latest',
            '^gazeta/([0-9]{4}-[0-9]{2}-[0-9]{2})/?$' => 'issue&es_key=$matches[1]',
            '^gazeta/issue/([0-9]{4}-[0-9]{2}-[0-9]{2})/?$' => 'legacy_issue&es_key=$matches[1]',
            '^gazeta/material/([a-z0-9_-]{1,160})/?$' => 'article&es_key=$matches[1]',
            '^gazeta/archive/?$' => 'archive&es_page=1',
            '^gazeta/archive/page/([1-9][0-9]*)/?$' => 'archive&es_page=$matches[1]',
            '^gazeta/search/?$' => 'search&es_page=1',
            '^gazeta/search/page/([1-9][0-9]*)/?$' => 'search&es_page=$matches[1]',
            '^gazeta/crossword/?$' => 'crosswords',
            '^gazeta/crossword/([0-9]{4}-[0-9]{2}-[0-9]{2})/?$' => 'crossword&es_key=$matches[1]',
            '^gazeta/preview/([0-9]+)/?$' => 'preview&es_key=$matches[1]',
            '^gazeta/sitemap\.xml$' => 'sitemap',
            '^gazeta/sitemap-(issues|articles)-([1-9][0-9]*)\.xml$' => 'sitemap_part&es_key=$matches[1]&es_page=$matches[2]',
        ];
    }

    public static function registerRules(): void
    {
        foreach (self::rules() as $pattern => $query) {
            add_rewrite_rule($pattern, 'index.php?es_view=' . $query, 'top');
        }
    }

    public static function queryVars(array $vars): array
    {
        return array_merge($vars, ['es_view', 'es_key', 'es_page']);
    }

    private static function pretty(): bool
    {
        return (bool) get_option('permalink_structure');
    }

    public static function home(): string
    {
        return self::pretty() ? home_url('/' . self::BASE . '/') : add_query_arg('es_view', 'latest', home_url('/'));
    }

    public static function issue(string $date): string
    {
        return self::pretty()
            ? home_url('/' . self::BASE . '/' . $date . '/')
            : add_query_arg(['es_view' => 'issue', 'es_key' => $date], home_url('/'));
    }

    public static function article(string $slug): string
    {
        return self::pretty()
            ? home_url('/' . self::BASE . '/material/' . $slug . '/')
            : add_query_arg(['es_view' => 'article', 'es_key' => $slug], home_url('/'));
    }

    public static function archive(int $page = 1): string
    {
        if (!self::pretty()) {
            return add_query_arg(['es_view' => 'archive', 'es_page' => max(1, $page)], home_url('/'));
        }
        return home_url('/' . self::BASE . '/archive/' . ($page > 1 ? 'page/' . $page . '/' : ''));
    }

    /** The query is appended already encoded (add_query_arg() in WordPress does not encode values). */
    /** Search page; $topic (0.5.5) narrows the results to a rubric or a tag (Domain\Topics). */
    public static function search(string $query = '', int $page = 1, string $topic = ''): string
    {
        $page = max(1, $page);
        // Until the rewrite rules are flushed after an update, the query-string address still works.
        $rules = self::pretty() ? get_option('rewrite_rules') : [];
        if (self::pretty() && (!is_array($rules) || isset($rules['^gazeta/search/?$']))) {
            $url = home_url('/' . self::BASE . '/search/' . ($page > 1 ? 'page/' . $page . '/' : ''));
        } else {
            $url = add_query_arg(['es_view' => 'search', 'es_page' => $page], home_url('/'));
        }
        $args = [];
        if ($query !== '') {
            $args[] = 'q=' . rawurlencode($query);
        }
        if ($topic !== '') {
            $args[] = 'tema=' . rawurlencode($topic);
        }
        if (!$args) {
            return $url;
        }
        return $url . (strpos($url, '?') === false ? '?' : '&') . implode('&', $args);
    }

    /** All crosswords, or the crossword of one issue date. Query-string fallback until rules are flushed. */
    public static function crossword(string $date = ''): string
    {
        $rules = self::pretty() ? get_option('rewrite_rules') : [];
        if (self::pretty() && (!is_array($rules) || isset($rules['^gazeta/crossword/?$']))) {
            return home_url('/' . self::BASE . '/crossword/' . ($date !== '' ? $date . '/' : ''));
        }
        return $date !== ''
            ? add_query_arg(['es_view' => 'crossword', 'es_key' => $date], home_url('/'))
            : add_query_arg('es_view', 'crosswords', home_url('/'));
    }

    public static function sitemap(string $kind = '', int $page = 0): string
    {
        if ($kind === '') {
            return self::pretty() ? home_url('/' . self::BASE . '/sitemap.xml') : add_query_arg('es_view', 'sitemap', home_url('/'));
        }
        return self::pretty()
            ? home_url('/' . self::BASE . '/sitemap-' . $kind . '-' . $page . '.xml')
            : add_query_arg(['es_view' => 'sitemap_part', 'es_key' => $kind, 'es_page' => $page], home_url('/'));
    }

    /** Signed preview URL; the nonce is bound to the issue version, so stale links stop working. */
    public static function preview(int $issueId, int $version, string $articleSlug = ''): string
    {
        $base = self::pretty()
            ? home_url('/' . self::BASE . '/preview/' . $issueId . '/')
            : add_query_arg(['es_view' => 'preview', 'es_key' => $issueId], home_url('/'));
        $url = add_query_arg('_esnonce', wp_create_nonce('es-preview-' . $issueId . '-' . $version), $base);
        return $articleSlug !== '' ? add_query_arg('article', $articleSlug, $url) : $url;
    }

    public static function verifyPreview(int $issueId, int $version, string $nonce): bool
    {
        return (bool) wp_verify_nonce($nonce, 'es-preview-' . $issueId . '-' . $version);
    }
}
