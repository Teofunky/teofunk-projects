<?php

namespace Kozlov\Gazeta\Domain;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Hints for the editor about the writing (0.5.6). Never blocks publication.
 *
 * Built from the review of issue № 003 (Лев Боровков, 23.10.2026): drafts tend to show the
 * verification work to the reader — disclaimers, notes on method, reports of what a source
 * does not say — and to use calques like «релиз». In a newspaper the check stays invisible:
 * attribution in the text, sources at the end. Notes for the editor belong in editorial_notes.
 * Pure functions: no WordPress.
 */
final class StyleHints
{
    /** At most this many hints per material, the most useful first. */
    public const MAX = 8;

    /** [pattern, advice]. Patterns run on one sentence, lower case, «ё» → «е». */
    private const RULES = [
        ['/\bсам[аоий]? по себе\b/u', 'Оговорка «само по себе»: читателю она не нужна. Скажите факт прямо или уберите фразу.'],
        ['/\bв данном случае\b/u', '«В данном случае» — канцелярит. Обычно фраза лучше без него.'],
        ['/\bследует (?:сверить|уточнить|учитывать|проверить|иметь в виду)\b/u', 'Совет читателю «следует…» звучит как инструкция. Скажите, где смотреть, или уберите.'],
        ['/\bне (?:указан[аоы]?|назван[аоы]?|раскрыт[аоы]?|уточня\w*|сообща\w*)\b/u', 'Отчёт о том, чего нет в источнике. Если это не важно читателю, перенесите в заметки редакции.'],
        ['/\bпроверенн\w*\b|\bприведен[аоы]? по\b/u', 'Описание проверки в тексте. Методике место в заметках редакции.'],
        ['/\bредакция (?:воздерживается|не\b|считает|полагает)|\bмы (?:приводим|не\b|считаем|полагаем)/u', 'Редакция говорит о себе. Читателю важен факт, а не наша кухня.'],
        ['/\bотсутстви[еяю]\b/u', '«Отсутствие…» — цепочка существительных. Перестройте фразу глаголом.'],
        ['/\bявля(?:ется|ются|лся|лась|лись)\b/u', '«Является» — канцелярит: «X является Y» → «X — Y».'],
        ['/(?<!в )\bданн(?:ый|ая|ое|ые|ого|ому|ом|ой|ых)\b/u', '«Данный» → «этот».'],
        ['/\bколлаборац\w*\b/u', '«Коллаборация» → «совместная запись», «совместная работа».'],
        ['/\bрелиз\w*\b/u', '«Релиз» — калька. Скажите точнее: альбом, пластинка, сингл, запись, выход.'],
    ];

    /** Words that name a source in the text. */
    private const ATTRIBUTION = '/сообщени(?:ю|и|ем)|по данным|по информации|по словам|согласно|сообщ(?:ил|ила|или|ает|ают)|пиш(?:ет|ут)|рассказал|заявил|объявил|анонсировал|в интервью|как следует из|как уточня|уточнил|подтвердил/u';

    /**
     * @param array $article JSON 1.1 article (draft)
     * @return array<int,array{advice:string,quote:string}>
     */
    public static function check(array $article): array
    {
        $hints = [];
        $text = self::sentences(self::scalar($article['lead'] ?? '') . ' ' . self::bodyText($article['body_paragraphs'] ?? []));
        foreach (self::RULES as [$pattern, $advice]) {
            $quotes = [];
            foreach ($text as $sentence) {
                if (preg_match($pattern, Topics::fold($sentence))) {
                    $quotes[] = self::clip($sentence);
                }
            }
            if (!$quotes) {
                continue;
            }
            $advice .= count($quotes) > 1 ? ' Встречается ' . count($quotes) . ' раза.' : '';
            // Two remarks about one sentence read as one hint.
            $same = array_search($quotes[0], array_column($hints, 'quote'), true);
            if ($same !== false) {
                $hints[$same]['advice'] .= ' ' . $advice;
                continue;
            }
            $hints[] = ['advice' => $advice, 'quote' => $quotes[0]];
        }

        // Photo credit: service notes and the link CC BY needs.
        $media = is_array($article['media_rights'] ?? null) ? $article['media_rights'] : [];
        $credit = self::scalar($media['credit'] ?? '');
        if ($credit !== '' && Credit::clean($credit) !== trim((string) preg_replace('/\s+/u', ' ', $credit))) {
            array_unshift($hints, ['advice' => 'В подписи к фото служебная пометка или адрес ссылки текстом. Читатель их не увидит (сайт убирает их сам), но в данных лучше поправить.', 'quote' => self::clip($credit)]);
        }
        [$news, $picture] = Credit::split(is_array($article['sources'] ?? null) ? $article['sources'] : []);
        $page = array_filter($picture, static function ($source) {
            return is_array($source) && strpos((string) ($source['url'] ?? ''), 'creativecommons.org/') === false;
        });
        if ($credit !== '' && preg_match('/\bCC[ -]BY\b/i', $credit) && !$page && self::scalar($media['image_url'] ?? '') !== '') {
            array_unshift($hints, ['advice' => 'Фото по лицензии CC BY: нужна ссылка на страницу снимка (например, Wikimedia Commons). Добавьте её в источники — сайт покажет её в подписи.', 'quote' => '']);
        }

        // One source, and the text does not name it: readers no longer see an evidence badge.
        $levels = (array) ($article['verification'] ?? []);
        foreach ((array) ($article['claims'] ?? []) as $claim) {
            if (is_array($claim) && isset($claim['verification'])) {
                $levels[] = $claim['verification'];
            }
        }
        $single = in_array('attributed_single', $levels, true) || count($news) === 1;
        $all = Topics::fold(self::scalar($article['lead'] ?? '') . ' ' . self::bodyText($article['body_paragraphs'] ?? []));
        if ($single && $news && !preg_match(self::ATTRIBUTION, $all)) {
            array_unshift($hints, ['advice' => 'Материал держится на одном источнике, а в тексте он не назван. Добавьте «по сообщению …» или «как сообщил …».', 'quote' => '']);
        }
        return array_slice($hints, 0, self::MAX);
    }

    /** @return string[] */
    private static function sentences(string $text): array
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($text === '') {
            return [];
        }
        return preg_split('/(?<=[.!?…])\s+(?=[«"(\p{Lu}\p{N}])/u', $text) ?: [$text];
    }

    private static function bodyText($paragraphs): string
    {
        $parts = [];
        foreach ((array) $paragraphs as $paragraph) {
            if (is_scalar($paragraph)) {
                $parts[] = html_entity_decode(strip_tags(str_replace('<', ' <', (string) $paragraph)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }
        return implode(' ', $parts);
    }

    private static function scalar($value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function clip(string $sentence): string
    {
        $sentence = trim($sentence);
        if (function_exists('mb_strlen') && mb_strlen($sentence) > 180) {
            return rtrim(mb_substr($sentence, 0, 177)) . '…';
        }
        return $sentence;
    }
}
