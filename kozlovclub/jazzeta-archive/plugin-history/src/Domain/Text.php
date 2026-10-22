<?php

namespace Kozlov\Gazeta\Domain;

if (!defined('ABSPATH')) {
    exit;
}

/** Reading helpers: Russian typography, word counts, reading time, excerpts. */
final class Text
{
    private const NBSP = "\u{00A0}";

    /** Words that should not end a line in Russian typesetting. */
    private const SHORT_WORDS = 'а|в|во|и|к|ко|о|об|обо|с|со|у|я|на|не|ни|но|по|до|за|из|от|под|над|при|про|для|без|что|как|или|ли|же|бы';

    /**
     * Glues short prepositions/conjunctions to the next word and numbers to their units.
     * Works on plain text; never touches HTML markup.
     */
    public static function typograph(string $text): string
    {
        $text = (string) preg_replace('/(?<=^|[\s(«„"—])(' . self::SHORT_WORDS . ')\s+(?=\S)/iu', '$1' . self::NBSP, $text);
        $text = (string) preg_replace('/(№|§)\s*(?=\d)/u', '$1' . self::NBSP, $text);
        $text = (string) preg_replace('/(\d)\s+(?=(тыс|млн|млрд|руб|долл|г\.|гг\.|лет|года|мин|%|‰)\b)/u', '$1' . self::NBSP, $text);
        $text = (string) preg_replace('/\s+—\s+/u', self::NBSP . '— ', $text);
        return $text;
    }

    /** Keeps a short last word from hanging alone on the final line (headlines, leads). */
    public static function noWidow(string $text): string
    {
        return (string) preg_replace('/\s+(\S{1,12})$/u', self::NBSP . '$1', rtrim($text));
    }

    /** Applies typograph() to text nodes of already-sanitised HTML. */
    public static function typographHtml(string $html): string
    {
        $parts = preg_split('/(<[^>]+>)/u', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return $html;
        }
        foreach ($parts as $index => $part) {
            if ($part !== '' && $part[0] !== '<') {
                $parts[$index] = self::typograph($part);
            }
        }
        return implode('', $parts);
    }

    public static function words(string $html): int
    {
        $plain = html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $count = preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\'’-]*/u', $plain);
        return (int) $count;
    }

    /** Minutes at a calm 180 words per minute; at least one. */
    public static function readingMinutes(int $words): int
    {
        return max(1, (int) ceil($words / 180));
    }

    public static function length(string $text): int
    {
        return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    }

    /** First letter for a drop cap, skipping opening quotes. */
    public static function initial(string $html): string
    {
        $plain = trim(html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (preg_match('/\p{L}/u', $plain, $m)) {
            return $m[0];
        }
        return '';
    }
}
