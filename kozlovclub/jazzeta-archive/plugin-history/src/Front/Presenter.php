<?php

namespace Kozlov\Gazeta\Front;

use Kozlov\Gazeta\Data\Calendar;
use Kozlov\Gazeta\Data\Search;
use Kozlov\Gazeta\Domain\Credit;
use Kozlov\Gazeta\Domain\Crossword;
use Kozlov\Gazeta\Domain\Labels;
use Kozlov\Gazeta\Domain\Text;
use Kozlov\Gazeta\Domain\Topics;
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
     * @param array $opts {preview:bool, issue_id:int, version:int, cover?:array, briefs?:array, facts?:array, focus?:array, crossword?:array, tags?:array} — the last six from Data\Meta
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
        $tagChips = [];
        foreach ($stories as $story) {
            $words += $story['words'];
            $regions[$story['region']] = ($regions[$story['region']] ?? 0) + 1;
            if (!isset($rubrics[$story['category']])) {
                $rubrics[$story['category']] = ['key' => $story['category'], 'kind' => 'rubric', 'label' => $story['category_label'], 'anchor' => $story['anchor'], 'count' => 0];
            }
            $rubrics[$story['category']]['count']++;
            foreach ($story['tags'] as $tag) {
                if (!isset($tagChips[$tag])) {
                    $tagChips[$tag] = ['key' => $tag, 'kind' => 'tag', 'label' => Topics::label($tag), 'anchor' => $story['anchor'], 'count' => 0];
                }
                $tagChips[$tag]['count']++;
            }
        }
        // 0.5.5: «В номере» lists the rubrics, then the tags found in this issue (most first).
        // «Фестивали» is a rubric and a tag: one chip, counting both.
        foreach ($tagChips as $tag => $chip) {
            if (isset($rubrics[$tag])) {
                $rubrics[$tag]['count'] = 0;
                foreach ($stories as $story) {
                    $rubrics[$tag]['count'] += in_array($tag, $story['topics'], true) ? 1 : 0;
                }
                unset($tagChips[$tag]);
            }
        }
        $tagOrder = array_flip(array_keys(Topics::TAGS));
        uasort($tagChips, static function ($a, $b) use ($tagOrder) {
            return [$b['count'], $tagOrder[$a['key']]] <=> [$a['count'], $tagOrder[$b['key']]];
        });

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
            'topics' => array_merge(array_values($rubrics), array_values($tagChips)),
            'digest' => $digest,
            // 0.5.11: «Этот день в джазе», entries of the issue's own date (Data\Calendar).
            'calendar' => self::calendar((array) ($opts['calendar'] ?? []), (string) $doc['edition_date']),
            'cover' => self::cover($opts['cover'] ?? null, $stories[0] ?? null),
            // 0.5.2: the crossword of the week, when this issue carries one.
            // 0.5.9: the teaser links to the crossword's own date, which may be an earlier issue of the week.
            'crossword' => !empty($opts['crossword']) && is_array($opts['crossword'])
                ? self::crosswordTeaser(
                    $opts['crossword'],
                    (string) ($opts['crossword_date'] ?? $doc['edition_date']),
                    array_key_exists('crossword_date', $opts) ? ($opts['crossword_number'] ?? null) : ($doc['number'] ?? null),
                    $opts['preview']
                        ? Router::preview((int) $opts['issue_id'], (int) $opts['version']) . '&crossword=1'
                        : Router::crossword((string) ($opts['crossword_date'] ?? $doc['edition_date']))
                )
                : null,
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
        // 0.5.6: the picture's page and licence are credited under the picture, not listed as news sources.
        [$newsSources, $pictureSources] = Credit::split((array) ($a['sources'] ?? []));
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
        }, $newsSources);
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
        // 0.5.5: topics for filters and search — the editor's choice or the automatic one.
        $category = (string) $a['category'];
        $tags = Topics::effective($a, isset($opts['tags'][$id]) && is_array($opts['tags'][$id]) ? $opts['tags'][$id] : null);
        $tagLinks = [];
        foreach (Topics::topics($category, $tags) as $key) {
            // The rubric is already printed in the kicker; readers see only the other topics.
            if ($key !== $category) {
                $tagLinks[] = ['key' => $key, 'label' => Topics::label($key), 'url' => Router::search('', 1, $key)];
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
            'category_url' => Router::search('', 1, '', $category),
            'tags' => $tags,
            'tag_links' => $tagLinks,
            'topics' => Topics::topics($category, $tags),
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
            // 0.5.7: the material has text beyond the lead, so the card says «Читать дальше →».
            'has_body' => trim(wp_strip_all_tags($bodyHtml)) !== '',
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
            'image' => self::image($a['media_rights'] ?? [], (string) $a['headline'], $opts, $opts['focus'][(string) ($a['id'] ?? '')] ?? null, $pictureSources),
        ];
    }

    /**
     * A material's own photo: only with stated rights. Attachment ids give srcset and sizes;
     * a plain URL (older data) is still shown, inside a fixed-ratio frame.
     */
    private static function image(array $media, string $headline, array $opts, ?array $focus = null, array $pictureSources = []): ?array
    {
        $url = (string) ($media['image_url'] ?? '');
        if ($url === '' || ($media['status'] ?? 'none') === 'none') {
            return null;
        }
        if ($opts['preview'] && !Validator::isLocalImage($url)) {
            return null;
        }
        $attachmentId = function_exists('attachment_url_to_postid') ? (int) attachment_url_to_postid($url) : 0;
        $attachmentAlt = $attachmentId > 0 ? trim((string) get_post_meta($attachmentId, '_wp_attachment_image_alt', true)) : '';
        return [
            'attachment_id' => $attachmentId,
            'url' => $url,
            'alt' => $attachmentAlt !== '' ? $attachmentAlt : $headline,
            'credit' => Text::typograph(Credit::clean((string) ($media['credit'] ?? ''))),
            'credit_html' => self::creditHtml(Credit::segments((string) ($media['credit'] ?? ''), $pictureSources)),
            'focus' => $focus,
        ];
    }

    /**
     * Issue cover: the editor's explicit choice, else the lead story's photo, else nothing.
     * A previous issue's picture or an illustration appears only when chosen.
     */
    /**
     * 0.5.11: the calendar block, or null when the date has no entries. Club dates first, then by year.
     * Entries arrive already checked by Data\Calendar::validate(); here only the labels are built.
     */
    public static function calendar(array $items, string $date): ?array
    {
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['kind'], $item['year'], $item['age'], $item['name'])) {
                continue;
            }
            $entry = [
                'kind' => (string) $item['kind'],
                'year' => (int) $item['year'],
                'age' => (int) $item['age'],
                'name' => (string) $item['name'],
                'occupation' => (string) ($item['occupation'] ?? ''),
                'round' => Calendar::isRound((int) $item['age']),
                'club' => !empty($item['club']),
            ];
            $entry['label'] = Calendar::label($entry['kind'], $entry['age']);
            $entry['line'] = Calendar::line($entry);
            $out[] = $entry;
        }
        if (!$out) {
            return null;
        }
        usort($out, static function ($a, $b) {
            return [!$a['club'], $a['year']] <=> [!$b['club'], $b['year']];
        });
        return [
            'title' => 'Этот день в джазе',
            'date_label' => Labels::date($date, false),
            'items' => array_slice($out, 0, Calendar::MAX_ITEMS),
        ];
    }

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
                    'credit' => Text::typograph(Credit::clean((string) ($chosen['credit'] ?? ''))),
                    'credit_html' => self::creditHtml(Credit::segments((string) ($chosen['credit'] ?? ''), [])),
                    'focus' => isset($chosen['focus']) && is_array($chosen['focus']) ? $chosen['focus'] : null,
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

    /**
     * Safe HTML of a photo credit (0.5.6): escaped text, links to the picture's page and licence.
     * @param array<int,array{text:string,url:string}> $segments from Domain\Credit::segments()
     */
    public static function creditHtml(array $segments): string
    {
        $html = '';
        foreach ($segments as $segment) {
            $text = esc_html((string) $segment['text']);
            $url = (string) $segment['url'];
            $html .= $url !== '' && preg_match('~^https?://~i', $url)
                ? '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer' . (strpos($url, 'creativecommons.org/') !== false ? ' license' : '') . '">' . $text . '</a>'
                : $text;
        }
        return Text::typographHtml($html);
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

    /**
     * CSS object-position for a photo in a cropping frame: the editor's focus point; without one,
     * portrait photos keep their upper part (faces; 20 % chosen on a real concert portrait), everything else stays centred.
     * @param array|null $image ['focus' => ?{x,y}, 'attachment_id' => int]
     */
    public static function objectPosition(?array $image): string
    {
        $focus = is_array($image['focus'] ?? null) ? $image['focus'] : null;
        if ($focus && isset($focus['x'], $focus['y'])) {
            return (int) $focus['x'] . '% ' . (int) $focus['y'] . '%';
        }
        $id = (int) ($image['attachment_id'] ?? 0);
        if ($id > 0 && function_exists('wp_get_attachment_metadata')) {
            $meta = wp_get_attachment_metadata($id);
            if (is_array($meta) && (int) ($meta['width'] ?? 0) > 0 && (int) ($meta['height'] ?? 0) > (int) $meta['width'] * 1.05) {
                return '50% 20%';
            }
        }
        return '';
    }

    /** A query stem starts a word of the text (the same rule as Data\Search::snippet()). */
    private static function hasTerm(string $text, array $terms): bool
    {
        if (!$terms) {
            return false;
        }
        $quoted = array_map(static function ($t) {
            return preg_quote((string) $t, '/');
        }, $terms);
        return (bool) preg_match('/(?<![\p{L}\p{N}])(?:' . implode('|', $quoted) . ')/u', Search::fold($text));
    }

    /** Search results for the template: highlighted headline and passage, date, number and rubric. */
    public static function searchResults(array $rows, array $terms): array
    {
        $items = [];
        foreach ($rows as $row) {
            $a = (array) ($row['article'] ?? []);
            $lead = Search::plain((string) ($a['lead'] ?? ''));
            $body = [];
            foreach ((array) ($a['body_paragraphs'] ?? []) as $paragraph) {
                if (is_scalar($paragraph)) {
                    $body[] = Search::plain((string) $paragraph);
                }
            }
            // 0.5.8: the whole lead, as on the front page, never «лид + начало текста…» cut mid-sentence.
            // Only when the words are found in the text and not in the headline or lead does the
            // passage around them stand in for the lead.
            $inHead = !$terms || self::hasTerm($lead . ' ' . Search::plain((string) ($a['headline'] ?? '')), $terms);
            $snippet = $inHead || !$body
                ? Search::highlight($lead, $terms)
                : Search::snippet(implode(' ', $body), $terms);
            $rubric = array_filter([Labels::region((string) ($a['region'] ?? '')), Labels::category((string) ($a['category'] ?? ''))]);
            $tags = [];
            foreach (explode(' ', trim((string) ($row['topics'] ?? ''))) as $key) {
                if ($key !== '' && Topics::isTag($key) && $key !== ($a['category'] ?? '')) {
                    $tags[] = Topics::label($key);
                }
            }
            $items[] = [
                'url' => Router::article((string) $row['slug']),
                'issue_url' => Router::issue((string) $row['edition_date']),
                'headline_html' => Text::typographHtml(Search::highlight(Search::plain((string) ($a['headline'] ?? '')), $terms)),
                'snippet_html' => Text::typographHtml($snippet),
                'date' => (string) $row['edition_date'],
                'human_date' => Labels::date((string) $row['edition_date']),
                'number_label' => Labels::issueNumber($row['number']),
                'rubric' => implode(' · ', $rubric),
                'tags' => $tags,
            ];
        }
        return $items;
    }

    /**
     * The crossword page (0.5.2). Answers appear in the HTML only once they are open;
     * until then the browser gets them encoded for checking (Domain\Crossword::encode()).
     * @param array $opts {date:string, number:?int, open:bool, preview:bool, url:string, issue_url:string}
     */
    public static function crossword(array $cw, array $opts): array
    {
        $open = !empty($opts['open']);
        $grid = Crossword::grid($cw);
        $clues = Crossword::clues($cw);
        $starts = [];
        $where = [];
        $names = ['a' => 'по горизонтали', 'd' => 'по вертикали'];
        foreach (['a', 'd'] as $d) {
            foreach ($clues[$d] as $clue) {
                $starts[$clue['r']][$clue['c']] = $clue['n'];
                for ($i = 0; $i < $clue['len']; $i++) {
                    $r = $clue['r'] + ($d === 'd' ? $i : 0);
                    $c = $clue['c'] + ($d === 'a' ? $i : 0);
                    $where[$r][$c][] = $clue['n'] . ' ' . $names[$d] . ', буква ' . ($i + 1) . ' из ' . $clue['len'];
                }
            }
        }
        $cells = [];
        for ($r = 0; $r < $cw['rows']; $r++) {
            for ($c = 0; $c < $cw['cols']; $c++) {
                $cells[$r][$c] = isset($grid[$r][$c]) ? [
                    'n' => $starts[$r][$c] ?? null,
                    'label' => implode('; ', $where[$r][$c] ?? []),
                    'letter' => $open ? $grid[$r][$c] : '',
                ] : null;
            }
        }
        $entries = [];
        foreach (['a', 'd'] as $d) {
            foreach ($clues[$d] as &$clue) {
                $entries[] = ['n' => $clue['n'], 'd' => $d, 'r' => $clue['r'], 'c' => $clue['c'], 'len' => $clue['len'], 'clue' => $clue['clue'], 'enum' => $clue['enum']];
                if (!$open) {
                    $clue['answer'] = '';
                    $clue['source'] = '';
                }
            }
            unset($clue);
        }
        $encoded = Crossword::encode($cw);
        $placed = count($cw['placements']);
        return [
            'id' => (string) $cw['id'],
            'title' => Text::noWidow(Text::typograph((string) $cw['title'])),
            'plain_title' => (string) $cw['title'],
            'intro' => Text::typograph((string) $cw['intro']),
            'author' => (string) ($cw['author'] ?? ''),
            'rows' => (int) $cw['rows'],
            'cols' => (int) $cw['cols'],
            'words' => $placed,
            'words_label' => Labels::count($placed, 'слово', 'слова', 'слов'),
            'date' => (string) $opts['date'],
            'human_date' => Labels::date((string) $opts['date']),
            'number_label' => Labels::issueNumber($opts['number'] ?? null),
            'url' => (string) ($opts['url'] ?? ''),
            'issue_url' => (string) ($opts['issue_url'] ?? ''),
            'open' => $open,
            'opens_on' => Labels::date(gmdate('Y-m-d', (int) strtotime((string) $opts['date'] . ' +' . Crossword::ANSWERS_AFTER_DAYS . ' days'))),
            'cells' => $cells,
            'clues' => $clues,
            'data' => [
                'id' => (string) $cw['id'],
                'layout' => substr(md5($cw['rows'] . 'x' . $cw['cols'] . ':' . $encoded), 0, 12),
                'rows' => (int) $cw['rows'],
                'cols' => (int) $cw['cols'],
                'key' => (int) $cw['key'],
                'cells' => $encoded,
                'entries' => $entries,
                'open' => $open,
            ],
        ];
    }

    /** Small card for the issue page and the list of crosswords. */
    public static function crosswordTeaser(array $cw, string $date, $number, string $url): array
    {
        $placed = count($cw['placements']);
        return [
            'title' => Text::noWidow(Text::typograph((string) $cw['title'])),
            'intro' => Text::typograph((string) $cw['intro']),
            'words_label' => Labels::count($placed, 'слово', 'слова', 'слов'),
            'size' => (int) $cw['rows'] . '×' . (int) $cw['cols'],
            'date' => $date,
            'human_date' => Labels::date($date),
            'number_label' => Labels::issueNumber($number),
            'url' => $url,
        ];
    }
}
