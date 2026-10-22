<?php

namespace Kozlov\Gazeta\Data;

use Kozlov\Gazeta\Runtime\Mode;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Read-only health check of the newspaper data (the "dry-run" before and after an upgrade).
 * Never writes. Each check returns ok | warn | error with human-readable details.
 */
final class Integrity
{
    private const BATCH = 200;

    /** @return array{checks:array,counts:array,ok:bool} */
    public static function run(): array
    {
        $wpdb = Db::wpdb();
        $checks = [];
        $counts = [];

        $missing = [];
        foreach (Db::TABLES as $name) {
            $table = Db::table($name);
            $engine = $wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table));
            if ($engine === null) {
                $missing[] = $table;
            } elseif ($engine !== 'InnoDB') {
                $missing[] = $table . ' (' . $engine . ')';
            }
        }
        $checks[] = self::check('Таблицы газеты на месте (InnoDB)', $missing ? 'error' : 'ok', $missing ? ['Нет или не InnoDB: ' . implode(', ', $missing)] : [count(Db::TABLES) . ' таблиц']);
        if ($missing) {
            return ['checks' => $checks, 'counts' => $counts, 'ok' => false];
        }

        $version = (string) get_option('es_db_version');
        $checks[] = self::check('Версия данных', $version === ES_VERSION ? 'ok' : 'warn', [
            'es_db_version = ' . ($version !== '' ? $version : 'не задана') . ($version === ES_VERSION ? '' : '. Нужна активация или «Завершить обновление» в разделе «Режим».'),
        ]);

        $issues = Db::table('issues');
        $articles = Db::table('articles');
        foreach ($wpdb->get_results("SELECT state, COUNT(*) AS n FROM {$issues} GROUP BY state", ARRAY_A) ?: [] as $row) {
            $counts['issues_' . $row['state']] = (int) $row['n'];
        }
        $counts['articles'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$articles}");
        $counts['articles_public'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$articles} WHERE public_json IS NOT NULL");
        $counts['revisions'] = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Db::table('revisions'));
        $counts['imports'] = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Db::table('imports'));

        $maxNumber = (int) $wpdb->get_var("SELECT MAX(number) FROM {$issues}");
        $counter = (int) $wpdb->get_var('SELECT value FROM ' . Db::table('state') . " WHERE name='number'");
        $duplicates = (int) $wpdb->get_var("SELECT COUNT(*) FROM (SELECT number FROM {$issues} WHERE number IS NOT NULL GROUP BY number HAVING COUNT(*)>1) d");
        $numberProblems = [];
        if ($duplicates) {
            $numberProblems[] = 'Повторяющихся номеров: ' . $duplicates;
        }
        if ($counter < $maxNumber) {
            $numberProblems[] = 'Счётчик номеров (' . $counter . ') меньше наибольшего номера (' . $maxNumber . ') — следующий номер мог бы повториться.';
        }
        $checks[] = self::check('Нумерация выпусков', $numberProblems ? 'error' : 'ok', $numberProblems ?: ['Наибольший номер ' . $maxNumber . ', счётчик ' . $counter]);

        $docErrors = [];
        $snapshotErrors = [];
        $offset = 0;
        do {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT id, edition_date, state, number, public_version, draft_json, public_json FROM {$issues} ORDER BY id LIMIT %d OFFSET %d", self::BATCH, $offset), ARRAY_A) ?: [];
            foreach ($rows as $row) {
                $label = $row['edition_date'] . ' (#' . $row['id'] . ')';
                if (!is_array(json_decode((string) $row['draft_json'], true))) {
                    $docErrors[] = $label . ': рабочий документ не читается';
                }
                $mustHavePublic = $row['state'] !== 'draft' || (int) $row['public_version'] > 0;
                if ($mustHavePublic) {
                    $public = json_decode((string) $row['public_json'], true);
                    if (!is_array($public)) {
                        $snapshotErrors[] = $label . ': нет читаемого опубликованного снимка';
                        continue;
                    }
                    if ((int) ($public['number'] ?? 0) !== (int) $row['number']) {
                        $snapshotErrors[] = $label . ': номер в снимке не совпадает с номером выпуска';
                    }
                    foreach ($public['articles'] ?? [] as $article) {
                        $slug = (string) ($article['slug'] ?? '');
                        if ($slug === '' || !$wpdb->get_var($wpdb->prepare("SELECT id FROM {$articles} WHERE slug=%s AND issue_id=%d AND public_json IS NOT NULL", $slug, $row['id']))) {
                            $snapshotErrors[] = $label . ': статья снимка без публичной строки — ' . ($slug ?: 'без адреса');
                        }
                    }
                }
            }
            $offset += self::BATCH;
        } while (count($rows) === self::BATCH);
        $checks[] = self::check('Рабочие документы выпусков читаются', $docErrors ? 'error' : 'ok', $docErrors ?: ['Без ошибок']);
        $checks[] = self::check('Опубликованные снимки целы', $snapshotErrors ? 'error' : 'ok', $snapshotErrors ?: ['Без ошибок']);

        $articleErrors = [];
        $slugWarnings = [];
        $offset = 0;
        do {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT a.id, a.issue_id, a.external_id, a.slug, a.draft_json, a.public_json, i.edition_date FROM {$articles} a LEFT JOIN {$issues} i ON i.id=a.issue_id ORDER BY a.id LIMIT %d OFFSET %d", self::BATCH, $offset), ARRAY_A) ?: [];
            foreach ($rows as $row) {
                if ($row['edition_date'] === null) {
                    $articleErrors[] = 'Статья #' . $row['id'] . ' ссылается на несуществующий выпуск #' . $row['issue_id'];
                    continue;
                }
                if (!is_array(json_decode((string) $row['draft_json'], true))) {
                    $articleErrors[] = $row['slug'] . ': черновик статьи не читается';
                }
                if ($row['public_json'] !== null && !is_array(json_decode((string) $row['public_json'], true))) {
                    $articleErrors[] = $row['slug'] . ': опубликованная версия не читается';
                }
                if (!preg_match('/^[a-z0-9_-]{1,160}$/D', (string) $row['slug'])) {
                    $articleErrors[] = 'Статья #' . $row['id'] . ': адрес не подходит для маршрута /gazeta/material/';
                } elseif ($row['slug'] !== $row['edition_date'] . '-' . $row['external_id']) {
                    $slugWarnings[] = $row['slug'] . ' (ожидалось ' . $row['edition_date'] . '-' . $row['external_id'] . '; адрес сохраняется как есть)';
                }
            }
            $offset += self::BATCH;
        } while (count($rows) === self::BATCH);
        $checks[] = self::check('Статьи и их постоянные адреса', $articleErrors ? 'error' : ($slugWarnings ? 'warn' : 'ok'), $articleErrors ?: ($slugWarnings ?: ['Без ошибок']));

        $owner = Mode::publisherId();
        $ownerOk = $owner > 0 && user_can($owner, 'es_edit') && user_can($owner, 'es_publish');
        $checks[] = self::check('Владелец газеты назначен', $ownerOk ? 'ok' : 'warn', [$ownerOk ? 'ID ' . $owner : 'Владелец не назначен или без прав — публикация недоступна. Раздел «Доступ».']);

        $rules = (array) get_option('rewrite_rules', []);
        $routes = isset($rules['^gazeta/?$']) || isset($rules['gazeta/?$']);
        $checks[] = self::check('Адреса /gazeta/ зарегистрированы', $routes || !get_option('permalink_structure') ? 'ok' : 'warn', [$routes ? 'Правила на месте' : 'Правила не найдены — пересохраните «Настройки → Постоянные ссылки» или переактивируйте плагин.']);

        $searchRule = isset($rules['^gazeta/search/?$']);
        $indexed = Search::size();
        $searchable = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$articles} a JOIN {$issues} i ON i.id=a.issue_id WHERE i.state='published' AND a.public_json IS NOT NULL");
        $fresh = Search::available() && Search::isFresh() && $indexed === $searchable;
        $searchDetails = ['В указателе ' . $indexed . ' из ' . $searchable . ' опубликованных материалов.'];
        if (!Search::available()) {
            $searchDetails[] = 'На сервере нет модуля PHP mbstring: поиск выключен, читатели видят «Поиск временно недоступен». Остальная газета работает.';
        }
        if (!$fresh) {
            $searchDetails[] = 'Указатель отстаёт (например, после отката на прежнюю версию). Он перестроится сам при открытии «Пульта»; читатели тем временем не видят снятых материалов.';
        }
        if (!$searchRule && get_option('permalink_structure')) {
            $searchDetails[] = 'Правило адреса /gazeta/search/ не найдено: ссылка «Поиск» ведёт на запасной адрес ?es_view=search. Нажмите «Завершить обновление» или пересохраните постоянные ссылки.';
        }
        $checks[] = self::check('Поиск по газете', $fresh && ($searchRule || !get_option('permalink_structure')) ? 'ok' : 'warn', $searchDetails);

        $ok = !array_filter($checks, static function ($c) {
            return $c['status'] === 'error';
        });
        return ['checks' => $checks, 'counts' => $counts, 'ok' => $ok];
    }

    private static function check(string $title, string $status, array $details): array
    {
        return ['title' => $title, 'status' => $status, 'details' => array_slice($details, 0, 50), 'more' => max(0, count($details) - 50)];
    }

    public static function report(array $result): string
    {
        $lines = ['Проверка данных «Ежедневного стандарта» ' . ES_VERSION . ' · ' . gmdate('Y-m-d H:i') . ' UTC'];
        foreach ($result['counts'] as $key => $value) {
            $lines[] = $key . ': ' . $value;
        }
        foreach ($result['checks'] as $check) {
            $lines[] = strtoupper($check['status']) . ' — ' . $check['title'];
            foreach ($check['details'] as $detail) {
                $lines[] = '    ' . $detail;
            }
        }
        return implode("\n", $lines);
    }
}
