<?php

namespace Kozlov\Gazeta\Domain;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * JSON 1.1 contract: strict schema check, then normalisation and referential checks.
 * Behaviour (accepted documents, limits and messages) mirrors 0.3.5.
 */
final class Validator
{
    public const MAX_BYTES = 262144;
    public const MAX_DEPTH = 32;

    /** Allowed inline HTML inside body paragraphs. */
    public const ALLOWED_HTML = [
        'p' => [], 'br' => [], 'strong' => [], 'em' => [], 'b' => [], 'i' => [],
        'blockquote' => [], 'ul' => [], 'ol' => [], 'li' => [],
        'a' => ['href' => true, 'title' => true],
    ];

    /** @var array|null */
    private static $schema = null;

    public static function error(string $message, int $status = 400): \WP_Error
    {
        return new \WP_Error('es_validation', $message, ['status' => $status]);
    }

    /** @return array|\WP_Error Normalised document. */
    public static function parse($raw)
    {
        if (!is_string($raw) || strlen($raw) > self::MAX_BYTES) {
            return self::error('Максимальный размер JSON — 256 КиБ.', 413);
        }
        $object = json_decode($raw, false, self::MAX_DEPTH);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return self::error('Некорректный JSON: ' . json_last_error_msg());
        }
        $schema = self::schema();
        try {
            self::walk($object, $schema, $schema, '$');
        } catch (\InvalidArgumentException $e) {
            return self::error($e->getMessage());
        }
        $document = json_decode($raw, true, self::MAX_DEPTH);
        try {
            return self::normalize($document);
        } catch (\InvalidArgumentException $e) {
            return self::error($e->getMessage());
        }
    }

    private static function schema(): array
    {
        if (self::$schema === null) {
            $schema = json_decode((string) file_get_contents(ES_DIR . 'schemas/issue.schema.json'), true);
            if (!is_array($schema)) {
                throw new \RuntimeException('Схема выпуска не читается.', 500);
            }
            self::$schema = $schema;
        }
        return self::$schema;
    }

    /** Minimal JSON Schema walker for the subset used by issue.schema.json. */
    private static function walk($value, array $rule, array $root, string $path): void
    {
        if (isset($rule['$ref'])) {
            $rule = $root['$defs'][basename($rule['$ref'])];
        }
        if (isset($rule['type'])) {
            $matches = false;
            foreach ((array) $rule['type'] as $type) {
                $matches = $matches
                    || ($type === 'object' && is_object($value))
                    || ($type === 'array' && is_array($value))
                    || ($type === 'string' && is_string($value))
                    || ($type === 'number' && (is_int($value) || is_float($value)))
                    || ($type === 'boolean' && is_bool($value))
                    || ($type === 'null' && $value === null);
            }
            if (!$matches) {
                throw new \InvalidArgumentException($path . ': неверный тип.');
            }
        }
        if (array_key_exists('const', $rule) && $value !== $rule['const']) {
            throw new \InvalidArgumentException($path . ': недопустимое значение.');
        }
        if (isset($rule['enum']) && !in_array($value, $rule['enum'], true)) {
            throw new \InvalidArgumentException($path . ': значение вне перечня.');
        }
        if (is_object($value)) {
            foreach ($rule['required'] ?? [] as $key) {
                if (!property_exists($value, $key)) {
                    throw new \InvalidArgumentException($path . '.' . $key . ': обязательное поле.');
                }
            }
            foreach (get_object_vars($value) as $key => $child) {
                if (!isset($rule['properties'][$key])) {
                    throw new \InvalidArgumentException($path . '.' . $key . ': неизвестное поле.');
                }
                self::walk($child, $rule['properties'][$key], $root, $path . '.' . $key);
            }
        } elseif (is_array($value)) {
            $count = count($value);
            if ($count < ($rule['minItems'] ?? 0) || $count > min(80, $rule['maxItems'] ?? 80)) {
                throw new \InvalidArgumentException($path . ': недопустимое число элементов.');
            }
            if (!empty($rule['uniqueItems']) && count(array_unique(array_map('serialize', $value))) !== $count) {
                throw new \InvalidArgumentException($path . ': повтор элементов.');
            }
            foreach ($value as $index => $child) {
                self::walk($child, $rule['items'], $root, $path . '[' . $index . ']');
            }
        } elseif (is_string($value)) {
            $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
            if ($length < ($rule['minLength'] ?? 0) || $length > 20000 || strpos($value, "\0") !== false) {
                throw new \InvalidArgumentException($path . ': недопустимая длина строки.');
            }
            $format = $rule['format'] ?? '';
            if ($format === 'date' && !self::isDate($value)) {
                throw new \InvalidArgumentException($path . ': неверная дата.');
            }
            if ($format === 'date-time' && !self::isTimestamp($value)) {
                throw new \InvalidArgumentException($path . ': требуется ISO 8601 с часовым поясом.');
            }
            if ($format === 'uri' && !self::isPublicUrl($value)) {
                throw new \InvalidArgumentException($path . ': требуется публичная HTTPS-ссылка без логина.');
            }
        } elseif (is_numeric($value) && !is_bool($value)) {
            if ($value < ($rule['minimum'] ?? -INF) || $value > ($rule['maximum'] ?? INF)) {
                throw new \InvalidArgumentException($path . ': число вне диапазона.');
            }
        }
    }

    public static function isDate($value): bool
    {
        if (!is_string($value)) {
            return false;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        return $date !== false && $date->format('Y-m-d') === $value;
    }

    public static function isTimestamp($value): bool
    {
        if (!is_string($value)
            || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $value)
            || !self::isDate(substr($value, 0, 10))) {
            return false;
        }
        try {
            new \DateTimeImmutable($value);
            $errors = \DateTimeImmutable::getLastErrors();
            return !$errors || (!$errors['warning_count'] && !$errors['error_count']);
        } catch (\Exception $e) {
            return false;
        }
    }

    /** Public HTTPS URL without credentials, non-default ports, IPs or local names. */
    public static function isPublicUrl($url): bool
    {
        if (!is_string($url) || strlen($url) > 2048 || !filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        $parts = wp_parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        return ($parts['scheme'] ?? '') === 'https'
            && !isset($parts['user']) && !isset($parts['pass'])
            && (!isset($parts['port']) || (int) $parts['port'] === 443)
            && preg_match('/^[a-z0-9][a-z0-9.-]*\.[a-z][a-z0-9-]{1,62}$/i', $host)
            && !filter_var($host, FILTER_VALIDATE_IP)
            && !preg_match('/(?:^|\.)(?:localhost|local|internal|test|invalid)$/i', $host);
    }

    public static function richHtml(string $html): string
    {
        return trim(wp_kses($html, self::ALLOWED_HTML, ['https', 'http']));
    }

    /** Paragraph array → safe HTML. Unwrapped text next to lists is wrapped by wpautop. */
    public static function renderBody(array $paragraphs): string
    {
        $out = '';
        foreach ($paragraphs as $paragraph) {
            $out .= wpautop(self::richHtml((string) $paragraph));
        }
        return $out;
    }

    private static function plain($value, int $max): string
    {
        $value = sanitize_textarea_field((string) $value);
        $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
        if ($length > $max) {
            throw new \InvalidArgumentException('Поле текста превышает лимит ' . $max . ' символов.');
        }
        return trim($value);
    }

    /** @throws \InvalidArgumentException */
    public static function normalize(array $doc): array
    {
        if (isset($doc['import_id']) && !preg_match('/^[a-zA-Z0-9_.:-]{1,128}$/D', $doc['import_id'])) {
            throw new \InvalidArgumentException('Некорректный import_id.');
        }
        $articleIds = [];
        $groups = [];
        foreach ($doc['articles'] as &$article) {
            $article = self::normalizeArticle($article, (string) $doc['schema_version'], $articleIds, $groups);
        }
        unset($article);

        $social = [];
        foreach ($doc['social'] as &$item) {
            if (!isset($articleIds[$item['article_id']]) || isset($social[$item['article_id']])) {
                throw new \InvalidArgumentException('Неизвестная или повторная статья в соцверсии.');
            }
            $social[$item['article_id']] = true;
            $item['telegram_sentence'] = self::plain($item['telegram_sentence'], 600);
            $item['instagram_sentence'] = self::plain($item['instagram_sentence'], 600);
        }
        unset($item);

        if (strtotime($doc['editorial']['source_scan_window_start']) > strtotime($doc['editorial']['source_scan_window_end'])) {
            throw new \InvalidArgumentException('Начало сбора позже конца.');
        }
        foreach (['blocking_issues', 'generation_notes'] as $key) {
            $doc['editorial'][$key] = array_map(static function ($note) {
                return self::plain($note, 4000);
            }, $doc['editorial'][$key]);
        }
        return $doc;
    }

    /**
     * The order of checks follows 0.3.5 exactly, so the same broken document gets the same message.
     * @param array<string,bool> $articleIds
     * @param array<string,bool> $groups
     */
    private static function normalizeArticle(array $a, string $schemaVersion, array &$articleIds, array &$groups): array
    {
        if ($schemaVersion === '1.1' && !isset($a['event_id'])) {
            throw new \InvalidArgumentException('Для схемы 1.1 требуется event_id.');
        }
        $a['event_id'] = $a['event_id'] ?? $a['story_group_id'];
        $a['previous_article_ids'] = $a['previous_article_ids'] ?? [];
        $a['development_note'] = self::plain($a['development_note'] ?? '', 4000);
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,119}$/D', $a['event_id'])) {
            throw new \InvalidArgumentException('Некорректный event_id.');
        }
        foreach ($a['previous_article_ids'] as $slug) {
            if (!preg_match('/^[a-z0-9_-]{1,160}$/D', $slug)) {
                throw new \InvalidArgumentException('Некорректная ссылка на предыдущую статью.');
            }
        }
        foreach (['id' => 64, 'story_group_id' => 120] as $key => $max) {
            if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,' . ($max - 1) . '}$/D', $a[$key])) {
                throw new \InvalidArgumentException($key . ': используйте латинские строчные буквы, цифры, _ и -.');
            }
        }
        if (isset($articleIds[$a['id']]) || isset($groups[$a['story_group_id']])) {
            throw new \InvalidArgumentException('Повтор ID статьи или сюжета внутри номера.');
        }
        $articleIds[$a['id']] = true;
        $groups[$a['story_group_id']] = true;
        foreach (['headline' => 230, 'lead' => 1500, 'summary' => 1500] as $key => $max) {
            $a[$key] = self::plain($a[$key], $max);
            // Same truthiness test as 0.3.5: a text of "0" counts as empty.
            if (!$a[$key]) {
                throw new \InvalidArgumentException('Пустой текст: ' . $key);
            }
        }
        if ($a['event_date'] !== null && !self::isDate($a['event_date'])) {
            throw new \InvalidArgumentException('event_date: требуется дата YYYY-MM-DD или null.');
        }
        if (count($a['sources']) > 12 || count($a['claims']) > 40 || count($a['body_paragraphs']) > 40 || count($a['quotes']) > 10) {
            throw new \InvalidArgumentException('Превышен лимит источников, фактов, абзацев или цитат.');
        }
        foreach ($a['body_paragraphs'] as &$paragraph) {
            $paragraph = self::richHtml($paragraph);
            if (trim(wp_strip_all_tags($paragraph)) === '') {
                throw new \InvalidArgumentException('Пустой абзац.');
            }
        }
        unset($paragraph);

        $sourceIds = [];
        $urls = [];
        $externals = [];
        $hashes = [];
        foreach ($a['sources'] as &$source) {
            if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $source['id']) || isset($sourceIds[$source['id']])) {
                throw new \InvalidArgumentException('Неверный или повторный ID источника.');
            }
            $sourceIds[$source['id']] = true;
            $canonical = Duplicates::canonicalUrl($source['url']);
            if (isset($urls[$canonical])) {
                throw new \InvalidArgumentException('Одна публикация источника повторяется внутри статьи.');
            }
            $urls[$canonical] = true;
            if (isset($source['publication_id']) && !is_string($source['publication_id'])) {
                throw new \InvalidArgumentException('publication_id должен быть строкой.');
            }
            if (isset($source['publication_id']) && strlen($source['publication_id']) > 200) {
                throw new \InvalidArgumentException('Слишком длинный publication_id.');
            }
            if (isset($source['content_hash']) && !preg_match('/^[a-f0-9]{64}$/D', $source['content_hash'])) {
                throw new \InvalidArgumentException('content_hash должен быть SHA-256.');
            }
            $external = Duplicates::externalKey($source);
            $hash = $source['content_hash'] ?? '';
            if (($external !== '' && isset($externals[$external])) || ($hash !== '' && isset($hashes[$hash]))) {
                throw new \InvalidArgumentException('Одинаковый ID или текст публикации источника внутри статьи.');
            }
            if ($external !== '') {
                $externals[$external] = true;
            }
            if ($hash !== '') {
                $hashes[$hash] = true;
            }
            foreach (['title', 'publisher', 'dependency_group'] as $key) {
                $source[$key] = self::plain($source[$key], 500);
            }
            foreach (['published_at', 'verification_checked_at'] as $key) {
                $value = $source[$key];
                if ($value !== null && !self::isTimestamp($value) && !($key === 'published_at' && self::isDate($value))) {
                    throw new \InvalidArgumentException('Неверная дата источника ' . $source['id']);
                }
            }
        }
        unset($source);

        $claimIds = [];
        foreach ($a['claims'] as &$claim) {
            if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $claim['id']) || isset($claimIds[$claim['id']])) {
                throw new \InvalidArgumentException('Неверный или повторный ID факта.');
            }
            $claimIds[$claim['id']] = true;
            if (array_diff($claim['source_ids'], array_keys($sourceIds))) {
                throw new \InvalidArgumentException('Факт ссылается на отсутствующий источник.');
            }
            $claim['statement'] = self::plain($claim['statement'], 3000);
            $claim['editorial_note'] = self::plain($claim['editorial_note'], 4000);
            if ($claim['statement'] === '') {
                throw new \InvalidArgumentException('Пустой факт.');
            }
        }
        unset($claim);

        foreach ($a['quotes'] as &$quote) {
            if (!isset($sourceIds[$quote['source_id']])) {
                throw new \InvalidArgumentException('Цитата без известного источника.');
            }
            $quote['original'] = self::plain($quote['original'], 4000);
            $quote['translation_ru'] = self::plain($quote['translation_ru'], 4000);
        }
        unset($quote);

        $a['conflict_disclosure'] = $a['conflict_disclosure'] === null ? null : self::plain($a['conflict_disclosure'], 2000);
        $a['media_rights']['credit'] = self::plain($a['media_rights']['credit'], 500);
        if ($a['media_rights']['image_url'] !== null && !self::isPublicUrl($a['media_rights']['image_url'])) {
            throw new \InvalidArgumentException('Изображение: требуется HTTPS-ссылка или null.');
        }
        $a['editorial_notes'] = array_map(static function ($note) {
            return self::plain($note, 4000);
        }, $a['editorial_notes']);
        return $a;
    }

    /** Problems that block review of one article. Empty array = structurally publishable. */
    public static function articleBlockers(array $a): array
    {
        $errors = [];
        foreach ($a['sources'] as $source) {
            if (in_array(wp_parse_url($source['url'], PHP_URL_HOST), ['example.com', 'example.org', 'example.net'], true)) {
                $errors[] = 'Замените тестовые ссылки example.com/org/net настоящими первоисточниками.';
            }
        }
        $sources = array_column($a['sources'], null, 'id');
        foreach ($a['claims'] as $claim) {
            $tag = $a['headline'] . ' / ' . $claim['id'] . ': ';
            if ($claim['verification'] === 'unverified' || !$claim['source_ids']) {
                $errors[] = $tag . 'факт не подтверждён.';
                continue;
            }
            $selected = array_intersect_key($sources, array_flip($claim['source_ids']));
            foreach ($selected as $source) {
                if (!$source['verification_checked_at'] || strtotime($source['verification_checked_at']) > time() + 300) {
                    $errors[] = $tag . 'нет корректной даты проверки источника.';
                }
                if ($source['published_at'] && strtotime($source['published_at']) > time() + 300) {
                    $errors[] = $tag . 'дата публикации источника в будущем.';
                }
            }
            if ($claim['verification'] === 'verified_primary'
                && !array_intersect(['official', 'event_organizer', 'distributor'], array_column($selected, 'type'))) {
                $errors[] = $tag . 'не указан компетентный первоисточник.';
            }
            if ($claim['verification'] === 'verified_independent') {
                $independent = array_filter($selected, static function ($s) {
                    return $s['type'] === 'independent_editorial' && $s['dependency_group'] !== '';
                });
                if (count(array_unique(array_column($independent, 'dependency_group'))) < 2) {
                    $errors[] = $tag . 'нужны две независимые редакционные цепочки.';
                }
            }
            if ($claim['verification'] === 'attributed_single' && trim($claim['editorial_note']) === '') {
                $errors[] = $tag . 'укажите основание и формулу атрибуции в заметке к факту.';
            }
        }
        foreach ($a['quotes'] as $quote) {
            if (trim($quote['original']) === '' || trim($quote['translation_ru']) === '') {
                $errors[] = 'Цитата: заполните оригинал и проверенный перевод.';
            }
        }
        $media = $a['media_rights'];
        if ($media['image_url'] && ($media['status'] === 'none' || !$media['credit'] || !self::isLocalImage($media['image_url']))) {
            $errors[] = 'Фото: нужны права, подпись и изображение из медиатеки этого сайта.';
        }
        return array_values(array_unique($errors));
    }

    public static function isLocalImage(?string $url): bool
    {
        if (!$url || wp_parse_url($url, PHP_URL_HOST) !== wp_parse_url(home_url(), PHP_URL_HOST)) {
            return false;
        }
        $id = attachment_url_to_postid($url);
        return $id && wp_attachment_is_image($id)
            && in_array(get_post_mime_type($id), ['image/jpeg', 'image/png', 'image/webp', 'image/avif'], true);
    }

    /** What readers may see. Claims, notes and review data never leave the newsroom. */
    public static function publicArticle(array $a): array
    {
        $keep = ['id', 'region', 'category', 'placement', 'headline', 'lead', 'body_paragraphs', 'summary', 'event_date',
            'conflict_disclosure', 'media_rights', 'previous_article_ids', 'development_note'];
        return array_intersect_key($a, array_flip($keep)) + [
            'sources' => array_map(static function ($s) {
                return array_intersect_key($s, array_flip(['title', 'publisher', 'url', 'published_at', 'type']));
            }, $a['sources']),
            'verification' => array_values(array_unique(array_column($a['claims'], 'verification'))),
        ];
    }
}
