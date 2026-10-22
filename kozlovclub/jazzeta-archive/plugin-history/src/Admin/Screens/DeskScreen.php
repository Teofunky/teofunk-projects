<?php

namespace Kozlov\Gazeta\Admin\Screens;

use Kozlov\Gazeta\Admin\Ui;
use Kozlov\Gazeta\Data\Db;
use Kozlov\Gazeta\Data\Issues;
use Kozlov\Gazeta\Domain\Labels;
use Kozlov\Gazeta\Domain\Readiness;
use Kozlov\Gazeta\Front\View;
use Kozlov\Gazeta\Http\Router;
use Kozlov\Gazeta\Runtime\Mode;

if (!defined('ABSPATH')) {
    exit;
}

/** "Пульт": today's issue first, then two weeks at a glance, then the full list. */
final class DeskScreen
{
    public static function render(): void
    {
        $tz = new \DateTimeZone('Europe/Moscow');
        $today = wp_date('Y-m-d', null, $tz);
        $todayRow = Issues::findByDate($today);
        $todayModel = $todayRow ? self::issueSummary($todayRow) : null;

        $from = (new \DateTimeImmutable($today, $tz))->modify('-13 days')->format('Y-m-d');
        $byDate = [];
        foreach (Issues::between($from, $today) as $row) {
            $byDate[$row['edition_date']] = $row;
        }
        $days = [];
        for ($i = 13; $i >= 0; $i--) {
            $date = (new \DateTimeImmutable($today, $tz))->modify('-' . $i . ' days');
            $key = $date->format('Y-m-d');
            $days[] = [
                'date' => $key,
                'day' => $date->format('j'),
                'weekday' => Labels::WEEKDAYS_SHORT[(int) $date->format('w')],
                'row' => $byDate[$key] ?? null,
                'today' => $key === $today,
            ];
        }

        $page = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
        $search = isset($_GET['date']) && is_string($_GET['date']) ? sanitize_text_field(wp_unslash($_GET['date'])) : '';
        $listing = Issues::listing($page, false, \Kozlov\Gazeta\Domain\Validator::isDate($search) ? $search : '');
        $latest = Issues::latestPublished();
        $import = Issues::lastImport();

        Ui::open('Пульт', 'es-newsroom', ['subtitle' => esc_html(Labels::longDate($today)) . ' · время московское']);
        View::render('admin/desk', [
            'today' => $today,
            'today_label' => Labels::longDate($today),
            'today_issue' => $todayModel,
            'days' => $days,
            'listing' => $listing,
            'page' => $page,
            'search' => $search,
            'latest' => $latest,
            'import' => $import,
            'mode' => Mode::current(),
            'owner' => Ui::userName(Mode::publisherId()),
            'is_owner' => Mode::isOwner(),
            'can_import' => current_user_can('es_import'),
        ]);
        Ui::close();
    }

    /** Compact readiness picture of one issue for cards. */
    public static function issueSummary(array $row): array
    {
        $items = Readiness::check($row);
        $summary = Readiness::summary($items);
        $failing = array_values(array_filter($items, static function ($item) {
            return !$item['ok'];
        }));
        $doc = Db::decode($row['draft_json']);
        return [
            'row' => $row,
            'summary' => $summary,
            'failing' => $failing,
            'articles' => count(Issues::articleRows((int) $row['id'])),
            'ready_flag' => !empty($doc['editorial']['ready_for_human_review']),
            'preview' => Router::preview((int) $row['id'], (int) $row['version']),
        ];
    }
}
