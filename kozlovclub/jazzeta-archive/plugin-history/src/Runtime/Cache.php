<?php

namespace Kozlov\Gazeta\Runtime;

use Kozlov\Gazeta\Data\Db;
use Kozlov\Gazeta\Data\Issues;
use Kozlov\Gazeta\Http\Router;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Page-cache purge for the newspaper's own addresses after a publication, a withdrawal, a mode
 * change or a new default design. Never flushes the whole site: only /gazeta/ URLs.
 *
 * WordPress page caches (W3 Total Cache and similar) never store newspaper pages: Plugin::boot()
 * sets DONOTCACHEPAGE for /gazeta/. This purge is for layers in front of WordPress (a host or CDN
 * cache): it calls W3TC's public w3tc_flush_url() when present — whether W3TC is active on the site
 * is not verified — and fires `es_cache_purge_urls` for any other layer the host connects.
 */
final class Cache
{
    public static function purgeIssue(int $issueId): void
    {
        $issue = Issues::find($issueId);
        if (!$issue) {
            return;
        }
        $urls = self::common();
        $urls[] = Router::issue((string) $issue['edition_date']);
        $urls[] = Router::crossword((string) $issue['edition_date']);
        foreach (Issues::articleRows($issueId, false) as $row) {
            $urls[] = Router::article((string) $row['slug']);
        }
        self::purge($urls);
    }

    /** After a mode change or a new default design: the front pages of the last issues. */
    public static function purgeRecent(int $limit = 30): void
    {
        $urls = self::common();
        $wpdb = Db::wpdb();
        $suppress = $wpdb->suppress_errors(true);
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT id, edition_date FROM ' . Db::table('issues') . " WHERE state='published' ORDER BY edition_date DESC LIMIT %d",
            max(1, min(200, $limit))
        ), ARRAY_A);
        $wpdb->suppress_errors($suppress);
        foreach ((array) $rows as $row) {
            $urls[] = Router::issue((string) $row['edition_date']);
            foreach (Issues::articleRows((int) $row['id']) as $article) {
                $urls[] = Router::article((string) $article['slug']);
            }
        }
        self::purge($urls);
    }

    private static function common(): array
    {
        return [Router::home(), Router::archive(1), Router::archive(2), Router::archive(3), Router::sitemap(''), Router::crossword()];
    }

    private static function purge(array $urls): void
    {
        $urls = array_values(array_unique(array_filter($urls)));
        if (function_exists('w3tc_flush_url')) {
            foreach ($urls as $url) {
                w3tc_flush_url($url);
            }
        }
        do_action('es_cache_purge_urls', $urls);
    }
}
