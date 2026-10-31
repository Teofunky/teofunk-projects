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
            $title = self::titled($article['headline_plain'], $brand);
            $description = $article['lead_plain'];
            $canonical = Router::article($article['slug']);
        } else {
            $title = $brand . ' · ' . $issue['number_label'] . ' · ' . $issue['human_date'];
            $headlines = array_map(static function ($s) {
                return $s['headline_plain'];
            }, array_slice($issue['stories'], 0, 3));
            $description = $headlines ? self::issueDescription($headlines) : 'Газета Клуба Алексея Козлова о джазе, блюзе и музыкальной культуре.';
            $canonical = Router::issue($issue['date']);
        }
        $image = $article
            ? ($article['image'] ? $article['image']['url'] : '')
            : (($issue['cover']['kind'] ?? '') === 'photo' ? (string) ($issue['cover']['url'] ?? '') : '');
        return [
            'title' => $title,
            'description' => self::excerpt($description),
            'canonical' => $private ? '' : $canonical,
            'robots' => self::robots($private, true),
            'og_type' => $article ? 'article' : 'website',
            'image' => $image !== '' ? $image : self::defaultImage(),
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
            'image' => self::defaultImage(),
            'schema' => [],
        ];
    }

    /** Search pages are useful to readers, not to search engines: never indexed, links still followed. */
    public static function forSearch(array $model): array
    {
        $query = (string) ($model['search']['query'] ?? '');
        $topic = (string) ($model['search']['topic_label'] ?? '');
        $filterTitle = (string) ($model['search']['filter_title'] ?? '');
        $parts = [];
        if ($filterTitle !== '') {
            $parts[] = $filterTitle; // 0.5.8: «Рубрика «Джаз» · Тема «Релизы»»
        } elseif ($topic !== '') {
            $parts[] = 'Тема «' . $topic . '»';
        }
        if ($query !== '') {
            $parts[] = 'Поиск: «' . $query . '»';
        }
        $title = ($parts ? implode(' · ', $parts) : 'Поиск по газете') . ' — ' . $model['brand'];
        return [
            'title' => $title,
            'description' => '',
            'canonical' => '',
            'robots' => $model['private'] ? 'noindex, nofollow, noarchive' : 'noindex, follow',
            'og_type' => 'website',
            'image' => '',
            'schema' => [],
        ];
    }

    public static function forCrossword(array $model): array
    {
        $cw = $model['crossword'];
        if ($model['preview']) {
            return ['title' => 'Предпросмотр кроссворда — ' . $model['brand'], 'description' => '', 'canonical' => '', 'robots' => 'noindex, nofollow, noarchive', 'og_type' => 'website', 'image' => '', 'schema' => []];
        }
        $description = $cw['intro'] !== '' ? $cw['intro'] : 'Кроссворд недели газеты «' . $model['brand'] . '»: ' . $cw['words_label'] . ', ответы — через неделю.';
        return [
            'title' => self::titled('Кроссворд: ' . $cw['plain_title'], $model['brand']),
            'description' => self::excerpt(wp_strip_all_tags(html_entity_decode($description, ENT_QUOTES, 'UTF-8'))),
            'canonical' => $model['private'] ? '' : $cw['url'],
            'robots' => self::robots($model['private'], true),
            'og_type' => 'website',
            'image' => self::defaultImage(),
            'schema' => [],
        ];
    }

    public static function forCrosswords(array $model): array
    {
        return [
            'title' => 'Кроссворды — ' . $model['brand'],
            'description' => 'Кроссворды недели газеты «' . $model['brand'] . '» о джазе и музыке нашего времени.',
            'canonical' => $model['private'] ? '' : Router::crossword(),
            'robots' => self::robots($model['private'], true),
            'og_type' => 'website',
            'image' => self::defaultImage(),
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

    /** Search snippets are cut at about 160 characters; longer descriptions are shown truncated anyway. */
    public const DESCRIPTION_MAX = 158;
    public const TITLE_MAX = 65;

    /** 0.5.10: up to DESCRIPTION_MAX characters, cut on a word boundary (was 220, cut mid-word). */
    private static function excerpt(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', wp_strip_all_tags($text)));
        if (mb_strlen($text, 'UTF-8') <= self::DESCRIPTION_MAX) {
            return $text;
        }
        $cut = mb_substr($text, 0, self::DESCRIPTION_MAX - 1, 'UTF-8');
        $space = mb_strrpos($cut, ' ', 0, 'UTF-8');
        if ($space !== false && $space > 40) {
            $cut = mb_substr($cut, 0, $space, 'UTF-8');
        }
        return rtrim($cut, " \t,.;:–—-") . '…';
    }

    /** «В выпуске: A · B · C»: as many headlines as fit, at least one; the last one may be shortened. */
    private static function issueDescription(array $headlines): string
    {
        $prefix = 'В выпуске: ';
        $parts = [];
        foreach ($headlines as $headline) {
            $candidate = $prefix . implode(' · ', array_merge($parts, [$headline]));
            if (mb_strlen($candidate, 'UTF-8') <= self::DESCRIPTION_MAX) {
                $parts[] = $headline;
                continue;
            }
            break;
        }
        return $parts ? $prefix . implode(' · ', $parts) : $prefix . $headlines[0];
    }

    /**
     * «Заголовок — Бренд» within TITLE_MAX: the full brand, then its last word («Джазета»), then the headline
     * alone (og:site_name still names the newspaper), then the headline cut on a word boundary with «…».
     */
    private static function titled(string $headline, string $brand): string
    {
        $full = $headline . ' — ' . $brand;
        if (mb_strlen($full, 'UTF-8') <= self::TITLE_MAX) {
            return $full;
        }
        $words = preg_split('/\s+/u', trim($brand));
        $short = is_array($words) && count($words) > 1 ? end($words) : '';
        if ($short !== '' && mb_strlen($headline . ' — ' . $short, 'UTF-8') <= self::TITLE_MAX) {
            return $headline . ' — ' . $short;
        }
        if (mb_strlen($headline, 'UTF-8') <= self::TITLE_MAX) {
            return $headline;
        }
        $cut = mb_substr($headline, 0, self::TITLE_MAX - 1, 'UTF-8');
        $space = mb_strrpos($cut, ' ', 0, 'UTF-8');
        if ($space !== false && $space > 30) {
            $cut = mb_substr($cut, 0, $space, 'UTF-8');
        }
        return rtrim($cut, " \t,.;:–—-") . '…';
    }

    /**
     * 0.5.10: picture for link previews when the page has none of its own (archive, crosswords, issues
     * without a photo cover, articles without a picture): the default social image from Yoast
     * (Settings → Site representation), else the newspaper logo from «Оформление», else nothing.
     */
    public static function defaultImage(): string
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        $cached = '';
        $social = get_option('wpseo_social');
        if (is_array($social) && !empty($social['og_default_image']) && is_string($social['og_default_image'])) {
            $cached = $social['og_default_image'];
            return $cached;
        }
        $logo = (int) (\Kozlov\Gazeta\Settings\Appearance::get()['logo_id'] ?? 0);
        if ($logo > 0) {
            $src = wp_get_attachment_image_src($logo, 'full');
            if (is_array($src) && !empty($src[0]) && get_post_mime_type($logo) !== 'image/svg+xml') {
                $cached = (string) $src[0];
            }
        }
        return $cached;
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
            // 0.5.5: the topics readers see under the article.
            if (!empty($article['tag_links'])) {
                $node['keywords'] = implode(', ', array_column($article['tag_links'], 'label'));
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
