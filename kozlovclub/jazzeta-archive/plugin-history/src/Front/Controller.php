<?php

namespace Kozlov\Gazeta\Front;

use Kozlov\Gazeta\Data\Db;
use Kozlov\Gazeta\Data\Meta;
use Kozlov\Gazeta\Data\Issues;
use Kozlov\Gazeta\Data\Search;
use Kozlov\Gazeta\Domain\Labels;
use Kozlov\Gazeta\Domain\Validator;
use Kozlov\Gazeta\Http\Router;
use Kozlov\Gazeta\Runtime\Guard;
use Kozlov\Gazeta\Runtime\Mode;
use Kozlov\Gazeta\Runtime\Request;
use Kozlov\Gazeta\Settings\Appearance;

if (!defined('ABSPATH')) {
    exit;
}

/** Resolves a newspaper request into a view model and renders it inside the club theme. */
final class Controller
{
    public static function init(): void
    {
        add_action('template_redirect', Guard::wrap([self::class, 'route']), 0);
    }

    public static function route(): void
    {
        $view = (string) get_query_var('es_view');
        if ($view === '') {
            return;
        }
        if (!Mode::canRead()) {
            Guard::deny(404);
        }
        if (!in_array($view, Router::VIEWS, true)) {
            self::notFound();
        }
        if (!in_array(Request::method(), ['GET', 'HEAD'], true)) {
            if (!headers_sent()) {
                header('Allow: GET, HEAD');
            }
            self::notFound(405);
        }
        if ($view === 'sitemap' || $view === 'sitemap_part') {
            Sitemap::render($view, (string) get_query_var('es_key'), (int) get_query_var('es_page'));
            exit;
        }
        $key = (string) get_query_var('es_key');
        $page = max(1, min(1000000, (int) get_query_var('es_page', 1)));

        switch ($view) {
            case 'preview':
                $model = self::preview(absint($key));
                break;
            case 'latest':
                $issue = Issues::latestPublished();
                $model = $issue ? self::published($issue, null) : self::emptyModel();
                break;
            case 'issue':
            case 'legacy_issue':
                if (!Validator::isDate($key)) {
                    self::notFound();
                }
                $issue = Issues::publishedByDate($key);
                if (!$issue) {
                    self::notFound();
                }
                if ($view === 'legacy_issue') {
                    self::legacyRedirect($issue);
                }
                $model = self::published($issue, null);
                break;
            case 'article':
                $found = Issues::publishedArticle($key);
                if (!$found) {
                    self::notFound();
                }
                $model = self::published($found['issue'], $found['article']);
                break;
            case 'archive':
                $model = self::archive($page);
                break;
            case 'search':
                $model = self::search($page);
                break;
            default:
                self::notFound();
                return;
        }
        self::send($model, 200);
    }

    public static function base(string $view): array
    {
        $mode = Mode::current();
        $settings = Appearance::get();
        return [
            'view' => $view,
            'mode' => $mode,
            'design' => $settings['design'],
            'design_lock' => false,
            'theme_lock' => '',
            'private' => $mode !== Mode::OPEN,
            'preview' => false,
            'settings' => $settings,
            'name_parts' => Appearance::nameParts(),
            'brand' => Appearance::name(),
            'can_edit' => current_user_can('es_edit'),
            'is_owner' => Mode::isOwner(),
            'urls' => [
                'home' => Router::home(),
                'archive' => Router::archive(),
                'search' => Router::search(),
                'club' => (string) $settings['club_home_url'],
                'desk' => admin_url('admin.php?page=es-newsroom'),
                'mode' => admin_url('admin.php?page=es-runtime'),
            ],
            'issue' => null,
            'article' => null,
            'archive' => null,
            'search' => null,
            'neighbours' => [null, null],
        ];
    }

    /** Published issue or one of its articles. */
    private static function published(array $issue, ?array $article): array
    {
        $latest = Issues::latestPublished();
        $model = self::publishedModel(
            $issue,
            $article ? (string) $article['slug'] : null,
            Issues::neighbours($issue['edition_date']),
            $latest && (int) $latest['id'] === (int) $issue['id']
        );
        if ($model === null) {
            self::notFound();
        }
        return $model;
    }

    /** Pure assembly of a published page from stored rows (also used by the render harness). */
    /** @param array|null $meta public layer of Data\Meta; read from the database when null */
    public static function publishedModel(array $issue, ?string $articleSlug, array $neighbours, bool $isLatest, ?array $meta = null): ?array
    {
        $doc = Db::decode($issue['public_json']);
        $meta = $meta ?? Meta::forIssue((int) $issue['id'], 'public');
        $model = self::base($articleSlug !== null ? 'article' : 'issue');
        $model['issue'] = Presenter::issue($doc, [
            'preview' => false,
            'issue_id' => (int) $issue['id'],
            'version' => (int) $issue['version'],
            'cover' => $meta['cover'] ?? null,
            'briefs' => $meta['briefs'] ?? [],
            'facts' => $meta['facts'] ?? [],
            'focus' => $meta['focus'] ?? [],
        ]);
        $model['issue']['published_at'] = $issue['published_at'];
        $model['issue']['updated_at'] = $issue['public_updated_at'];
        $model['issue']['is_latest'] = $isLatest;
        $model['neighbours'] = $neighbours;
        if ($articleSlug !== null) {
            $model['article'] = self::findStory($model['issue'], $articleSlug);
            if (!$model['article']) {
                return null;
            }
        }
        $model['seo'] = Seo::forIssue($model, $issue);
        return $model;
    }

    private static function preview(int $issueId): array
    {
        $issue = $issueId ? Issues::find($issueId) : null;
        $nonce = isset($_GET['_esnonce']) && is_string($_GET['_esnonce']) ? sanitize_text_field(wp_unslash($_GET['_esnonce'])) : '';
        if (!$issue || !current_user_can('es_edit') || !Router::verifyPreview((int) $issue['id'], (int) $issue['version'], $nonce)) {
            self::notFound();
        }
        $selected = isset($_GET['article']) && is_string($_GET['article']) ? sanitize_key(wp_unslash($_GET['article'])) : '';
        $model = self::previewModel($issue, Issues::document($issue), Issues::articleRows((int) $issue['id']), $selected);
        if ($model === null) {
            self::notFound();
        }
        // The desk shows each reading mode side by side; this never applies to public URLs.
        $design = isset($_GET['design']) && is_string($_GET['design']) ? sanitize_key(wp_unslash($_GET['design'])) : '';
        if (isset(Appearance::DESIGNS[$design])) {
            $model['design'] = $design;
            $model['design_lock'] = true;
            $theme = isset($_GET['theme']) && is_string($_GET['theme']) ? sanitize_key(wp_unslash($_GET['theme'])) : '';
            $model['theme_lock'] = in_array($theme, ['light', 'dark'], true) ? $theme : 'light';
        }
        return $model;
    }

    /** Draft rendered exactly as readers would see it after approval. */
    /** @param array|null $meta draft layer of Data\Meta; read from the database when null */
    public static function previewModel(array $issue, array $draft, array $articleRows, string $selected, ?array $meta = null): ?array
    {
        $doc = ['edition_date' => $draft['edition_date'], 'number' => $issue['number'], 'articles' => [], 'social' => $draft['social']];
        $drafts = [];
        foreach ($articleRows as $row) {
            $article = Db::decode($row['draft_json']);
            $drafts[] = $article;
            $public = Validator::publicArticle($article);
            $public['slug'] = $row['slug'];
            $doc['articles'][] = $public;
        }
        $meta = $meta ?? Meta::forIssue((int) $issue['id'], 'draft');
        $model = self::base($selected !== '' ? 'article' : 'issue');
        $model['preview'] = true;
        $model['private'] = true;
        $model['issue'] = Presenter::issue($doc, [
            'preview' => true,
            'issue_id' => (int) $issue['id'],
            'version' => (int) $issue['version'],
            'cover' => $meta['cover'] ?? null,
            'briefs' => $meta['briefs'] ?? [],
            // The preview shows the facts list exactly as publishing will build it.
            'facts' => Meta::draftFacts($meta, $drafts),
            'focus' => $meta['focus'] ?? [],
        ]);
        $model['issue']['is_latest'] = true;
        $model['issue']['version'] = (int) $issue['version'];
        $model['issue']['state'] = $issue['state'];
        $model['urls']['desk_issue'] = admin_url('admin.php?page=es-newsroom&issue=' . (int) $issue['id']);
        if ($selected !== '') {
            $model['article'] = self::findStory($model['issue'], $selected);
            if (!$model['article']) {
                return null;
            }
        }
        $model['seo'] = Seo::forPreview($model);
        return $model;
    }

    private static function archive(int $page): array
    {
        $listing = Issues::listing($page, true);
        if ($page > max(1, $listing['pages'])) {
            self::notFound();
        }
        return self::archiveModel($listing, $page);
    }

    public static function archiveModel(array $listing, int $page): array
    {
        $model = self::base('archive');
        $model['archive'] = [
            'groups' => Presenter::archive($listing['items']),
            'page' => $page,
            'pages' => max(1, $listing['pages']),
            'total' => $listing['total'],
            'prev' => $page > 1 ? Router::archive($page - 1) : '',
            'next' => $page < $listing['pages'] ? Router::archive($page + 1) : '',
        ];
        $model['seo'] = Seo::forArchive($model, $page);
        return $model;
    }

    private static function search(int $page): array
    {
        $raw = isset($_GET['q']) && is_string($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';
        $found = Search::find($raw, $page);
        if ($page > 1 && $page > $found['pages']) {
            self::notFound();
        }
        return self::searchModel($found);
    }

    /** @param array $found result of Data\Search::find() */
    public static function searchModel(array $found): array
    {
        $model = self::base('search');
        $query = (string) $found['query'];
        $page = (int) $found['page'];
        $model['search'] = [
            'query' => $query,
            'searched' => $query !== '',
            'unavailable' => !empty($found['unavailable']),
            'understood' => $found['terms'] !== [],
            'total' => (int) $found['total'],
            'page' => $page,
            'pages' => (int) $found['pages'],
            'items' => Presenter::searchResults($found['rows'], $found['terms']),
            'prev' => $page > 1 ? Router::search($query, $page - 1) : '',
            'next' => $page < $found['pages'] ? Router::search($query, $page + 1) : '',
        ];
        $model['seo'] = Seo::forSearch($model);
        return $model;
    }

    public static function emptyModel(): array
    {
        $model = self::base('empty');
        $model['seo'] = Seo::forEmpty($model);
        return $model;
    }

    private static function findStory(array $issue, string $slug): ?array
    {
        foreach ($issue['stories'] as $index => $story) {
            if ($story['slug'] === $slug) {
                $story['prev'] = $issue['stories'][$index - 1] ?? null;
                $story['next'] = $issue['stories'][$index + 1] ?? null;
                return $story;
            }
        }
        return null;
    }

    private static function legacyRedirect(array $issue): void
    {
        if (Mode::current() === Mode::OPEN) {
            header('Cache-Control: no-cache, max-age=0, must-revalidate');
        } else {
            Guard::privateHeaders();
        }
        if (!wp_safe_redirect(Router::issue($issue['edition_date']), 301, 'Kozlov Gazeta')) {
            self::notFound(500);
        }
        exit;
    }

    /** Branded "not here" page inside the club chrome; always noindex. */
    public static function notFound(int $status = 404): void
    {
        $model = self::base('notfound');
        $model['seo'] = Seo::forNotFound($model);
        self::send($model, $status);
    }

    public static function send(array $model, int $status): void
    {
        status_header($status);
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=UTF-8');
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: SAMEORIGIN');
            if ($model['private'] || $model['preview'] || $status !== 200) {
                Guard::privateHeaders();
            } else {
                header('Cache-Control: no-cache, max-age=0, must-revalidate');
                header('Referrer-Policy: strict-origin-when-cross-origin');
            }
            if (is_user_logged_in()) {
                nocache_headers();
                header('Cache-Control: private, no-store, max-age=0');
                header('Vary: Cookie', false);
            }
        }
        self::render($model);
        exit;
    }

    /** Writes the whole HTML document for a model. */
    public static function render(array $model): void
    {
        $model['labels'] = [
            'mode' => Mode::LABELS[$model['mode']] ?? '',
            'state' => isset($model['issue']['state']) ? (Labels::ISSUE_STATES[$model['issue']['state']] ?? '') : '',
        ];
        Document::open($model);
        View::render('front/newspaper', $model);
        Document::close($model);
    }
}
