<?php

namespace Kozlov\Gazeta\Domain;

use Kozlov\Gazeta\Data\Search;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * «Кроссворд недели» (0.5.2): the file format es-crossword-1.0, its checks and the stored form.
 *
 * The editor sends answers and clues only — a JSON file or plain lines «Ответ — вопрос».
 * The grid is built here (CrosswordBuilder). The stored crossword lives in wp_es_meta under
 * the issue (key `crossword`, draft and public layers) and goes public with the issue. From 0.5.9 it
 * hangs on seven issues from its date (Data\Crosswords::activeFor()); the next one replaces it.
 *
 * Answers reach the reader's browser lightly encoded so that a glance at the page source does
 * not spoil the puzzle. This is not protection: there is no prize, and the full solution is
 * printed a week later.
 */
final class Crossword
{
    public const FORMAT = 'es-crossword-1.0';
    public const MAX_BYTES = 204800;
    public const MIN_WORDS = 12;
    public const MAX_WORDS = 60;
    public const MIN_PLACED = 10;
    public const MIN_LETTERS = 3;
    public const MAX_LETTERS = 21;
    public const ANSWERS_AFTER_DAYS = 7;
    /** Encoding alphabet: Russian without Ё (counted as Е), then Latin. */
    public const ALPHABET = 'АБВГДЕЖЗИЙКЛМНОПРСТУФХЦЧШЩЪЫЬЭЮЯABCDEFGHIJKLMNOPQRSTUVWXYZ';

    // ---------------------------------------------------------------- input

    /**
     * A JSON file of format es-crossword-1.0, or plain lines «Ответ — вопрос».
     * @param array $fallback title/intro/author typed in the form (used for plain text and empty fields)
     * @return array|\WP_Error ['title','intro','author','words' => [[answer, letters, enum, clue, source, note]], 'warnings' => string[]]
     */
    public static function parse(string $raw, array $fallback = [])
    {
        $raw = trim((string) preg_replace('/^\xEF\xBB\xBF/', '', $raw));
        if ($raw === '') {
            return Validator::error('Вставьте JSON-файл кроссворда или строки «Ответ — вопрос».');
        }
        if (strlen($raw) > self::MAX_BYTES) {
            return Validator::error('Файл кроссворда больше 200 КБ.', 413);
        }
        $warnings = [];
        if ($raw[0] === '{') {
            $data = json_decode($raw, true, 16);
            if (!is_array($data)) {
                return Validator::error('JSON кроссворда не читается: ' . json_last_error_msg() . '.');
            }
            if (($data['format'] ?? '') !== self::FORMAT) {
                return Validator::error('Поле format должно быть «' . self::FORMAT . '».');
            }
            $extra = array_diff(array_keys($data), ['format', 'title', 'intro', 'author', 'words']);
            if ($extra) {
                $warnings[] = 'Лишние поля пропущены: ' . implode(', ', array_slice($extra, 0, 5)) . '.';
            }
            $items = isset($data['words']) && is_array($data['words']) ? array_values($data['words']) : null;
            if ($items === null) {
                return Validator::error('Нет списка words.');
            }
            $head = [
                'title' => is_string($data['title'] ?? null) ? $data['title'] : '',
                'intro' => is_string($data['intro'] ?? null) ? $data['intro'] : '',
                'author' => is_string($data['author'] ?? null) ? $data['author'] : '',
            ];
        } else {
            $items = [];
            foreach (preg_split('/\R/u', $raw) ?: [] as $number => $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') {
                    continue;
                }
                $parts = preg_split('/\s+[—–|]\s+|\t+|\s+-\s+/u', $line, 2);
                if (!is_array($parts) || count($parts) < 2) {
                    return Validator::error('Строка ' . ($number + 1) . ': нужен формат «Ответ — вопрос».');
                }
                $items[] = ['answer' => $parts[0], 'clue' => $parts[1]];
            }
            $head = ['title' => '', 'intro' => '', 'author' => ''];
        }
        foreach (['title', 'intro', 'author'] as $key) {
            if (trim($head[$key]) === '' && isset($fallback[$key]) && is_string($fallback[$key])) {
                $head[$key] = $fallback[$key];
            }
        }
        $title = self::text($head['title']);
        $intro = self::text($head['intro']);
        $author = self::text($head['author']);
        $errors = [];
        if (mb_strlen($title) < 3 || mb_strlen($title) > 80) {
            $errors[] = 'Название — от 3 до 80 знаков.';
        }
        if (mb_strlen($intro) > 300) {
            $errors[] = 'Вступление — не длиннее 300 знаков.';
        }
        if (mb_strlen($author) > 80) {
            $errors[] = 'Автор — не длиннее 80 знаков.';
        }
        if (count($items) < self::MIN_WORDS || count($items) > self::MAX_WORDS) {
            $errors[] = 'Слов должно быть от ' . self::MIN_WORDS . ' до ' . self::MAX_WORDS . ', сейчас ' . count($items) . '.';
        }
        $words = [];
        $seen = [];
        foreach (array_slice($items, 0, self::MAX_WORDS) as $i => $item) {
            $tag = 'Слово ' . ($i + 1);
            if (!is_array($item) || !is_string($item['answer'] ?? null) || !is_string($item['clue'] ?? null)) {
                $errors[] = $tag . ': нужны строки answer и clue.';
                continue;
            }
            $word = self::word($item, $tag, $errors);
            if ($word === null) {
                continue;
            }
            if (isset($seen[$word['letters']])) {
                $errors[] = $tag . ': ответ «' . $word['answer'] . '» повторяется.';
                continue;
            }
            $seen[$word['letters']] = true;
            $words[] = $word;
        }
        if ($errors) {
            $more = count($errors) > 12 ? ' …и ещё ' . (count($errors) - 12) . '.' : '';
            return Validator::error(implode(' ', array_slice($errors, 0, 12)) . $more);
        }
        return ['title' => $title, 'intro' => $intro, 'author' => $author, 'words' => $words, 'warnings' => $warnings];
    }

    private static function word(array $item, string $tag, array &$errors): ?array
    {
        $answer = trim((string) preg_replace('/\s+/u', ' ', $item['answer']));
        if (!preg_match('/^[\p{L}][\p{L} \-]*[\p{L}]$/u', $answer)) {
            $errors[] = $tag . ' «' . self::clip($answer, 30) . '»: в ответе только буквы, пробел и дефис.';
            return null;
        }
        $letters = strtr(mb_strtoupper(str_replace([' ', '-'], '', $answer), 'UTF-8'), ['Ё' => 'Е']);
        if (!preg_match('/^[А-Я]+$/u', $letters) && !preg_match('/^[A-Z]+$/', $letters)) {
            $errors[] = $tag . ' «' . $answer . '»: ответ целиком кириллицей или целиком латиницей.';
            return null;
        }
        $length = mb_strlen($letters);
        if ($length < self::MIN_LETTERS || $length > self::MAX_LETTERS) {
            $errors[] = $tag . ' «' . $answer . '»: от ' . self::MIN_LETTERS . ' до ' . self::MAX_LETTERS . ' букв, сейчас ' . $length . '.';
            return null;
        }
        $clue = self::text($item['clue']);
        if (mb_strlen($clue) < 5 || mb_strlen($clue) > 200) {
            $errors[] = $tag . ' «' . $answer . '»: вопрос от 5 до 200 знаков.';
            return null;
        }
        if (self::clueGivesAway($clue, $answer)) {
            $errors[] = $tag . ' «' . $answer . '»: в вопросе есть сам ответ.';
            return null;
        }
        $source = '';
        if (isset($item['source']) && $item['source'] !== '' && $item['source'] !== null) {
            $source = is_string($item['source']) ? esc_url_raw(trim($item['source']), ['http', 'https']) : '';
            if ($source === '' || strlen($source) > 500) {
                $errors[] = $tag . ' «' . $answer . '»: source — ссылка http(s) до 500 знаков.';
                return null;
            }
        }
        $note = isset($item['note']) && is_string($item['note']) ? self::clip(self::text($item['note']), 300) : '';
        return [
            'answer' => $answer,
            'letters' => $letters,
            'enum' => self::enumeration($answer),
            'clue' => $clue,
            'source' => $source,
            'note' => $note,
        ];
    }

    /** «Weather Report» → «7, 6»; «Ар-деко» → «2-4»; one word → its length. */
    public static function enumeration(string $answer): string
    {
        $parts = preg_split('/([ \-])/u', $answer, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$answer];
        $out = '';
        foreach ($parts as $part) {
            if ($part === ' ') {
                $out .= ', ';
            } elseif ($part === '-') {
                $out .= '-';
            } elseif ($part !== '') {
                $out .= mb_strlen($part);
            }
        }
        return $out;
    }

    /** The clue must not contain the answer or any of its words (in any grammatical form). */
    public static function clueGivesAway(string $clue, string $answer): bool
    {
        $text = Search::normalize($clue);
        foreach (preg_split('/[ \-]+/u', $answer) ?: [] as $part) {
            $folded = trim(Search::normalize($part));
            if (mb_strlen($folded) < 3) {
                continue;
            }
            $stem = Stemmer::stem($folded);
            $probe = mb_strlen($stem) >= 4 ? $stem : $folded;
            if (preg_match('/ ' . preg_quote($probe, '/') . '/u', $text)) {
                return true;
            }
        }
        return false;
    }

    private static function text(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', sanitize_text_field($value)));
    }

    private static function clip(string $text, int $max): string
    {
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1) . '…' : $text;
    }

    // ---------------------------------------------------------------- assembly

    /**
     * Parsed words + a grid. @return array|\WP_Error the stored crossword (see docs/CROSSWORD.md)
     */
    public static function assemble(array $parsed, int $seed, string $id = '')
    {
        $layout = CrosswordBuilder::build(array_column($parsed['words'], 'letters'), $seed);
        if (count($layout['placements']) < self::MIN_PLACED) {
            return Validator::error('Сетка не собралась: поместилось ' . count($layout['placements']) . ' слов из ' . count($parsed['words']) . '. Добавьте слова с общими буквами или попробуйте другую сетку.');
        }
        return [
            'v' => 1,
            'id' => $id !== '' ? $id : 'cw' . substr(md5(uniqid('', true) . wp_rand()), 0, 10),
            'title' => $parsed['title'],
            'intro' => $parsed['intro'],
            'author' => $parsed['author'],
            'words' => $parsed['words'],
            'seed' => $seed,
            'rows' => $layout['rows'],
            'cols' => $layout['cols'],
            'placements' => $layout['placements'],
            'unplaced' => $layout['unplaced'],
            'key' => 1 + ($seed % (self::alphabetSize() - 1)),
            'warnings' => array_values((array) ($parsed['warnings'] ?? [])),
            'built_at' => gmdate('Y-m-d H:i:s'),
        ];
    }

    /** A stored value that passed through the database: checked again, never trusted blindly. */
    public static function clean($value): ?array
    {
        if (!is_array($value) || (int) ($value['v'] ?? 0) !== 1 || !is_array($value['words'] ?? null) || !is_array($value['placements'] ?? null)) {
            return null;
        }
        $rows = (int) ($value['rows'] ?? 0);
        $cols = (int) ($value['cols'] ?? 0);
        if ($rows < 1 || $cols < 1 || $rows > CrosswordBuilder::MAX_SIZE || $cols > CrosswordBuilder::MAX_SIZE) {
            return null;
        }
        $grid = [];
        foreach ($value['placements'] as $p) {
            $word = $value['words'][(int) ($p['w'] ?? -1)] ?? null;
            if (!is_array($word) || !in_array($p['d'] ?? '', ['a', 'd'], true)) {
                return null;
            }
            $letters = preg_split('//u', (string) $word['letters'], -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($letters as $i => $letter) {
                $r = (int) $p['r'] + ($p['d'] === 'd' ? $i : 0);
                $c = (int) $p['c'] + ($p['d'] === 'a' ? $i : 0);
                if ($r < 0 || $c < 0 || $r >= $rows || $c >= $cols || (isset($grid[$r][$c]) && $grid[$r][$c] !== $letter)) {
                    return null;
                }
                $grid[$r][$c] = $letter;
            }
        }
        return $value;
    }

    /** What the public layer keeps: editor notes and build warnings stay in the newsroom. */
    public static function forPublic(array $crossword): array
    {
        foreach ($crossword['words'] as &$word) {
            unset($word['note']);
        }
        unset($word);
        unset($crossword['warnings']);
        return $crossword;
    }

    /** @return array<int,array<int,string>> row => col => letter */
    public static function grid(array $crossword): array
    {
        $grid = [];
        foreach ($crossword['placements'] as $p) {
            $letters = preg_split('//u', (string) $crossword['words'][$p['w']]['letters'], -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($letters as $i => $letter) {
                $grid[$p['r'] + ($p['d'] === 'd' ? $i : 0)][$p['c'] + ($p['d'] === 'a' ? $i : 0)] = $letter;
            }
        }
        return $grid;
    }

    /** Clues in reading order, split by direction. @return array{a:array,d:array} */
    public static function clues(array $crossword): array
    {
        $out = ['a' => [], 'd' => []];
        foreach ($crossword['placements'] as $p) {
            $word = $crossword['words'][$p['w']];
            $out[$p['d']][] = [
                'n' => (int) $p['n'],
                'd' => $p['d'],
                'r' => (int) $p['r'],
                'c' => (int) $p['c'],
                'len' => mb_strlen($word['letters']),
                'enum' => (string) $word['enum'],
                'clue' => Text::typograph((string) $word['clue']),
                'answer' => (string) $word['answer'],
                'source' => (string) ($word['source'] ?? ''),
            ];
        }
        foreach ($out as &$list) {
            usort($list, static function ($a, $b) {
                return $a['n'] <=> $b['n'];
            });
        }
        unset($list);
        return $out;
    }

    public static function alphabetSize(): int
    {
        return mb_strlen(self::ALPHABET);
    }

    /**
     * One character per cell, row by row: '.' for a black cell, otherwise the letter shifted
     * along ALPHABET by the crossword's key and the cell index. Decoded by crossword.js.
     */
    public static function encode(array $crossword): string
    {
        $grid = self::grid($crossword);
        $size = self::alphabetSize();
        $key = (int) $crossword['key'];
        $out = '';
        for ($r = 0; $r < $crossword['rows']; $r++) {
            for ($c = 0; $c < $crossword['cols']; $c++) {
                if (!isset($grid[$r][$c])) {
                    $out .= '.';
                    continue;
                }
                $index = mb_strpos(self::ALPHABET, $grid[$r][$c]);
                $k = $r * $crossword['cols'] + $c;
                $out .= mb_substr(self::ALPHABET, ($index + $key + $k * 7) % $size, 1);
            }
        }
        return $out;
    }

    /** Answers open a week after the issue date or as soon as a newer crossword is out. */
    public static function answersOpen(string $editionDate, ?string $newerDate, ?int $now = null): bool
    {
        if ($newerDate !== null && $newerDate > $editionDate) {
            return true;
        }
        $now = $now ?? time();
        $open = strtotime($editionDate . ' 00:00:00 Europe/Moscow +' . self::ANSWERS_AFTER_DAYS . ' days');
        return $open !== false && $now >= $open;
    }
}
