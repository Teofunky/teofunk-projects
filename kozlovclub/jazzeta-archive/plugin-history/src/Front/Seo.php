<?php

namespace Kozlov\Gazeta\Front;

use Kozlov\Gazeta\Http\Router;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Metadata of a newspaper page. Values are plain text; escaping happens on output.
 * Private views (closed mode, preview) never get canonical URLs or structured data.
 */
final class Seo
{
    public static function forIssue(array $model, array $row): array
    {
        $brand = $model['brand'];
        $issue = $model['issue'];
        $article = $model['article'];
        $private = $model['private'];
        if ($article) {
            $title = $article['headline_plain'] . ' — ' . $brand;
            $description = $article['lead_plain'];
            $canonical = Router::article($article['slug']);
        } else {
            $title = $brand . ' · ' . $issue['number_label'] . ' · ' . $issue['human_date'];
            $headlines = array_map(static function ($s) {
                return $s['headline_plain'];
            }, array_slice($issue['stories'], 0, 3));
            $description = $headlines ? 'В выпуске: ' . implode(' · ', $headlines) : 'Газета Клуба Алексея Козлова о джазе, блюзе и музыкальной культуре.';
            $canonical = Router::issue($issue['date']);
        }
        return [
            'title' => $title,
            'description' => self::excerpt($description),
            'canonical' => $private ? '' : $canonical,
            'robots' => self::robots($private, true),
            'og_type' => $article ? 'article' : 'website',
            'image' => $article
                ? ($article['image'] ? $article['image']['url'] : '')
                : (($issue['cover']['kind'] ?? '') === 'photo' ? (string) ($issue['cover']['url'] ?? '') : ''),
            'published' => $row['published_at'] ? gmdate('c', (int) strtotime($row['published_at'] . ' UTC')) : '',
            'modified' => $row['public_updated_at'] ? gmdate('c', (int) strtotime($row['public_updated_at'] . ' UTC')) : '',
            'schema' => $private ? [] : self::schema($model, $row, $canonical),
        ];
    }

    public static function forPreview(array $model): array
    {
        $title = 'Предпросмотр — ' . ($model['article'] ? $model['article']['headline_plain'] : $model['brand'] . ' · ' . $model['issue']['human_date']);
        return ['title' => $title, 'description' => '', 'canonical' => '', 'robots' => 'noindex, nofollow, noarchive', 'og_type' => 'website', 'image' => '', 'schema' => []];
    }

    public static function forArchive(array $model, int $page): array
    {
        $title = 'Подшивка' . ($page > 1 ? ' — страница ' . $page : '') . ' — ' . $model['brand'];
        return [
            'title' => $title,
            'description' => 'Все выпуски газеты «' . $model['brand'] . '» по датам и номерам.',
            'canonical' => $model['private'] ? '' : Router::archive($page),
            'robots' => self::robots($model['private'], true),
            'og_type' => 'website',
            'image' => '',
            'schema' => [],
        ];
    }

    public static function forEmpty(array $model): array
    {
        return [
            'title' => $model['brand'] . ' — газета о музыке',
            'description' => 'Газета Клуба Алексея Козлова о джазе, блюзе и музыкальной культуре.',
            'canonical' => '',
            'robots' => self::robots($model['private'], false),
            'og_type' => 'website',
            'image' => '',
            'schema' => [],
        ];
    }

    public static function forNotFound(array $model): array
    {
        return ['title' => 'Страница недоступна — ' . $model['brand'], 'description' => '', 'canonical' => '', 'robots' => 'noindex, nofollow', 'og_type' => 'website', 'image' => '', 'schema' => []];
    }

    private static function robots(bool $private, bool $indexable): string
    {
        if ($private || !$indexable || !get_option('blog_public')) {
            return 'noindex, nofollow, noarchive';
        }
        return 'index, follow, max-image-preview:large, max-snippet:-1';
    }

    private static function excerpt(string $text): string
    {
        return wp_html_excerpt(wp_strip_all_tags($text), 220, '…');
    }

    /** NewsArticle / PublicationIssue + breadcrumbs. Organization comes from Yoast when present. */
    private static function schema(array $model, array $row, string $url): array
    {
        $issue = $model['issue'];
        $article = $model['article'];
        $publisher = defined('WPSEO_VERSION')
            ? ['@id' => trailingslashit(home_url('/')) . '#organization']
            : ['@type' => 'Organization', 'name' => 'Клуб Алексея Козлова', 'url' => home_url('/')];
        $issueUrl = Router::issue($issue['date']);
        $crumbs = [
            ['@type' => 'ListItem', 'position' => 1, 'name' => $model['brand'], 'item' => Router::home()],
            ['@type' => 'ListItem', 'position' => 2, 'name' => 'Выпуск ' . $issue['number_label'] . ' · ' . $issue['human_date'], 'item' => $issueUrl],
        ];
        $graph = [];
        $published = $row['published_at'] ? gmdate('c', (int) strtotime($row['published_at'] . ' UTC')) : null;
        $modified = $row['public_updated_at'] ? gmdate('c', (int) strtotime($row['public_updated_at'] . ' UTC')) : $published;
        if ($article) {
            $crumbs[] = ['@type' => 'ListItem', 'position' => 3, 'name' => $article['headline_plain'], 'item' => $url];
            $node = [
                '@type' => 'NewsArticle',
                '@id' => $url . '#article',
                'headline' => $article['headline_plain'],
                'description' => $article['lead_plain'],
                'inLanguage' => 'ru',
                'mainEntityOfPage' => $url,
                'datePublished' => $published,
                'dateModified' => $modified,
                'wordCount' => $article['words'],
                'articleSection' => $article['category_label'],
                'author' => ['@type' => 'Organization', 'name' => 'Редакция «' . $model['brand'] . '»', 'url' => Router::home()],
                'publisher' => $publisher,
                'isPartOf' => ['@type' => 'PublicationIssue', 'issueNumber' => (string) $issue['number'], 'url' => $issueUrl],
                'citation' => array_map(static function ($s) {
                    return ['@type' => 'CreativeWork', 'name' => $s['title'], 'url' => $s['url'], 'publisher' => ['@type' => 'Organization', 'name' => $s['publisher']]];
                }, $article['sources']),
            ];
            if ($article['image']) {
                $node['image'] = [$article['image']['url']];
            }
            $graph[] = $node;
        } else {
            $graph[] = [
                '@type' => 'PublicationIssue',
                '@id' => $issueUrl . '#issue',
                'issueNumber' => (string) $issue['number'],
                'datePublished' => $published,
                'url' => $issueUrl,
                'name' => $model['brand'] . ' · ' . $issue['number_label'] . ' · ' . $issue['human_date'],
                'publisher' => $publisher,
                'hasPart' => array_map(static function ($s) {
                    return ['@type' => 'NewsArticle', 'headline' => $s['headline_plain'], 'url' => $s['url']];
                }, $issue['stories']),
            ];
        }
        $graph[] = ['@type' => 'BreadcrumbList', 'itemListElement' => $crumbs];
        return ['@context' => 'https://schema.org', '@graph' => $graph];
    }
}
