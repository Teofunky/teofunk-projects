<?php

namespace Kozlov\Gazeta\Data;

use Kozlov\Gazeta\Domain\Labels;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 0.5.11: «Этот день в джазе» — the calendar of jazz anniversaries under each issue.
 *
 * The dates are prepared outside WordPress (jazz_calendar.py: Wikidata, Russian Wikipedia,
 * «Джаз.Ру» obituaries, the club's own dates) and uploaded by the owner as one JSON file
 * (format es-calendar-1). It is kept whole in the option `es_calendar` (not autoloaded) and
 * read by date: the issue of 10 November shows the entries of 10 November, in the archive too.
 *
 * Nothing here writes the texts: every line is built from the stored year, kind and age, and
 * the upload is refused when an age does not match the year. The JSON exchange format of
 * issues (1.1), the tables and the publication flow do not change.
 */
final class Calendar
{
    public const OPTION = 'es_calendar';
    public const FORMAT = 'es-calendar-1';
    public const MAX_BYTES = 2097152;
    public const MAX_DAYS = 1200;
    public const MAX_ITEMS = 6;
    public const NAME_MAX = 80;
    public const OCCUPATION_MAX = 80;
    public const KINDS = ['birth', 'death'];

    /** @var array|null per-request copy of the option */
    private static $stored;

    /**
     * Checks an uploaded file and returns the value to store, or the list of problems.
     * Pure: no WordPress calls, so the export can be checked by the same rules outside the site.
     * @return array{ok:bool,errors:string[],value:?array}
     */
    public static function validate(string $raw): array
    {
        $errors = [];
        if (strlen($raw) > self::MAX_BYTES) {
            return self::refuse(['Файл больше 2 МБ.']);
        }
        $doc = json_decode($raw, true);
        if (!is_array($doc)) {
            return self::refuse(['Это не JSON.']);
        }
        if (($doc['format'] ?? '') !== self::FORMAT) {
            return self::refuse(['Нужен формат ' . self::FORMAT . ' (поле format).']);
        }
        $days = $doc['days'] ?? null;
        if (!is_array($days) || !$days) {
            return self::refuse(['Нет дней (поле days).']);
        }
        if (count($days) > self::MAX_DAYS) {
            return self::refuse(['Больше ' . self::MAX_DAYS . ' дней в одном файле.']);
        }
        $clean = [];
        foreach ($days as $date => $items) {
            $date = (string) $date;
            if (!self::isDate($date)) {
                $errors[] = 'Неверная дата: «' . self::short($date) . '».';
                continue;
            }
            if (!is_array($items) || count($items) > self::MAX_ITEMS) {
                $errors[] = $date . ': нужен список не длиннее ' . self::MAX_ITEMS . ' записей.';
                continue;
            }
            $year = (int) substr($date, 0, 4);
            $list = [];
            foreach (array_values($items) as $i => $item) {
                $problem = null;
                $entry = self::cleanItem($item, $year, $problem);
                if ($entry === null) {
                    $errors[] = $date . ', запись ' . ($i + 1) . ': ' . $problem;
                    continue;
                }
                $list[] = $entry;
            }
            if ($list) {
                $clean[$date] = $list;
            }
        }
        if ($errors) {
            return self::refuse($errors);
        }
        if (!$clean) {
            return self::refuse(['В файле нет ни одной записи.']);
        }
        ksort($clean);
        $dates = array_keys($clean);
        // The declared range drives the «calendar ends soon» warning, so it must hold every day it carries.
        $from = (string) ($doc['from'] ?? '');
        $to = (string) ($doc['to'] ?? '');
        $from = self::isDate($from) ? $from : $dates[0];
        $to = self::isDate($to) ? $to : end($dates);
        if ($from > $to) {
            return self::refuse(['Начало охвата (from) позже конца (to).']);
        }
        if ($dates[0] < $from || end($dates) > $to) {
            return self::refuse(['Даты ' . $dates[0] . ' — ' . end($dates) . ' выходят за охват from–to (' . $from . ' — ' . $to . ').']);
        }
        if ((strtotime($to) - strtotime($from)) / 86400 > self::MAX_DAYS) {
            return self::refuse(['Охват from–to длиннее ' . self::MAX_DAYS . ' дней.']);
        }
        return [
            'ok' => true,
            'errors' => [],
            'value' => [
                'format' => self::FORMAT,
                'generated_at' => self::short((string) ($doc['generated_at'] ?? ''), 40),
                'from' => $from,
                'to' => $to,
                'days' => $clean,
            ],
        ];
    }

    /**
     * One entry, or null with the reason in $problem.
     * The age must equal the issue year minus the year of the event: this is the check that keeps
     * a wrong export from printing «110 лет» next to a year that gives 109.
     */
    private static function cleanItem($item, int $issueYear, ?string &$problem): ?array
    {
        if (!is_array($item)) {
            $problem = 'не объект.';
            return null;
        }
        $kind = (string) ($item['kind'] ?? '');
        if (!in_array($kind, self::KINDS, true)) {
            $problem = 'kind должен быть birth или death.';
            return null;
        }
        $year = $item['year'] ?? null;
        $age = $item['age'] ?? null;
        if (!is_int($year) || !is_int($age) || $year < 1600 || $year >= $issueYear) {
            $problem = 'год и возраст должны быть целыми числами, год раньше года выпуска.';
            return null;
        }
        if ($issueYear - $year !== $age) {
            $problem = 'возраст ' . $age . ' не сходится с годом ' . $year . '.';
            return null;
        }
        $name = self::text($item['name'] ?? '');
        $occupation = self::text($item['occupation'] ?? '');
        if ($name === '' || self::length($name) > self::NAME_MAX) {
            $problem = 'имя пустое или длиннее ' . self::NAME_MAX . ' знаков.';
            return null;
        }
        if (self::length($occupation) > self::OCCUPATION_MAX) {
            $problem = 'подпись длиннее ' . self::OCCUPATION_MAX . ' знаков.';
            return null;
        }
        if (!preg_match('/[\p{Cyrillic}]/u', $name)) {
            $problem = 'имя «' . self::short($name) . '» не по-русски.';
            return null;
        }
        // Every date must be traceable: the editor opens the source when a reader disputes it.
        $source = (string) ($item['source'] ?? '');
        if (!preg_match('#^https://[^\s<>"]{4,300}$#', $source)) {
            $problem = 'нет источника (нужен адрес https://).';
            return null;
        }
        return [
            'kind' => $kind,
            'year' => $year,
            'age' => $age,
            'name' => $name,
            'occupation' => $occupation,
            'round' => self::isRound($age),
            'russian' => !empty($item['russian']),
            'club' => !empty($item['club']),
            'source' => $source,
        ];
    }

    /** Stored calendar, or null before the first upload. */
    public static function stored(): ?array
    {
        if (self::$stored === null) {
            $value = function_exists('get_option') ? get_option(self::OPTION, null) : null;
            self::$stored = is_array($value) && isset($value['days']) && is_array($value['days']) ? $value : [];
        }
        return self::$stored ?: null;
    }

    /** Entries of one date as stored (presenter input), or [] when the date has none. */
    public static function forDate(string $date): array
    {
        $stored = self::stored();
        $items = $stored['days'][$date] ?? [];
        return is_array($items) ? $items : [];
    }

    /**
     * Replaces the stored calendar in one write. The old value is never deleted first: if the
     * database refuses the write, the previous calendar stays and false is returned.
     */
    public static function save(array $value, int $userId): bool
    {
        $value['uploaded_at'] = gmdate('Y-m-d H:i:s');
        $value['uploaded_by'] = $userId;
        $exists = get_option(self::OPTION, null) !== null;
        $ok = $exists ? update_option(self::OPTION, $value, false) : add_option(self::OPTION, $value, '', false);
        if (!$ok) {
            // update_option() also answers false for an identical value: read back to tell the two apart.
            wp_cache_delete(self::OPTION, 'options');
            $ok = get_option(self::OPTION, null) == $value;
        }
        self::$stored = null;
        return $ok;
    }

    public static function remove(): void
    {
        delete_option(self::OPTION);
        self::$stored = null;
    }

    /** Round anniversary: every 10 years and 25, 75, 125… (the same rule as jazz_calendar.py). */
    public static function isRound(int $age): bool
    {
        return $age > 0 && ($age % 10 === 0 || $age % 25 === 0);
    }

    /** «110 лет со дня рождения», «Год со дня смерти». */
    public static function label(string $kind, int $age): string
    {
        $what = $kind === 'death' ? 'со дня смерти' : 'со дня рождения';
        if ($age === 1) {
            return 'Год ' . $what;
        }
        return $age . ' ' . Labels::plural($age, 'год', 'года', 'лет') . ' ' . $what;
    }

    /** One line for a messenger: «1916 · 110 лет со дня рождения: Билли Мэй, трубач и композитор». */
    public static function line(array $item): string
    {
        $tail = $item['occupation'] !== '' ? ', ' . $item['occupation'] : '';
        return $item['year'] . ' · ' . self::label($item['kind'], (int) $item['age']) . ': ' . $item['name'] . $tail;
    }

    public static function isDate(string $value): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return false;
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    private static function text($value): string
    {
        if (!is_string($value)) {
            return '';
        }
        $value = preg_replace('/\s+/u', ' ', trim($value));
        // Plain text only: a tag or an entity in a name is a broken export, not markup to keep.
        return preg_match('/[<>]|&[a-z#0-9]+;/i', (string) $value) ? '' : (string) $value;
    }

    private static function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    private static function short(string $value, int $max = 30): string
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $max, 'UTF-8') : substr($value, 0, $max);
    }

    private static function refuse(array $errors): array
    {
        return ['ok' => false, 'errors' => $errors, 'value' => null];
    }
}
