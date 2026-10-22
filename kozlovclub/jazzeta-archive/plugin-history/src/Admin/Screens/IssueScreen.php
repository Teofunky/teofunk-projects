<?php

namespace Kozlov\Gazeta\Admin\Screens;

use Kozlov\Gazeta\Admin\Admin;
use Kozlov\Gazeta\Admin\Ui;
use Kozlov\Gazeta\Data\Db;
use Kozlov\Gazeta\Data\Issues;
use Kozlov\Gazeta\Data\Meta;
use Kozlov\Gazeta\Domain\Duplicates;
use Kozlov\Gazeta\Domain\Labels;
use Kozlov\Gazeta\Domain\Readiness;
use Kozlov\Gazeta\Domain\Text;
use Kozlov\Gazeta\Domain\Validator;
use Kozlov\Gazeta\Front\Presenter;
use Kozlov\Gazeta\Front\View;
use Kozlov\Gazeta\Http\Router;
use Kozlov\Gazeta\Runtime\Mode;

if (!defined('ABSPATH')) {
    exit;
}

/** One issue: materials in order, assembly notes, readiness checklist, publication, history. */
final class IssueScreen
{
    public static function render(int $id): void
    {
        $row = Issues::find($id);
        if (!$row) {
            Ui::open('Выпуск не найден', 'es-newsroom', ['back' => [Admin::url(), 'Пульт']]);
            echo '<p class="es-empty">Такого выпуска нет. Возможно, ссылка устарела.</p>';
            Ui::close();
            return;
        }
        $doc = Issues::document($row);
        $socialIds = array_column($doc['social'], 'article_id');
        $articles = [];
        foreach (Issues::articleRows($id) as $articleRow) {
            $article = Db::decode($articleRow['draft_json']);
            $words = Text::words(Validator::renderBody($article['body_paragraphs'])) + Text::words($article['lead']);
            $articles[] = [
                'id' => $article['id'],
                'slug' => $articleRow['slug'],
                'headline' => $article['headline'],
                'placement' => $article['placement'],
                'rubric' => Labels::region($article['region']) . ' · ' . Labels::category($article['category']),
                'words' => $words,
                'minutes' => Text::readingMinutes($words),
                'score' => $article['story_score'],
                'levels' => array_values(array_unique(array_column($article['claims'], 'verification'))),
                'sources' => count($article['sources']),
                'reviewed' => Issues::isReviewed($articleRow),
                'reviewer' => $articleRow['reviewed_by'] ? Ui::userName((int) $articleRow['reviewed_by']) : '',
                'reviewed_at' => $articleRow['reviewed_at'],
                'blockers' => count(Validator::articleBlockers($article)),
                'social' => in_array($article['id'], $socialIds, true),
                'matches' => count(Duplicates::matches($article, $id)),
                'disclosure' => !empty($article['conflict_disclosure']),
                'public' => $articleRow['public_json'] !== null,
                'edit' => Admin::url($id, $article['id']),
                'preview' => Router::preview($id, (int) $row['version'], $articleRow['slug']),
            ];
        }
        $items = Readiness::check($row);
        $historyPage = isset($_GET['history_page']) ? max(1, absint($_GET['history_page'])) : 1;
        $history = Issues::history($id, $historyPage);
        $revisionId = isset($_GET['revision']) ? absint($_GET['revision']) : 0;
        $revision = null;
        if ($revisionId) {
            $stored = Issues::revision($id, $revisionId);
            if ($stored) {
                $snapshot = json_decode((string) $stored['snapshot'], true);
                $revision = [
                    'row' => $stored,
                    'headlines' => array_column($snapshot['document']['articles'] ?? [], 'headline'),
                    'ready' => !empty($snapshot['document']['editorial']['ready_for_human_review']),
                    'reviewed' => count(array_filter($snapshot['reviews'] ?? [], static function ($r) {
                        return !empty($r['reviewed_by']);
                    })),
                ];
            }
        }
        $canReplace = Mode::isOwner() && current_user_can('es_import') && !Issues::everPublished($row);
        $preview = Router::preview($id, (int) $row['version']);
        $title = esc_html(Labels::longDate($row['edition_date']));
        $subtitle = Ui::stateChip($row['state']) . ' <span>' . esc_html($row['number'] ? Labels::issueNumber($row['number']) : 'номер присвоится при первой публикации') . '</span>'
            . ' <span class="es-muted">· рабочая версия ' . (int) $row['version'] . ((int) $row['public_version'] ? ' · опубликована версия ' . (int) $row['public_version'] : '') . '</span>';
        $actions = '<a class="es-btn" href="' . esc_url($preview) . '" target="_blank" rel="noopener">Предпросмотр ↗</a>';
        if ($row['state'] === 'published') {
            $actions .= ' <a class="es-btn" href="' . esc_url(Router::issue($row['edition_date'])) . '" target="_blank" rel="noopener">На сайте ↗</a>';
        }
        $actions .= ' <a class="es-btn es-btn--primary" href="' . esc_url(Admin::url($id, 'new')) . '">+ Материал</a>';

        Ui::open($title, 'es-newsroom', ['back' => [Admin::url(), 'Пульт'], 'subtitle' => $subtitle, 'actions' => $actions, 'class' => 'es-desk--issue']);
        View::render('admin/issue', [
            'row' => $row,
            'doc' => $doc,
            'articles' => $articles,
            'items' => $items,
            'summary' => Readiness::summary($items),
            'blocking' => Readiness::messages($items),
            'history' => $history,
            'history_page' => $historyPage,
            'revision' => $revision,
            'is_owner' => Mode::isOwner(),
            'owner' => Ui::userName(Mode::publisherId()),
            'mode' => Mode::current(),
            'can_replace' => $canReplace,
            'social_count' => count($doc['social']),
            'cover' => Meta::forIssue($id, 'draft')['cover'],
            'crossword' => Meta::forIssue($id, 'draft')['crossword'],
            'crossword_preview' => \Kozlov\Gazeta\Http\Router::preview($id, (int) $row['version']) . '&crossword=1',
            'lead_photo' => self::leadPhoto($doc),
            'plates' => Presenter::PLATE_NAMES,
        ]);
        Ui::close();
    }

    /** The photo of the lead story, if it has one: the default cover. */
    private static function leadPhoto(array $doc): string
    {
        foreach ($doc['articles'] as $article) {
            if ($article['placement'] === 'lead') {
                return (string) ($article['media_rights']['image_url'] ?? '');
            }
        }
        return (string) ($doc['articles'][0]['media_rights']['image_url'] ?? '');
    }
}
