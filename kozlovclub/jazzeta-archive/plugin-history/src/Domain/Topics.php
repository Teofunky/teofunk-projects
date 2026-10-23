<?php

namespace Kozlov\Gazeta\Domain;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Topics of a material (0.5.5): a small closed list of news types — «Релизы», «Концерты»,
 * «Конкурсы и премии»… — that cuts across the rubrics (Джаз, Блюз, Фестивали…).
 *
 * Tags are found automatically, by words in the headline, the lead and the text, so the JSON 1.1
 * exchange format does not change and no AI is involved. The editor may replace them by hand
 * (Data\Meta key `tags`); the manual choice wins. The rules keep tags quiet:
 *  - a word in the headline weighs 3, in the lead 2, anywhere in the text 1; a tag needs 2,
 *    so the text alone never adds one — the material has to be about it;
 *  - some words count only next to a firm one («выходит» — when an album is mentioned);
 *  - at most two tags, the second only when it weighs more than half of the first;
 *  - «Фестивали» makes «Концерты» redundant.
 * Pure functions: no WordPress calls, no database.
 */
final class Topics
{
    public const MAX = 2;

    /** Tag key => reader label. The order is the tie-break and the order of lists. */
    public const TAGS = [
        'releases' => 'Релизы',
        'concerts' => 'Концерты',
        'festivals' => 'Фестивали',
        'awards' => 'Конкурсы и премии',
        'interviews' => 'Интервью',
        'anniversaries' => 'Юбилеи',
        'memorial' => 'Памяти',
        'screen_books' => 'Кино и книги',
    ];

    /** Weight of a match in each part of the material. */
    private const WEIGHT_HEADLINE = 3;
    private const WEIGHT_LEAD = 2;
    private const WEIGHT_TEXT = 1;
    private const THRESHOLD = 2;

    /** A tag that makes another one redundant. */
    private const COVERS = ['festivals' => ['concerts']];

    /**
     * Word patterns on lower-cased text with «ё» folded to «е». Each pattern must match a whole
     * word: the engine adds word boundaries on both sides. Endings are listed where a bare stem
     * would catch other words («концертную запись» is a release, not a concert).
     */
    private const PATTERNS = [
        'releases' => [
            'альбом\p{L}*', 'сингл(?:а|у|ом|е|ы|ов|ам|ами|ах)?', 'пластинк\p{L}*', 'винил\p{L}*', 'переизда\p{L}*',
            'релиз\p{L}*', 'сборник\p{L}*', 'трек(?:а|у|ом|е|и|ов|ам|ами|ах)?', 'выпуск(?!ник)\p{L}*', 'выпущен\p{L}*',
            'выпуст(?:ил|ила|или|ит|ят|ять)', 'изда(?:ть|ст|ет|ют|дут|л|ла|ли|ло)', 'лейбл\p{L}*', 'ep', 'lp',
        ],
        'concerts' => [
            'концерт(?:а|у|ом|е|ы|ов|ам|ами|ах)?', 'гастрол\p{L}*', 'турне', 'сольник\p{L}*',
            'выступ(?:ит|ят|ает|ают)', 'сыгра(?:ет|ют)', 'исполн(?:ит|ят)',
        ],
        'festivals' => [
            'фестивал\p{L}*', 'festival\p{L}*', 'фест(?:а|е|у|ом|ы|ов)?',
        ],
        'awards' => [
            'конкурс\p{L}*', 'преми(?:я|и|ю|ей|ям|ями|ях)', 'наград\p{L}*', 'лауреат\p{L}*', 'номина(?:ц|нт)\p{L}*',
            'номинирова\p{L}*', 'грэмми', 'grammy', 'победител\p{L}*', 'побед(?:ил|ила|или)', 'выигра(?:л|ла|ли|ет|ют)',
            'стипенди\p{L}*', 'гран-при', 'приз(?:а|у|ом|е|ы|ов)?', 'призер\p{L}*',
        ],
        'interviews' => [
            'интервью', 'бесед(?:а|е|у|ой)',
        ],
        'anniversaries' => [
            'юбиле\p{L}*', 'годовщин\p{L}*', '\p{N}+-?лети\p{L}*', 'со дня (?:рождения|смерти|основания)',
        ],
        'memorial' => [
            'умер(?:ла|ли)?', 'скончал\p{L}*', 'ушл?(?:ел|а|и) из жизни', 'памяти', 'некролог\p{L}*', 'прощани\p{L}*',
        ],
        'screen_books' => [
            'фильм\p{L}*', 'кинофильм\p{L}*', 'документальн\p{L}*', 'кино', 'сериал\p{L}*', 'книг(?:а|и|у|е|ой|ам|ами|ах)?',
            'мемуар\p{L}*', 'автобиограф\p{L}*', 'биограф(?:ия|ии|ию|ией)', 'подкаст\p{L}*', 'саундтрек\p{L}*', 'экранизац\p{L}*',
        ],
    ];

    /**
     * Words that point to a tag only when the material also has one of its words above:
     * «выходит 23 октября» is a release when an album is mentioned somewhere, «программа» is a
     * concert when a concert is. Same syntax as PATTERNS.
     */
    private const WEAK = [
        'releases' => [
            'выход\p{L}*', 'выйд(?:ет|ут)', 'вышл?(?:ел|а|и|о)', 'запис(?:ь|и|ью|ям|ями|ях)', 'издани\p{L}*', 'тираж\p{L}*',
            'cd', 'предзаказ\p{L}*',
        ],
        'concerts' => ['программ\p{L}*'],
        'awards' => ['финал\p{L}*', 'жюри', 'заявк\p{L}*'],
    ];

    /** @var array<string,array{0:string,1:?string}>|null compiled patterns: tag => [strong, weak] */
    private static $regex = null;

    // ---------------------------------------------------------------- vocabulary

    public static function isTag(string $key): bool
    {
        return isset(self::TAGS[$key]);
    }

    /** A key usable in a topic filter: a rubric or a tag. */
    public static function isTopic(string $key): bool
    {
        return isset(self::TAGS[$key]) || isset(Labels::CATEGORIES[$key]);
    }

    public static function label(string $key): string
    {
        return self::TAGS[$key] ?? (Labels::CATEGORIES[$key] ?? $key);
    }

    /**
     * Rubric and tags of a material as one list of filter keys, the rubric first.
     * «Фестивали» is both a rubric and a tag and appears once.
     * @param string[] $tags
     * @return string[]
     */
    public static function topics(string $category, array $tags): array
    {
        $out = [];
        if ($category !== '') {
            $out[] = $category;
        }
        foreach ($tags as $tag) {
            if (!in_array($tag, $out, true)) {
                $out[] = $tag;
            }
        }
        return $out;
    }

    // ---------------------------------------------------------------- automatic tags

    /**
     * Tags of a JSON 1.1 article, best first, at most MAX.
     * @return string[]
     */
    public static function auto(array $article): array
    {
        $scores = self::scores($article);
        foreach (self::COVERS as $tag => $covered) {
            if (isset($scores[$tag])) {
                foreach ($covered as $other) {
                    unset($scores[$other]);
                }
            }
        }
        $scores = array_filter($scores, static function ($score) {
            return $score >= self::THRESHOLD;
        });
        if (!$scores) {
            return [];
        }
        $order = array_flip(array_keys(self::TAGS));
        uksort($scores, static function ($a, $b) use ($scores, $order) {
            return [$scores[$b], $order[$a]] <=> [$scores[$a], $order[$b]];
        });
        $top = reset($scores);
        $out = [];
        foreach ($scores as $tag => $score) {
            if ($out && 2 * $score <= $top) {
                break;
            }
            $out[] = $tag;
            if (count($out) >= self::MAX) {
                break;
            }
        }
        return $out;
    }

    /**
     * Raw scores of every tag (for the editor's hint and the tests).
     * @return array<string,int>
     */
    public static function scores(array $article): array
    {
        $fields = [
            self::WEIGHT_HEADLINE => self::fold(self::scalar($article['headline'] ?? '')),
            self::WEIGHT_LEAD => self::fold(self::scalar($article['lead'] ?? '')),
            self::WEIGHT_TEXT => self::fold(self::scalar($article['summary'] ?? '') . ' ' . self::bodyText($article['body_paragraphs'] ?? [])),
        ];
        $scores = [];
        foreach (self::compiled() as $tag => [$strong, $weak]) {
            $hits = [];
            foreach ($fields as $weight => $text) {
                $hits[$weight] = $text !== '' && preg_match($strong, $text) === 1;
            }
            if ($weak !== null && in_array(true, $hits, true)) {
                foreach ($fields as $weight => $text) {
                    $hits[$weight] = $hits[$weight] || ($text !== '' && preg_match($weak, $text) === 1);
                }
            }
            $score = 0;
            foreach ($hits as $weight => $hit) {
                $score += $hit ? $weight : 0;
            }
            if ($score > 0) {
                $scores[$tag] = $score;
            }
        }
        return $scores;
    }

    // ---------------------------------------------------------------- manual choice

    /**
     * Stored manual choice (Data\Meta `tags`): {"mode":"manual","tags":[…]}; null means automatic.
     * Unknown keys are dropped, the list is cut to MAX. An empty manual list means «no tags».
     * @return string[]|null
     */
    public static function cleanManual($value): ?array
    {
        if (!is_array($value) || ($value['mode'] ?? '') !== 'manual' || !isset($value['tags']) || !is_array($value['tags'])) {
            return null;
        }
        $out = [];
        foreach ($value['tags'] as $tag) {
            if (is_string($tag) && self::isTag($tag) && !in_array($tag, $out, true)) {
                $out[] = $tag;
            }
        }
        // Vocabulary order, so the same choice always reads the same.
        $order = array_flip(array_keys(self::TAGS));
        usort($out, static function ($a, $b) use ($order) {
            return $order[$a] <=> $order[$b];
        });
        return array_slice($out, 0, self::MAX);
    }

    /** Value to store for a manual choice. @param string[] $tags */
    public static function manualValue(array $tags): array
    {
        return ['mode' => 'manual', 'tags' => self::cleanManual(['mode' => 'manual', 'tags' => $tags]) ?? []];
    }

    /**
     * The tags readers see: the editor's choice when there is one, otherwise the automatic ones.
     * @param string[]|null $manual result of cleanManual()
     * @return string[]
     */
    public static function effective(array $article, ?array $manual): array
    {
        return $manual !== null ? $manual : self::auto($article);
    }

    // ---------------------------------------------------------------- text

    /** Lower case, «ё» → «е», no tags or entities, single spaces. */
    public static function fold(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
        $text = strtr($text, ['ё' => 'е', "\u{00A0}" => ' ', '’' => "'"]);
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private static function bodyText($paragraphs): string
    {
        $parts = [];
        foreach ((array) $paragraphs as $paragraph) {
            if (is_scalar($paragraph)) {
                // A space between block tags keeps words of neighbouring paragraphs apart.
                $parts[] = str_replace('<', ' <', (string) $paragraph);
            }
        }
        return implode(' ', $parts);
    }

    private static function scalar($value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /** @return array<string,array{0:string,1:?string}> */
    private static function compiled(): array
    {
        if (self::$regex === null) {
            self::$regex = [];
            foreach (self::PATTERNS as $tag => $words) {
                self::$regex[$tag] = [self::wordRegex($words), isset(self::WEAK[$tag]) ? self::wordRegex(self::WEAK[$tag]) : null];
            }
        }
        return self::$regex;
    }

    /** @param string[] $words */
    private static function wordRegex(array $words): string
    {
        return '/(?<![\p{L}\p{N}])(?:' . implode('|', $words) . ')(?![\p{L}\p{N}])/u';
    }
}
