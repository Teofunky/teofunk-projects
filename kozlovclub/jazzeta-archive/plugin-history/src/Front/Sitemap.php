<?php

namespace Kozlov\Gazeta\Front;

use Kozlov\Gazeta\Data\Db;
use Kozlov\Gazeta\Http\Router;
use Kozlov\Gazeta\Runtime\Mode;

if (!defined('ABSPATH')) {
    exit;
}

/** /gazeta/sitemap.xml index and 500-URL parts. Only in public mode on an indexable site. */
final class Sitemap
{
    private const PER_FILE = 500;

    public static function render(string $view, string $kind, int $page): void
    {
        if (Mode::current() !== Mode::OPEN || !get_option('blog_public')) {
            Controller::notFound();
        }
        $wpdb = Db::wpdb();
        $issues = Db::table('issues');
        $articles = Db::table('articles');
        $counts = [
            'issues' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$issues} WHERE state='published'"),
            'articles' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$articles} a JOIN {$issues} i ON i.id=a.issue_id WHERE i.state='published' AND a.public_json IS NOT NULL"),
        ];
        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        if ($view === 'sitemap') {
            $xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
            foreach ($counts as $name => $count) {
                for ($p = 1; $p <= (int) ceil($count / self::PER_FILE); $p++) {
                    $xml .= '<sitemap><loc>' . esc_xml(Router::sitemap($name, $p)) . '</loc></sitemap>';
                }
            }
            $xml .= '</sitemapindex>';
        } else {
            if (!isset($counts[$kind]) || $page < 1 || $page > (int) ceil($counts[$kind] / self::PER_FILE)) {
                Controller::notFound();
            }
            $offset = ($page - 1) * self::PER_FILE;
            if ($kind === 'issues') {
                $rows = $wpdb->get_results($wpdb->prepare("SELECT edition_date AS route,public_updated_at FROM {$issues} WHERE state='published' ORDER BY id LIMIT %d OFFSET %d", self::PER_FILE, $offset), ARRAY_A);
            } else {
                $rows = $wpdb->get_results($wpdb->prepare("SELECT a.slug AS route,i.public_updated_at FROM {$articles} a JOIN {$issues} i ON i.id=a.issue_id WHERE i.state='published' AND a.public_json IS NOT NULL ORDER BY a.id LIMIT %d OFFSET %d", self::PER_FILE, $offset), ARRAY_A);
            }
            $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
            foreach ($rows ?: [] as $row) {
                $loc = $kind === 'issues' ? Router::issue($row['route']) : Router::article($row['route']);
                $xml .= '<url><loc>' . esc_xml($loc) . '</loc><lastmod>' . esc_xml(gmdate('c', (int) strtotime($row['public_updated_at'] . ' UTC'))) . '</lastmod></url>';
            }
            $xml .= '</urlset>';
        }
        status_header(200);
        if (!headers_sent()) {
            header('Content-Type: application/xml; charset=UTF-8');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-cache, max-age=0');
        }
        echo $xml;
    }
}
