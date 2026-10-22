<?php

namespace Kozlov\Gazeta\Admin;

if (!defined('ABSPATH')) {
    exit;
}

/** Word-level diff for the manual import preview. Plain text in, escaped HTML out. */
final class Diff
{
    /** Above this many LCS cells the diff falls back to "old → new" to stay light on memory. */
    private const MAX_CELLS = 250000;

    public static function words(string $old, string $new): string
    {
        $a = self::tokens($old);
        $b = self::tokens($new);
        if ($a === $b) {
            return esc_html($new);
        }
        if (count($a) * count($b) > self::MAX_CELLS) {
            return '<del>' . esc_html($old) . '</del> <ins>' . esc_html($new) . '</ins>';
        }
        $n = count($a);
        $m = count($b);
        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $a[$i] === $b[$j] ? $lcs[$i + 1][$j + 1] + 1 : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }
        $out = [];
        $i = 0;
        $j = 0;
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $out[] = ['=', $a[$i]];
                $i++;
                $j++;
            } elseif ($lcs[$i + 1][$j] >= $lcs[$i][$j + 1]) {
                $out[] = ['-', $a[$i++]];
            } else {
                $out[] = ['+', $b[$j++]];
            }
        }
        while ($i < $n) {
            $out[] = ['-', $a[$i++]];
        }
        while ($j < $m) {
            $out[] = ['+', $b[$j++]];
        }
        $html = '';
        $open = '';
        foreach ($out as [$op, $word]) {
            $tag = $op === '-' ? 'del' : ($op === '+' ? 'ins' : '');
            if ($tag !== $open) {
                if ($open !== '') {
                    $html .= '</' . $open . '>';
                }
                if ($tag !== '') {
                    $html .= '<' . $tag . '>';
                }
                $open = $tag;
            }
            $html .= esc_html($word) . ' ';
        }
        if ($open !== '') {
            $html .= '</' . $open . '>';
        }
        return trim($html);
    }

    private static function tokens(string $text): array
    {
        $text = trim(html_entity_decode(wp_strip_all_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        return $text === '' ? [] : (preg_split('/\s+/u', $text) ?: []);
    }

    /** Short description of what changed between two versions of one article. */
    public static function fields(array $old, array $new): array
    {
        $labels = [
            'headline' => 'заголовок', 'lead' => 'лид', 'body_paragraphs' => 'текст', 'summary' => 'выжимка',
            'sources' => 'источники', 'claims' => 'факты', 'quotes' => 'цитаты', 'placement' => 'место на полосе',
            'region' => 'регион', 'category' => 'рубрика', 'media_rights' => 'иллюстрация', 'conflict_disclosure' => 'раскрытие связи',
            'event_date' => 'дата события', 'editorial_notes' => 'заметки',
        ];
        $changed = [];
        foreach ($labels as $key => $label) {
            if (wp_json_encode($old[$key] ?? null) !== wp_json_encode($new[$key] ?? null)) {
                $changed[] = $label;
            }
        }
        return $changed;
    }
}
