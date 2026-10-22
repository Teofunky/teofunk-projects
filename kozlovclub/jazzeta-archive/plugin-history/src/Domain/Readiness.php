<?php

namespace Kozlov\Gazeta\Domain;

use Kozlov\Gazeta\Data\Db;
use Kozlov\Gazeta\Data\Issues;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Publication readiness of an issue. The same rules as 0.3.5, expressed as a checklist
 * so the desk can show what is done, what blocks publication and where to fix it.
 */
final class Readiness
{
    /**
     * @return array<int,array{code:string,ok:bool,title:string,details:string[],article:?string}>
     */
    public static function check(array $issue): array
    {
        $doc = Issues::document($issue);
        $items = [];

        $parsed = Validator::parse(Db::json($doc));
        if (is_wp_error($parsed)) {
            return [self::item('schema', false, 'Документ выпуска не проходит проверку схемы', [$parsed->get_error_message()])];
        }

        $count = count($doc['articles']);
        $items[] = self::item('count', $count >= 4 && $count <= 7, 'В номере 4–7 материалов',
            $count >= 4 && $count <= 7 ? [] : ['Полный номер содержит 4–7 материалов. Сейчас: ' . $count . '.']);

        $today = wp_date('Y-m-d', null, new \DateTimeZone('Europe/Moscow'));
        $items[] = self::item('date', $doc['edition_date'] <= $today, 'Дата выпуска наступила',
            $doc['edition_date'] <= $today ? [] : ['Дата выпуска ещё не наступила в Москве.']);

        $editorialOk = $doc['editorial']['ready_for_human_review'] && !$doc['editorial']['blocking_issues'];
        $editorialDetails = [];
        if (!$editorialOk) {
            $editorialDetails[] = 'Редакционные блокеры не сняты или выпуск не готов к проверке.';
            foreach ($doc['editorial']['blocking_issues'] as $note) {
                $editorialDetails[] = '— ' . $note;
            }
        }
        $items[] = self::item('editorial', $editorialOk, 'Редакционные блокеры сняты, номер отмечен готовым', $editorialDetails);

        $leads = count(array_filter($doc['articles'], static function ($a) {
            return $a['placement'] === 'lead';
        }));
        $items[] = self::item('lead', $leads === 1, 'Выбран один материал первой полосы',
            $leads === 1 ? [] : ['Нужен ровно один материал первой полосы. Сейчас: ' . $leads . '.']);

        foreach (Issues::articleRows((int) $issue['id']) as $row) {
            $article = Db::decode($row['draft_json']);
            $problems = Validator::articleBlockers($article);
            if (!Issues::isReviewed($row)) {
                $problems[] = 'Не подтверждена текущая версия: ' . $article['headline'];
            }
            if (Issues::recentStory($article['story_group_id'], $issue['edition_date'], (int) $issue['id']) && trim((string) $row['new_fact']) === '') {
                $problems[] = 'Сюжет уже выходил за последние 14 дней: ' . $article['headline'] . '. Укажите новый факт.';
            }
            $items[] = self::item('article', !$problems, $article['headline'], $problems, $article['id']);
        }

        // 0.5.2: a crossword attached to the issue must be a valid grid (it goes public with it).
        $suppress = Db::wpdb()->suppress_errors(true);
        $rawCrossword = Db::wpdb()->get_var(Db::wpdb()->prepare(
            'SELECT meta_value FROM ' . Db::table('meta') . " WHERE object_type='issue' AND object_id=%d AND layer='draft' AND meta_key='crossword'",
            (int) $issue['id']
        ));
        Db::wpdb()->suppress_errors($suppress);
        if ($rawCrossword !== null) {
            $crossword = Crossword::clean(json_decode((string) $rawCrossword, true));
            $placed = $crossword ? count($crossword['placements']) : 0;
            $ok = $crossword !== null && $placed >= Crossword::MIN_PLACED;
            $items[] = self::item('crossword', $ok, $ok
                ? 'Кроссворд недели: ' . Labels::count($placed, 'слово', 'слова', 'слов') . ', сетка ' . $crossword['rows'] . '×' . $crossword['cols']
                : 'Кроссворд недели', $ok ? [] : ['Кроссворд недели не собран или повреждён: загрузите файл заново или уберите кроссворд.']);
        }

        $duplicates = Duplicates::blockers($issue);
        $items[] = self::item('duplicates', !$duplicates, 'Нет повторов внутри номера и с архивом', $duplicates);

        return $items;
    }

    /** A detail line for display under its own item: the article's headline is already the item title. */
    public static function detail(array $item, string $detail): string
    {
        if (($item['code'] ?? '') !== 'article' || $item['title'] === '') {
            return $detail;
        }
        $detail = str_replace(': ' . $item['title'] . '.', '.', $detail);
        $suffix = ': ' . $item['title'];
        if (substr($detail, -strlen($suffix)) === $suffix) {
            $detail = substr($detail, 0, -strlen($suffix));
        }
        return $detail;
    }

    /** Flat list of blocking messages, identical in substance to 0.3.5. */
    public static function messages(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if (!$item['ok']) {
                foreach ($item['details'] as $detail) {
                    if (strpos($detail, '— ') !== 0) {
                        $out[] = $detail;
                    }
                }
            }
        }
        return array_values(array_unique($out));
    }

    /** @return array{done:int,total:int,articles_reviewed:int,articles_total:int} */
    public static function summary(array $items): array
    {
        $done = 0;
        $reviewed = 0;
        $articles = 0;
        foreach ($items as $item) {
            if ($item['ok']) {
                $done++;
            }
            if ($item['code'] === 'article') {
                $articles++;
                if ($item['ok']) {
                    $reviewed++;
                }
            }
        }
        return ['done' => $done, 'total' => count($items), 'articles_reviewed' => $reviewed, 'articles_total' => $articles];
    }

    private static function item(string $code, bool $ok, string $title, array $details, ?string $article = null): array
    {
        return ['code' => $code, 'ok' => $ok, 'title' => $title, 'details' => array_values(array_unique($details)), 'article' => $article];
    }
}
