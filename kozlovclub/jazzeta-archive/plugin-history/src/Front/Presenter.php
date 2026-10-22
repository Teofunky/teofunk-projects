<?php

namespace Kozlov\Gazeta\Front;

use Kozlov\Gazeta\Domain\Labels;
use Kozlov\Gazeta\Domain\Text;
use Kozlov\Gazeta\Domain\Validator;
use Kozlov\Gazeta\Http\Router;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Turns public snapshots into the one view model that all three reading modes share:
 * URLs, labels, reading time, cover, optional editorial blocks, sources.
 * Pure functions of their input; no database access (extras arrive in $opts).
 */
final class Presenter
{
    /** Editorial illustrations: used only when an editor explicitly picks one as the cover. */
    public const PLATE_NAMES = [
        'record' => 'Пластинка',
        'keys' => 'Клавиши',
        'strings' => 'Струны',
        'stage' => 'Сцена',
        'wave' => 'Звуковая волна',
        'microphone' => 'Микрофон',
    ];

    /** Editorial blocks in reading order, with their reader-facing headings. */
    public const BRIEF_BLOCKS = [
        'why' => 'Почему это важно',
        'context' => 'Контекст',
        'next' => 'Что дальше',
    ];

    /**
     * @param array $doc  {edition_date, number, articles[], social[]}
     * @param array $opts {preview:bool, issue_id:int, version:int, cover?:array, briefs?:array, facts?:array} — the last three from Data\Meta
     */
    public static function issue(array $doc, array $opts): array
    {
        $stories = [];
        foreach ($doc['articles'] as $article) {
            $stories[] = self::story($article, $doc, $opts);
        }
        $leadIndex = 0;
        foreach ($stories as $i => $story) {
            if ($story['placement'] === 'lead') {
                $leadIndex = $i;
                break;
            }
        }
        // The lead goes first in every mode; the rest keep the editor's order.
        if ($stories && $leadIndex > 0) {
            $lead = $stories[$leadIndex];
            array_splice($stories, $leadIndex, 1);
            array_unshift($stories, $lead);
        }
        foreach ($stories as $i => &$story) {
            $story['position'] = $i + 1;
            $story['number'] = $i + 1;
            $story['number_label'] = str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT);
            $story['is_lead'] = $i === 0;
            $story['grid'] = self::gridHints($i, count($stories));
        }
        unset($story);

        $words = 0;
        $regions = ['russia' => 0, 'world' => 0, 'mixed' => 0];
        $rubrics = [];
        foreach ($stories as $story) {
            $words += $story['words'];
            $regions[$story['region']] = ($regions[$story['region']] ?? 0) + 1;
            if (!isset($rubrics[$story['category']])) {
                $rubrics[$story['category']] = ['label' => $story['category_label'], 'anchor' => $story['anchor'], 'count' => 0];
            }
            $rubrics[$story['category']]['count']++;
        }

        $byId = [];
        foreach ($stories as $story) {
            $byId[$story['id']] = $story;
        }
        $digest = [];
        foreach ($doc['social'] ?? [] as $item) {
            if (isset($byId[$item['article_id']])) {
                $story = $byId[$item['article_id']];
                $digest[] = [
                    'id' => $story['id'],
                    'number' => $story['position'],
                    'headline' => $story['headline'],
                    'rubric' => $story['rubric'],
                    'instagram' => $item['instagram_sentence'],
                    'telegram' => $item['telegram_sentence'],
                    'url' => $opts['preview'] ? '' : $story['url'],
                    'disclosure' => $story['disclosure'],
                ];
            }
        }
        $count = count($stories);
        return [
            'date' => $doc['edition_date'],
            'number' => $doc['number'] ?? null,
            'number_label' => Labels::issueNumber($doc['number'] ?? null),
            'long_date' => Labels::longDate($doc['edition_date']),
            'human_date' => Labels::date($doc['edition_date']),
            'stories' => $stories,
            'lead' => $stories[0] ?? null,
            'count' => $count,
            'count_label' => Labels::count($count, 'материал', 'материала', 'материалов'),
            'words' => $words,
            'minutes' => max(1, Text::readingMinutes($words)),
            'regions' => $regions,
            'rubrics' => array_values($rubrics),
            'digest' => $digest,
            'cover' => self::cover($opts['cover'] ?? null, $stories[0] ?? null),
            'url' => $opts['preview'] ? Router::preview((int) $opts['issue_id'], (int) $opts['version']) : Router::issue($doc['edition_date']),
        ];
    }

    /**
     * Width hints for the desktop compositions, so the last row is never left with a hole:
     * «Газета» sets stories 4+ three to a row, «Современный» sets stories 2+ two to a row.
     * Pure presentation; phones and «Кратко» ignore them.
     */
    private static function gridHints(int $index, int $count): array
    {
        $hints = [];
        $rest = $count - 3;
        if ($index >= 3 && $rest > 0) {
            $pos = $index - 3;
            $tail = $rest % 3;
            if ($tail === 2 && $pos >= $rest - 2) {
                $hints[] = 'g-half';
            } elseif ($tail === 1 && $pos === $rest - 1) {
                $hints[] = 'g-full';
            }
        }
        if ($index >= 1 && ($count - 1) % 2 === 1 && $index === $count - 1) {
            $hints[] = 'm-full';
        }
        return $hints;
    }

    public static function story(array $a, array $doc, array $opts): array
    {
        $slug = (string) ($a['slug'] ?? '');
        $id = (string) $a['id'];
        $bodyHtml = Validator::renderBody($a['body_paragraphs'] ?? []);
        $words = Text::words($bodyHtml) + Text::words((string) ($a['lead'] ?? ''));
        // The opening of the text for a lead story printed without a photo: whole paragraphs,
        // never a cut sentence, about 90 words at most.
        $opening = [];
        $openingWords = 0;
        foreach ((array) ($a['body_paragraphs'] ?? []) as $paragraph) {
            $count = Text::words(Validator::renderBody([$paragraph]));
            if ($opening && $openingWords + $count > 90) {
                break;
            }
            $opening[] = $paragraph;
            $openingWords += $count;
            if (count($opening) === 2 || $openingWords >= 70) {
                break;
            }
        }
        $openingHtml = $opening && $openingWords <= 140 ? Text::typographHtml(Validator::renderBody($opening)) : '';
        $hasMore = count($opening) < count((array) ($a['body_paragraphs'] ?? []));
        $levels = array_values(array_intersect(array_keys(Labels::LEVELS), (array) ($a['verification'] ?? [])));
        $sources = array_map(static function ($s) {
            return [
                'title' => (string) $s['title'],
                'publisher' => (string) $s['publisher'],
                'url' => (string) $s['url'],
                'date' => !empty($s['published_at']) ? Labels::date($s['published_at']) : '',
                'datetime' => !empty($s['published_at']) ? substr((string) $s['published_at'], 0, 10) : '',
                'type' => (string) ($s['type'] ?? 'other'),
                'type_label' => Labels::SOURCE_TYPES[$s['type'] ?? 'other'] ?? 'Источник',
                'host' => preg_replace('/^www\./', '', (string) wp_parse_url((string) $s['url'], PHP_URL_HOST)),
            ];
        }, $a['sources'] ?? []);
        $brief = [];
        foreach (self::BRIEF_BLOCKS as $key => $title) {
            $text = trim((string) ($opts['briefs'][$id][$key] ?? ''));
            if ($text !== '') {
                $brief[] = ['key' => $key, 'title' => $title, 'text' => Text::typograph($text)];
            }
        }
        // Shown only when the editor opted in for this article (Data\Meta): checked facts and
        // single-source ones, never unverified claims or private notes.
        $facts = ['confirmed' => [], 'single' => []];
        foreach (['confirmed', 'single'] as $group) {
            foreach ((array) ($opts['facts'][$id][$group] ?? []) as $statement) {
                if (is_string($statement) && trim($statement) !== '') {
                    $facts[$group][] = Text::typograph(trim($statement));
                }
            }
        }
        return [
            'id' => $id,
            'slug' => $slug,
            'anchor' => 'story-' . ($slug !== '' ? $slug : $id),
            'url' => $opts['preview']
                ? Router::preview((int) $opts['issue_id'], (int) $opts['version'], $slug)
                : Router::article($slug),
            'region' => (string) $a['region'],
            'region_label' => Labels::region((string) $a['region']),
            'category' => (string) $a['category'],
            'category_label' => Labels::category((string) $a['category']),
            'rubric' => Labels::region((string) $a['region']) . ' · ' . Labels::category((string) $a['category']),
            'placement' => (string) $a['placement'],
            'headline' => Text::noWidow(Text::typograph((string) $a['headline'])),
            'headline_plain' => (string) $a['headline'],
            'lead' => Text::noWidow(Text::typograph((string) $a['lead'])),
            'lead_plain' => (string) $a['lead'],
            'summary' => (string) ($a['summary'] ?? ''),
            'body_html' => Text::typographHtml($bodyHtml),
            'opening_html' => $openingHtml,
            'opening_more' => $hasMore,
            'words' => $words,
            'minutes' => Text::readingMinutes($words),
            'event_date' => $a['event_date'] ?? null,
            'event_date_label' => !empty($a['event_date']) ? Labels::date($a['event_date']) : '',
            'event_is_future' => !empty($a['event_date']) && $a['event_date'] > $doc['edition_date'],
            'levels' => $levels,
            'level_labels' => array_map(static function ($k) {
                return Labels::LEVELS[$k];
            }, $levels),
            'weakest_level' => $levels ? end($levels) : '',
            'sources' => $sources,
            'source' => $sources[0] ?? null,
            'source_count' => count($sources),
            'disclosure' => self::disclosure($a['conflict_disclosure'] ?? null),
            'previous' => array_values((array) ($a['previous_article_ids'] ?? [])),
            'development_note' => Text::typograph((string) ($a['development_note'] ?? '')),
            'brief' => $brief,
            'facts' => $facts,
            'image' => self::image($a['media_rights'] ?? [], (string) $a['headline'], $opts),
        ];
    }

    /**
     * A material's own photo: only with stated rights. Attachment ids give srcset and sizes;
     * a plain URL (older data) is still shown, inside a fixed-ratio frame.
     */
    private static function image(array $media, string $headline, array $opts): ?array
    {
        $url = (string) ($media['image_url'] ?? '');
        if ($url === '' || ($media['status'] ?? 'none') === 'none') {
            return null;
        }
        if ($opts['preview'] && !Validator::isLocalImage($url)) {
            return null;
        }
        return [
            'attachment_id' => function_exists('attachment_url_to_postid') ? (int) attachment_url_to_postid($url) : 0,
            'url' => $url,
            'alt' => $headline,
            'credit' => Text::typograph((string) ($media['credit'] ?? '')),
        ];
    }

    /**
     * Issue cover: the editor's explicit choice, else the lead story's photo, else nothing.
     * A previous issue's picture or an illustration appears only when chosen.
     */
    private static function cover(?array $chosen, ?array $lead): ?array
    {
        if (is_array($chosen)) {
            $kind = (string) ($chosen['kind'] ?? '');
            if ($kind === 'none') {
                return null;
            }
            if ($kind === 'photo' && (int) ($chosen['attachment_id'] ?? 0) > 0) {
                return [
                    'kind' => 'photo',
                    'attachment_id' => (int) $chosen['attachment_id'],
                    'url' => (string) (wp_get_attachment_image_url((int) $chosen['attachment_id'], 'large') ?: ''),
                    'alt' => (string) ($chosen['alt'] ?? ''),
                    'credit' => Text::typograph((string) ($chosen['credit'] ?? '')),
                ];
            }
            if ($kind === 'plate' && isset(self::PLATE_NAMES[$chosen['plate'] ?? ''])) {
                return [
                    'kind' => 'plate',
                    'plate' => (string) $chosen['plate'],
                    'alt' => '',
                    'credit' => 'Иллюстрация редакции',
                ];
            }
        }
        if ($lead && $lead['image']) {
            return ['kind' => 'photo'] + $lead['image'];
        }
        return null;
    }

    /** The box is already titled «Связь с издателем»; drop the same words at the start of the text. */
    private static function disclosure(?string $text): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }
        $clean = (string) preg_replace('/^\s*связь с издателем\s*[:.—-]\s*/iu', '', $text);
        return Labels::ucfirst($clean !== '' ? $clean : $text);
    }

    /** Archive rows grouped by month: ["Октябрь 2026" => [rows]]. */
    public static function archive(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $month = Labels::ucfirst(Labels::monthYear($row['edition_date']));
            $row['url'] = Router::issue($row['edition_date']);
            $row['number_label'] = Labels::issueNumber($row['number']);
            $row['human_date'] = Labels::date($row['edition_date']);
            $row['weekday'] = Labels::ucfirst(Labels::WEEKDAYS[(int) (new \DateTimeImmutable($row['edition_date']))->format('w')]);
            $row['lead_headline'] = Text::noWidow(Text::typograph((string) ($row['lead_headline'] ?? '')));
            $row['lead_url'] = !empty($row['lead_slug']) ? Router::article($row['lead_slug']) : $row['url'];
            $row['headlines'] = array_map(static function ($h) {
                return Text::typograph((string) $h);
            }, (array) ($row['headlines'] ?? []));
            $row['count_label'] = isset($row['article_count']) ? Labels::count((int) $row['article_count'], 'материал', 'материала', 'материалов') : '';
            $groups[$month][] = $row;
        }
        return $groups;
    }
}
