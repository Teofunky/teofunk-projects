<?php

namespace Kozlov\Gazeta\Domain;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Word stems for the newspaper search: «Дворжаком» and «Дворжак» meet at «дворжак».
 *
 * Russian follows the Snowball (Porter) algorithm for Russian; Latin words only lose a plural
 * «s». The stem is matched as a word prefix, so a slightly short stem costs precision, never
 * recall. Input is expected in lower case with «ё» already folded to «е» (Data\Search::normalize).
 */
final class Stemmer
{
    private const VOWELS = 'аеиоуыэюя';

    private const PERFECTIVE_GERUND = '/(?:(?<=[ая])(?:вшись|вши|в)|ившись|ывшись|ивши|ывши|ив|ыв)$/u';
    private const REFLEXIVE = '/(?:ся|сь)$/u';
    private const ADJECTIVE = '/(?:ими|ыми|его|ого|ему|ому|ее|ие|ые|ое|ей|ий|ый|ой|ем|им|ым|ом|их|ых|ую|юю|ая|яя|ою|ею)$/u';
    private const PARTICIPLE = '/(?:(?<=[ая])(?:ем|нн|вш|ющ|щ)|ивш|ывш|ующ)$/u';
    private const VERB = '/(?:(?<=[ая])(?:ла|на|ете|йте|ли|й|л|ем|н|ло|но|ет|ют|ны|ть|ешь|нно)|ила|ыла|ена|ейте|уйте|ите|или|ыли|ей|уй|ил|ыл|им|ым|ен|ило|ыло|ено|ят|ует|уют|ит|ыт|ены|ить|ыть|ишь|ую|ю)$/u';
    private const NOUN = '/(?:иями|ями|ами|ией|иям|ием|иях|ев|ов|ие|ье|еи|ии|ей|ой|ий|ям|ем|ам|ом|ах|ях|ию|ью|ия|ья|а|е|и|й|о|у|ы|ь|ю|я)$/u';
    private const SUPERLATIVE = '/(?:ейше|ейш)$/u';
    private const DERIVATIONAL = '/(?:ость|ост)$/u';

    public static function stem(string $word): string
    {
        if ($word === '' || !preg_match('/\p{L}/u', $word)) {
            return $word;
        }
        if (preg_match('/^[а-я]+$/u', $word)) {
            return self::russian($word);
        }
        if (preg_match('/^[a-z]+$/', $word) && strlen($word) > 3 && substr($word, -1) === 's' && substr($word, -2) !== 'ss') {
            return substr($word, 0, -1);
        }
        return $word;
    }

    private static function russian(string $word): string
    {
        [$prefix, $rv] = self::region($word);
        if ($rv === '') {
            return $word;
        }
        // Step 1: a perfective gerund, otherwise reflexive + adjectival | verb | noun.
        if (!self::cut($rv, self::PERFECTIVE_GERUND)) {
            self::cut($rv, self::REFLEXIVE);
            if (self::cut($rv, self::ADJECTIVE)) {
                self::cut($rv, self::PARTICIPLE);
            } elseif (!self::cut($rv, self::VERB)) {
                self::cut($rv, self::NOUN);
            }
        }
        // Step 2.
        self::cut($rv, '/и$/u');
        // Step 3: derivational ending inside R2.
        if (preg_match(self::DERIVATIONAL, $rv, $m) && self::inR2($prefix . $rv, mb_strlen($m[0]))) {
            self::cut($rv, self::DERIVATIONAL);
        }
        // Step 4.
        if (self::cut($rv, '/ь$/u') === false) {
            self::cut($rv, self::SUPERLATIVE);
            $rv = (string) preg_replace('/нн$/u', 'н', $rv);
        }
        return $prefix . $rv;
    }

    /** RV: the part after the first vowel. @return array{0:string,1:string} */
    private static function region(string $word): array
    {
        if (!preg_match('/^(.*?[' . self::VOWELS . '])(.*)$/u', $word, $m)) {
            return [$word, ''];
        }
        return [$m[1], $m[2]];
    }

    /** True when an ending of $length characters lies inside R2 of $word. */
    private static function inR2(string $word, int $length): bool
    {
        $r1 = self::after($word);
        $r2 = $r1 === null ? null : self::after($r1);
        return $r2 !== null && mb_strlen($r2) >= $length;
    }

    /** The region after the first non-vowel that follows a vowel; null when empty. */
    private static function after(string $text): ?string
    {
        if (!preg_match('/^.*?[' . self::VOWELS . '][^' . self::VOWELS . '](.*)$/u', $text, $m)) {
            return null;
        }
        return $m[1];
    }

    private static function cut(string &$text, string $pattern): bool
    {
        $result = preg_replace($pattern, '', $text, 1, $count);
        if ($count > 0 && is_string($result)) {
            $text = $result;
            return true;
        }
        return false;
    }
}
