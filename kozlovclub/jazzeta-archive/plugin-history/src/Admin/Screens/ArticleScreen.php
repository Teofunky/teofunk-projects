<?php

namespace Kozlov\Gazeta\Admin\Screens;

use Kozlov\Gazeta\Admin\Admin;
use Kozlov\Gazeta\Admin\Ui;
use Kozlov\Gazeta\Data\Db;
use Kozlov\Gazeta\Data\Issues;
use Kozlov\Gazeta\Data\Meta;
use Kozlov\Gazeta\Domain\Duplicates;
use Kozlov\Gazeta\Domain\Labels;
use Kozlov\Gazeta\Domain\Validator;
use Kozlov\Gazeta\Front\View;
use Kozlov\Gazeta\Http\Router;
use Kozlov\Gazeta\Domain\Topics;
use Kozlov\Gazeta\Domain\StyleHints;

if (!defined('ABSPATH')) {
    exit;
}

/** Article editor: text on the left, facts and decisions on the right. */
final class ArticleScreen
{
    public static function render(int $issueId, string $key): void
    {
        $issue = Issues::find($issueId);
        if (!$issue) {
            Ui::open('Выпуск не найден', 'es-newsroom', ['back' => [Admin::url(), 'Пульт']]);
            Ui::close();
            return;
        }
        $row = null;
        foreach (Issues::articleRows($issueId) as $candidate) {
            if ($candidate['external_id'] === $key) {
                $row = $candidate;
                break;
            }
        }
        $isNew = $row === null;
        if ($isNew && $key !== 'new') {
            Ui::open('Материал не найден', 'es-newsroom', ['back' => [Admin::url($issueId), 'К выпуску']]);
            echo '<p class="es-empty">Материал удалён из рабочей версии или адрес неверен.</p>';
            Ui::close();
            return;
        }
        $article = $isNew ? Issues::blankArticle() : Db::decode($row['draft_json']);
        $doc = Issues::document($issue);
        $social = null;
        foreach ($doc['social'] as $item) {
            if ($item['article_id'] === $article['id']) {
                $social = $item;
            }
        }
        $meta = Meta::forIssue($issueId, 'draft');
        $brief = $isNew ? [] : ($meta['briefs'][$article['id']] ?? []);
        $factsVisible = !$isNew && !empty($meta['facts_visible'][$article['id']]);
        $matches = $isNew ? [] : Duplicates::matches($article, $issueId);
        $recent = $isNew ? [] : Issues::recentStory($article['story_group_id'], $issue['edition_date'], $issueId);
        $title = $isNew ? 'Новый материал' : esc_html($article['headline']);
        $subtitle = esc_html(Labels::longDate($issue['edition_date'])) . ' · ' . Ui::stateChip($issue['state'])
            . (!$isNew ? ' · <span class="es-muted">адрес: /gazeta/material/' . esc_html($row['slug']) . '/</span>' : '');
        $actions = $isNew ? '' : '<a class="es-btn" href="' . esc_url(Router::preview($issueId, (int) $issue['version'], $row['slug'])) . '" target="_blank" rel="noopener">Предпросмотр ↗</a>';

        Ui::open($title, 'es-newsroom', ['back' => [Admin::url($issueId), 'Выпуск ' . Labels::date($issue['edition_date'])], 'subtitle' => $subtitle, 'actions' => $actions, 'class' => 'es-desk--article']);
        View::render('admin/article', [
            'issue' => $issue,
            'row' => $row,
            'is_new' => $isNew,
            'issue_published' => (int) ($issue['public_version'] ?? 0) > 0,
            'article' => $article,
            'body_html' => Validator::renderBody($article['body_paragraphs']),
            'social' => $social,
            'social_count' => count($doc['social']),
            'blockers' => $isNew ? [] : Validator::articleBlockers($article),
            'reviewed' => $row ? Issues::isReviewed($row) : false,
            'reviewer' => $row && $row['reviewed_by'] ? Ui::userName((int) $row['reviewed_by']) : '',
            'reviewed_at' => $row['reviewed_at'] ?? null,
            'review_note' => $row['review_note'] ?? '',
            'matches' => $matches,
            'recent' => $recent,
            'draft_key' => 'es-article-' . $issueId . '-' . ($isNew ? 'new' : $article['id']),
            'brief' => $brief,
            'facts_visible' => $factsVisible,
            'focus' => $isNew ? null : ($meta['focus'][$article['id']] ?? null),
            'focus_src' => self::pickerSource((string) ($article['media_rights']['image_url'] ?? '')),
            'facts_preview' => Meta::factsFromClaims($article['claims']),
            // 0.5.5: topics for search — the automatic ones of the saved text and the editor's own choice.
            'tags_auto' => $isNew ? [] : Topics::auto($article),
            'tags_manual' => $isNew ? null : ($meta['tags'][$article['id']] ?? null),
            // 0.5.6: advice on the writing; never blocks publication.
            'style_hints' => $isNew ? [] : StyleHints::check($article),
        ]);
        Ui::close();
    }

    /** A lighter size of a media-library photo for the focus picker; other addresses as they are. */
    private static function pickerSource(string $url): string
    {
        if ($url === '') {
            return '';
        }
        $id = function_exists('attachment_url_to_postid') ? (int) attachment_url_to_postid($url) : 0;
        $large = $id ? wp_get_attachment_image_url($id, 'large') : false;
        return is_string($large) && $large !== '' ? $large : $url;
    }
}
