<?php

namespace Kozlov\Gazeta\Front;

use Kozlov\Gazeta\Data\Db;
use Kozlov\Gazeta\Data\Meta;
use Kozlov\Gazeta\Data\Issues;
use Kozlov\Gazeta\Data\Search;
use Kozlov\Gazeta\Data\Crosswords;
use Kozlov\Gazeta\Domain\Crossword;
use Kozlov\Gazeta\Domain\Labels;
use Kozlov\Gazeta\Domain\Topics;
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
        self::enforceTrailingSlash();
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
            case 'crosswords':
                $model = self::crosswordsModel(Crosswords::published());
                break;
            case 'crossword':
                if (!Validator::isDate($key)) {
                    self::notFound();
                }
                [$current, $newer, $older] = Crosswords::around($key);
                if (!$current) {
                    self::notFound();
                }
                $model = self::crosswordModel($current, $newer, $older);
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
                'crosswords' => Router::crossword(),
                'club' => (string) $settings['club_home_url'],
                'desk' => admin_url('admin.php?page=es-newsroom'),
                'mode' => admin_url('admin.php?page=es-runtime'),
            ],
            'issue' => null,
            'article' => null,
            'archive' => null,
            'search' => null,
            'crossword' => null,
            'crosswords' => null,
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
        // 0.5.9: the crossword of the week hangs on every issue of its week, not only on its own issue.
        $active = Crosswords::activeFor((string) $issue['edition_date']);
        $model = self::base($articleSlug !== null ? 'article' : 'issue');
        $model['issue'] = Presenter::issue($doc, [
            'preview' => false,
            'issue_id' => (int) $issue['id'],
            'version' => (int) $issue['version'],
            'cover' => $meta['cover'] ?? null,
            'briefs' => $meta['briefs'] ?? [],
            'facts' => $meta['facts'] ?? [],
            'focus' => $meta['focus'] ?? [],
            'crossword' => $active['crossword'] ?? null,
            'crossword_date' => $active['edition_date'] ?? null,
            'crossword_number' => $active['number'] ?? null,
            'tags' => $meta['tags'] ?? [],
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
        if (isset($_GET['crossword'])) {
            $meta = Meta::forIssue((int) $issue['id'], 'draft');
            if (!$meta['crossword']) {
                self::notFound();
            }
            $answers = isset($_GET['answers']) && $_GET['answers'] === '1';
            $model = self::crosswordModel(['issue_id' => (int) $issue['id'], 'edition_date' => $issue['edition_date'], 'number' => $issue['number'], 'crossword' => $meta['crossword']], null, null, true, $answers);
            $model['urls']['answers'] = Router::preview((int) $issue['id'], (int) $issue['version']) . '&crossword=1' . ($answers ? '' : '&answers=1');
            return $model;
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
            'crossword' => $meta['crossword'] ?? null,
            'tags' => $meta['tags'] ?? [],
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
        // 0.5.5: «?tema=releases» — a rubric or a tag (Domain\Topics); anything else is ignored.
        $topic = isset($_GET['tema']) && is_string($_GET['tema']) ? sanitize_key(wp_unslash($_GET['tema'])) : '';
        $topic = Topics::isTopic($topic) ? $topic : '';
        // 0.5.8: «?rubric=jazz» is a separate filter; a rubric in «tema» (links before 0.5.8) moves there.
        $rubric = isset($_GET['rubric']) && is_string($_GET['rubric']) ? sanitize_key(wp_unslash($_GET['rubric'])) : '';
        $rubric = isset(Labels::CATEGORIES[$rubric]) ? $rubric : '';
        if ($rubric === '' && isset(Labels::CATEGORIES[$topic])) {
            $rubric = $topic;
            $topic = '';
        }
        $found = Search::find($raw, $page, $topic, $rubric);
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
        $topic = (string) ($found['topic'] ?? '');
        $rubric = (string) ($found['rubric'] ?? '');
        $filtered = $topic !== '' || $rubric !== '';
        // 0.5.8: «в рубрике «Джаз» и теме «Релизы»» for the texts; «Джаз · Релизы» as a short label.
        $phrase = [];
        $labels = [];
        if ($rubric !== '') {
            $phrase[] = 'рубрике «' . Topics::label($rubric) . '»';
            $labels[] = Topics::label($rubric);
        }
        if ($topic !== '') {
            $phrase[] = 'теме «' . Topics::label($topic) . '»';
            $labels[] = Topics::label($topic);
        }
        $model['search'] = [
            'query' => $query,
            'topic' => $topic,
            'rubric' => $rubric,
            'topic_label' => implode(' · ', $labels),
            'filter_phrase' => $phrase ? ' в ' . implode(' и ', $phrase) : '',
            'filter_title' => trim(($rubric !== '' ? 'Рубрика «' . Topics::label($rubric) . '»' : '') . ($rubric !== '' && $topic !== '' ? ' · ' : '') . ($topic !== '' ? 'Тема «' . Topics::label($topic) . '»' : '')),
            'topic_clear' => $filtered ? Router::search($query) : '',
            'searched' => $query !== '' || $filtered,
            'unavailable' => !empty($found['unavailable']),
            'understood' => $found['terms'] !== [] || $filtered,
            'has_words' => $found['terms'] !== [],
            'total' => (int) $found['total'],
            'page' => $page,
            'pages' => (int) $found['pages'],
            'items' => Presenter::searchResults($found['rows'], $found['terms']),
            'prev' => $page > 1 ? Router::search($query, $page - 1, $topic, $rubric) : '',
            'next' => $page < $found['pages'] ? Router::search($query, $page + 1, $topic, $rubric) : '',
            'topics' => self::topicChips(empty($found['unavailable']) ? Search::topicCounts() : [], $query, $topic, $rubric),
        ];
        $model['seo'] = Seo::forSearch($model);
        return $model;
    }

    /**
     * Topic links of the search page (0.5.5): rubrics and tags that have published materials,
     * each with its count; the active one links back to all topics.
     * @param array<string,int> $counts
     * @return array{rubrics:array,tags:array}
     */
    public static function topicChips(array $counts, string $query, string $active, string $activeRubric = ''): array
    {
        $out = ['rubrics' => [], 'tags' => []];
        foreach ([Labels::CATEGORIES, Topics::TAGS] as $group => $vocabulary) {
            foreach ($vocabulary as $key => $label) {
                if ($group === 1 && isset(Labels::CATEGORIES[$key])) {
                    continue; // «Фестивали» is listed once, with the rubrics.
                }
                // 0.5.8: a rubric chip changes only the rubric, a topic chip only the topic.
                $isRubric = $group === 0;
                $on = $isRubric ? $key === $activeRubric : $key === $active;
                $count = (int) ($counts[$key] ?? 0);
                if ($count === 0 && !$on) {
                    continue;
                }
                $out[$isRubric ? 'rubrics' : 'tags'][] = [
                    'order' => count($out['rubrics']) + count($out['tags']),
                    'key' => $key,
                    'label' => $label,
                    'count' => $count,
                    'active' => $on,
                    'url' => $isRubric
                        ? Router::search($query, 1, $active, $on ? '' : $key)
                        : Router::search($query, 1, $on ? '' : $key, $activeRubric),
                ];
            }
        }
        foreach (['rubrics', 'tags'] as $group) {
            usort($out[$group], static function ($a, $b) {
                return [$b['count'], $a['order']] <=> [$a['count'], $b['order']];
            });
        }
        return $out;
    }

    /**
     * The crossword page. $newer/$older are the neighbouring published crosswords (Data\Crosswords::around()).
     * In the editor's preview the answers can be shown on request.
     */
    public static function crosswordModel(array $item, ?array $newer, ?array $older, bool $preview = false, bool $showAnswers = false): array
    {
        $model = self::base('crossword');
        $date = (string) $item['edition_date'];
        $open = $preview ? $showAnswers : Crossword::answersOpen($date, $newer['edition_date'] ?? null);
        $model['crossword'] = Presenter::crossword($item['crossword'], [
            'date' => $date,
            'number' => $item['number'],
            'open' => $open,
            'preview' => $preview,
            'url' => $preview ? '' : Router::crossword($date),
            'issue_url' => $preview ? '' : Router::issue($date),
        ]);
        $model['crossword']['newer'] = $newer ? Presenter::crosswordTeaser($newer['crossword'], $newer['edition_date'], $newer['number'], Router::crossword($newer['edition_date'])) : null;
        // The previous crossword's answers are open by definition: this one is newer.
        $model['crossword']['previous'] = $older && !$preview ? Presenter::crossword($older['crossword'], [
            'date' => $older['edition_date'],
            'number' => $older['number'],
            'open' => true,
            'url' => Router::crossword($older['edition_date']),
            'issue_url' => Router::issue($older['edition_date']),
        ]) : null;
        if ($preview) {
            $model['preview'] = true;
            $model['private'] = true;
        }
        $model['seo'] = Seo::forCrossword($model);
        return $model;
    }

    /** All published crosswords, newest first. */
    public static function crosswordsModel(array $published): array
    {
        $model = self::base('crosswords');
        $items = [];
        foreach ($published as $item) {
            $items[] = Presenter::crosswordTeaser($item['crossword'], $item['edition_date'], $item['number'], Router::crossword($item['edition_date']));
        }
        $model['crosswords'] = ['items' => $items];
        $model['seo'] = Seo::forCrosswords($model);
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

    /**
     * 0.5.10: one address per page. «/gazeta/2026-10-21» and «/gazeta/2026-10-21/» both answered 200, and
     * search engines count that as two pages; now the address without the slash is a 301 to the canonical
     * one (the query string, e.g. ?q= of the search page, is kept). Query-string addresses (?es_view=) and
     * sitemaps are left alone.
     */
    private static function enforceTrailingSlash(): void
    {
        if (!get_option('permalink_structure') || headers_sent()) {
            return;
        }
        $uri = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        $rawPath = (string) wp_parse_url($uri, PHP_URL_PATH);
        if ($rawPath === '' || substr($rawPath, -1) === '/' || !preg_match('~^/' . Request::BASE . '(?:/|$)~', Request::path())) {
            return;
        }
        if (!preg_match('~^[A-Za-z0-9_\-./%]+$~', $rawPath)) {
            return;
        }
        $home = wp_parse_url(home_url('/'));
        if (empty($home['scheme']) || empty($home['host'])) {
            return;
        }
        $origin = $home['scheme'] . '://' . $home['host'] . (isset($home['port']) ? ':' . $home['port'] : '');
        $query = (string) wp_parse_url($uri, PHP_URL_QUERY);
        $target = $origin . $rawPath . '/' . ($query !== '' ? '?' . $query : '');
        header('Cache-Control: no-cache, max-age=0, must-revalidate');
        if (wp_safe_redirect($target, 301, 'Kozlov Gazeta')) {
            exit;
        }
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
