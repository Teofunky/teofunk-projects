<?php

namespace Kozlov\Gazeta\Domain;

if (!defined('ABSPATH')) {
    exit;
}

/** Human-readable Russian labels for contract enums. Values are the JSON 1.1 contract. */
final class Labels
{
    public const REGIONS = [
        'russia' => 'Россия',
        'world' => 'Мир',
        'mixed' => 'Россия и мир',
    ];

    public const CATEGORIES = [
        'jazz' => 'Джаз',
        'blues' => 'Блюз',
        'funk_soul' => 'Фанк и соул',
        'guitar_rock' => 'Гитара и рок',
        'festivals' => 'Фестивали',
        'instruments' => 'Инструменты',
        'research' => 'Ноты будущего',
        'other' => 'Люди и сцена',
    ];

    public const PLACEMENTS = [
        'lead' => 'Первая полоса',
        'feature' => 'Крупный материал',
        'regular' => 'Заметка',
        'brief' => 'Коротко',
    ];

    /** Reader-facing names for evidence levels. */
    public const LEVELS = [
        'verified_primary' => 'Подтверждено первоисточником',
        'verified_independent' => 'Подтверждено независимыми источниками',
        'attributed_single' => 'По сообщению одного источника',
        'unverified' => 'Не проверено',
    ];

    /** Short editor-facing names for the same levels. */
    public const LEVELS_SHORT = [
        'verified_primary' => 'Первоисточник',
        'verified_independent' => 'Две независимые цепочки',
        'attributed_single' => 'Один источник',
        'unverified' => 'Не проверено',
    ];

    public const SOURCE_TYPES = [
        'official' => 'Официальный источник',
        'independent_editorial' => 'Независимое издание',
        'press_release' => 'Пресс-релиз',
        'distributor' => 'Дистрибьютор',
        'event_organizer' => 'Организатор',
        'research_preprint' => 'Препринт',
        'other' => 'Другой источник',
    ];

    public const MEDIA_RIGHTS = [
        'none' => 'Нет изображения',
        'owned' => 'Собственная работа',
        'licensed' => 'Лицензия',
        'verified_permission' => 'Подтверждённое разрешение',
    ];

    public const ISSUE_STATES = [
        'draft' => 'Черновик',
        'published' => 'Опубликован',
        'withdrawn' => 'Снят с публикации',
    ];

    public const HISTORY_ACTIONS = [
        'import' => 'Автоматический импорт',
        'create' => 'Выпуск создан',
        'edit_article' => 'Правка материала',
        'review_article' => 'Материал проверен',
        'remove_article' => 'Материал убран',
        'edit_issue' => 'Правка сборки номера',
        'restore_draft' => 'Восстановлена прежняя версия',
        'publish' => 'Опубликовано',
        'withdraw' => 'Снято с публикации',
        'before_manual_import' => 'Резервная копия перед ручным импортом',
        'manual_import' => 'Ручной импорт JSON',
    ];

    public const MONTHS_GENITIVE = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
    public const MONTHS_NOMINATIVE = ['Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];
    public const WEEKDAYS_SHORT = ['Вс', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб'];
    public const WEEKDAYS = ['воскресенье', 'понедельник', 'вторник', 'среда', 'четверг', 'пятница', 'суббота'];

    public static function region(string $key): string
    {
        return self::REGIONS[$key] ?? $key;
    }

    public static function category(string $key): string
    {
        return self::CATEGORIES[$key] ?? $key;
    }

    /** "21 октября 2026" from YYYY-MM-DD (or any date-time string). */
    public static function date(?string $value, bool $withYear = true): string
    {
        if (!$value) {
            return '';
        }
        try {
            $date = new \DateTimeImmutable(substr($value, 0, 10), new \DateTimeZone('Europe/Moscow'));
        } catch (\Exception $e) {
            return $value;
        }
        $text = $date->format('j') . ' ' . self::MONTHS_GENITIVE[(int) $date->format('n') - 1];
        return $withYear ? $text . ' ' . $date->format('Y') : $text;
    }

    /** "Среда, 21 октября 2026". */
    public static function longDate(string $value): string
    {
        try {
            $date = new \DateTimeImmutable(substr($value, 0, 10), new \DateTimeZone('Europe/Moscow'));
        } catch (\Exception $e) {
            return $value;
        }
        $weekday = self::WEEKDAYS[(int) $date->format('w')];
        return self::ucfirst($weekday) . ', ' . self::date($value);
    }

    public static function monthYear(string $value): string
    {
        $date = new \DateTimeImmutable(substr($value, 0, 10));
        return self::MONTHS_NOMINATIVE[(int) $date->format('n') - 1] . ' ' . $date->format('Y');
    }

    public static function issueNumber($number): string
    {
        // Approved masthead style: "№ 001".
        return $number ? '№ ' . str_pad((string) (int) $number, 3, '0', STR_PAD_LEFT) : 'Черновик';
    }

    /** Russian plural: plural(5, 'материал', 'материала', 'материалов'). */
    public static function plural(int $n, string $one, string $few, string $many): string
    {
        $mod10 = $n % 10;
        $mod100 = $n % 100;
        if ($mod10 === 1 && $mod100 !== 11) {
            return $one;
        }
        if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) {
            return $few;
        }
        return $many;
    }

    public static function count(int $n, string $one, string $few, string $many): string
    {
        return $n . ' ' . self::plural($n, $one, $few, $many);
    }

    public static function ucfirst(string $text): string
    {
        if ($text === '') {
            return $text;
        }
        if (function_exists('mb_substr')) {
            return mb_strtoupper(mb_substr($text, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($text, 1, null, 'UTF-8');
        }
        return ucfirst($text);
    }
}
