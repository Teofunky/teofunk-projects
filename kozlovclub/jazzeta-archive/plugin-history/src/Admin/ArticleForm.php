<?php

namespace Kozlov\Gazeta\Admin;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Converts the article editor form into a JSON 1.1 article. Only known keys are copied,
 * so a tampered form cannot add fields; the repository then runs the full validator.
 */
final class ArticleForm
{
    private const SOURCE_TYPES = ['official', 'independent_editorial', 'press_release', 'distributor', 'event_organizer', 'research_preprint', 'other'];

    /** @throws \InvalidArgumentException */
    public static function fromPost(array $in, string $body, string $notes, string $previous, string $newFact): array
    {
        foreach (['id', 'headline', 'lead', 'summary', 'region', 'category', 'placement', 'story_group_id', 'event_id'] as $key) {
            if (!isset($in[$key]) || !is_string($in[$key])) {
                throw new \InvalidArgumentException('нет поля ' . $key . '.');
            }
        }
        $article = [
            'id' => trim($in['id']),
            'region' => trim($in['region']),
            'category' => trim($in['category']),
            'placement' => trim($in['placement']),
            'headline' => trim($in['headline']),
            'lead' => trim($in['lead']),
            'body_paragraphs' => self::paragraphs($body),
            'summary' => trim($in['summary']),
            'event_date' => self::nullable($in['event_date'] ?? ''),
            'story_group_id' => trim($in['story_group_id']),
            'story_score' => isset($in['story_score']) && is_numeric($in['story_score']) ? 0 + $in['story_score'] : 0,
            'event_id' => trim($in['event_id']),
            'previous_article_ids' => array_values(array_unique(array_filter(array_map('trim', preg_split('/[\s,]+/', $previous) ?: [])))),
            'development_note' => $newFact,
            'sources' => [],
            'claims' => [],
            'quotes' => [],
            'conflict_disclosure' => self::nullable($in['conflict_disclosure'] ?? ''),
            'editorial_notes' => array_values(array_filter(array_map('trim', preg_split('/\R/u', $notes) ?: []), 'strlen')),
            'media_rights' => [
                'image_url' => self::nullable($in['media_rights']['image_url'] ?? ''),
                'status' => (string) ($in['media_rights']['status'] ?? 'none'),
                'credit' => trim((string) ($in['media_rights']['credit'] ?? '')),
            ],
        ];
        foreach (self::rows($in['sources'] ?? []) as $s) {
            $source = [
                'id' => trim((string) ($s['id'] ?? '')),
                'title' => trim((string) ($s['title'] ?? '')),
                'publisher' => trim((string) ($s['publisher'] ?? '')),
                'url' => trim((string) ($s['url'] ?? '')),
                'published_at' => self::date($s['published_at'] ?? ''),
                'type' => in_array($s['type'] ?? '', self::SOURCE_TYPES, true) ? $s['type'] : 'other',
                'verification_checked_at' => self::date($s['verification_checked_at'] ?? ''),
                'dependency_group' => trim((string) ($s['dependency_group'] ?? '')),
            ];
            foreach (['publication_id', 'content_hash'] as $optional) {
                $value = self::nullable($s[$optional] ?? '');
                if ($value !== null) {
                    $source[$optional] = $value;
                }
            }
            $article['sources'][] = $source;
        }
        foreach (self::rows($in['claims'] ?? []) as $c) {
            $ids = $c['source_ids'] ?? [];
            $ids = is_array($ids) ? $ids : (preg_split('/[\s,]+/', (string) $ids) ?: []);
            $article['claims'][] = [
                'id' => trim((string) ($c['id'] ?? '')),
                'statement' => trim((string) ($c['statement'] ?? '')),
                'verification' => (string) ($c['verification'] ?? 'unverified'),
                'source_ids' => array_values(array_unique(array_filter(array_map('trim', array_map('strval', $ids))))),
                'editorial_note' => trim((string) ($c['editorial_note'] ?? '')),
            ];
        }
        foreach (self::rows($in['quotes'] ?? []) as $q) {
            $article['quotes'][] = [
                'original' => trim((string) ($q['original'] ?? '')),
                'translation_ru' => trim((string) ($q['translation_ru'] ?? '')),
                'source_id' => trim((string) ($q['source_id'] ?? '')),
                'verified' => isset($q['verified']) && $q['verified'] === '1',
            ];
        }
        return $article;
    }

    private static function rows($value): array
    {
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_filter($value, static function ($row) {
            return is_array($row) && !isset($row['__template']);
        }));
    }

    private static function nullable($value): ?string
    {
        $value = is_string($value) ? trim($value) : '';
        return $value === '' ? null : $value;
    }

    /** Accepts YYYY-MM-DD, full ISO 8601 or a datetime-local value (interpreted as Moscow time). */
    private static function date($value): ?string
    {
        $value = self::nullable($value);
        if ($value === null) {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?$/', $value)) {
            try {
                return (new \DateTimeImmutable($value, new \DateTimeZone('Europe/Moscow')))->format('c');
            } catch (\Exception $e) {
                return $value;
            }
        }
        return $value;
    }

    /** Editor HTML → paragraph strings: plain lines are wrapped first, then split at block ends. */
    public static function paragraphs(string $html): array
    {
        $html = trim(str_replace("\r\n", "\n", $html));
        if ($html === '') {
            return [];
        }
        $html = wpautop($html);
        $parts = preg_split('~(?<=</p>|</ul>|</ol>|</blockquote>)~i', $html) ?: [$html];
        $out = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '' && trim(wp_strip_all_tags($part)) !== '') {
                $out[] = $part;
            }
        }
        return $out;
    }
}
