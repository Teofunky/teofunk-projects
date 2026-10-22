<?php

namespace Kozlov\Gazeta\Migration;

use Kozlov\Gazeta\Data\Db;
use Kozlov\Gazeta\Data\Issues;
use Kozlov\Gazeta\Domain\Validator;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Copies one 0.2.0 issue (CPT jz_issue, meta _jz_issue_json) into a new UNVERIFIED draft.
 * The old post and its meta are never modified. Same behaviour as 0.3.x.
 */
final class Legacy020
{
    /** @return int|\WP_Error */
    public static function copy(int $legacyId)
    {
        if (!current_user_can('manage_options') || !current_user_can('es_import')) {
            return Validator::error('Нет прав.', 403);
        }
        $post = get_post($legacyId);
        if (!$post || $post->post_type !== 'jz_issue') {
            return Validator::error('Старый выпуск не найден.', 404);
        }
        $raw = get_post_meta($legacyId, '_jz_issue_json', true);
        if (!is_string($raw) || strlen($raw) > Validator::MAX_BYTES) {
            return Validator::error('Некорректный размер старого выпуска.');
        }
        $old = json_decode($raw, true, 32);
        if (!is_array($old) || !Validator::isDate($old['issue']['date'] ?? null) || !is_array($old['articles'] ?? null)) {
            return Validator::error('Неверные данные 0.2.0.');
        }
        $doc = Issues::emptyDocument($old['issue']['date']);
        $doc['import_id'] = 'legacy-0.2.0-' . $legacyId;
        $doc['editorial']['source_scan_window_start'] = $old['issue']['date'] . 'T00:00:00+03:00';
        $doc['editorial']['source_scan_window_end'] = $old['issue']['date'] . 'T00:00:00+03:00';
        $doc['editorial']['blocking_issues'] = ['Перенесено из 0.2.0: повторно проверить все факты и источники.'];
        $doc['editorial']['generation_notes'] = ['Исходная запись #' . $legacyId . '; прежний номер ' . ($old['issue']['number'] ?? 'не указан') . '. Номер присвоится при первой публикации.'];
        foreach ($old['articles'] as $position => $item) {
            if (!is_array($item)) {
                return Validator::error('Повреждён старый материал.');
            }
            $article = Issues::blankArticle();
            $article['id'] = 'legacy-' . $legacyId . '-' . ($position + 1);
            $article['event_id'] = $article['id'];
            $article['story_group_id'] = $article['id'];
            $article['headline'] = (string) ($item['title'] ?? 'Материал из старого выпуска');
            $article['lead'] = (string) ($item['summary'] ?? '');
            $article['summary'] = (string) ($item['social'] ?? $article['lead']);
            $article['body_paragraphs'] = preg_split('/\R\s*\R/u', (string) ($item['body'] ?? '')) ?: [''];
            $article['region'] = ($item['region'] ?? '') === 'Россия' ? 'russia' : 'world';
            $article['placement'] = $position === 0 ? 'lead' : 'regular';
            $article['sources'] = [];
            foreach ($item['sources'] ?? [] as $index => $source) {
                $date = $source['published'] ?? null;
                $article['sources'][] = [
                    'id' => 's' . ($index + 1),
                    'title' => (string) ($source['name'] ?? 'Источник'),
                    'publisher' => (string) ($source['name'] ?? 'Источник'),
                    'url' => (string) ($source['url'] ?? ''),
                    'published_at' => Validator::isDate($date) ? $date : null,
                    'type' => 'other',
                    'verification_checked_at' => null,
                    'dependency_group' => 'legacy-source-' . ($index + 1),
                ];
            }
            $article['claims'] = [[
                'id' => 'f1',
                'statement' => 'Перенесённый материал требует разметки существенных фактов.',
                'verification' => 'unverified',
                'source_ids' => array_column($article['sources'], 'id'),
                'editorial_note' => 'Старый флаг verified не переносится как человеческое подтверждение.',
            ]];
            if (!empty($item['related'])) {
                $article['conflict_disclosure'] = 'Материал связан с издателем. Уточните характер связи перед публикацией.';
            }
            $doc['articles'][] = $article;
            if ($position < 5) {
                $doc['social'][] = ['article_id' => $article['id'], 'telegram_sentence' => $article['summary'], 'instagram_sentence' => $article['summary'], 'visual_format' => '1080x1350'];
            }
        }
        $result = Issues::import(Db::json($doc));
        return is_wp_error($result) ? $result : (int) $result['id'];
    }
}
