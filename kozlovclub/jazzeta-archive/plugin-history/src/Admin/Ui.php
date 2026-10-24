<?php

namespace Kozlov\Gazeta\Admin;

use Kozlov\Gazeta\Domain\Labels;
use Kozlov\Gazeta\Http\Router;
use Kozlov\Gazeta\Runtime\Mode;
use Kozlov\Gazeta\Settings\Appearance;

if (!defined('ABSPATH')) {
    exit;
}

/** Shared desk chrome: header, section tabs, page title, notices, chips. */
final class Ui
{
    private const TABS = [
        'es-newsroom' => ['Пульт', 'es_edit'],
        'es-import' => ['Импорт', 'es_import'],
        'es-sources' => ['Источники', 'es_edit'],
        'es-runtime' => ['Режим', 'es_edit'],
        'es-appearance' => ['Оформление', 'manage_options'],
        'es-access' => ['Доступ', 'manage_options'],
        'es-integrity' => ['Проверка данных', 'manage_options'],
    ];

    private const NOTICES = [
        'created' => 'Выпуск создан. Добавьте материалы или импортируйте JSON.',
        'imported' => 'Черновик импортирован. Публичная версия не менялась.',
        'import_unchanged' => 'Такой JSON уже был импортирован — изменений нет.',
        'manual_import' => 'Черновик заменён из JSON. Прежняя версия сохранена в истории; проверки сброшены.',
        'saved' => 'Материал сохранён. Подтверждение проверки снимается, если менялся текст.',
        'reviewed' => 'Материал сохранён и отмечен проверенным в этой версии.',
        'article_removed' => 'Материал убран из рабочей версии. Читатели увидят изменение после нового утверждения.',
        'issue_saved' => 'Сборка номера сохранена.',
        'crossword_saved' => 'Кроссворд собран и сохранён в рабочей версии. Читатели увидят его после утверждения номера и будут видеть на выпусках недели; ответы откроются через неделю.',
        'crossword_removed' => 'Кроссворд убран из рабочей версии номера. Прежний вариант остался в истории версий.',
        'focus_saved' => 'Кадр сохранён. Проверка текста не снималась; если номер уже вышел, читатели увидят новый кадр после повторного утверждения.',
        'restored' => 'Прежняя версия перенесена в рабочий черновик. Проверки сняты; опубликованная версия не менялась.',
        'published' => 'Выпуск утверждён и опубликован.',
        'withdrawn' => 'Выпуск снят с публикации. Данные сохранены.',
        'migrated' => 'Старый выпуск скопирован в новый непроверенный черновик.',
        'upgraded' => 'Обновление завершено: таблицы проверены, указатель поиска собран; опубликованные данные не изменялись.',
        'mode_closed' => 'Газета в закрытом режиме: её видит только редакция.',
        'mode_public' => 'Газета открыта читателям.',
        'mode_paused' => 'Газета остановлена.',
    ];

    public static function open(string $title, string $current, array $opts = []): void
    {
        $mode = Mode::current();
        echo '<div class="wrap es-desk' . (!empty($opts['class']) ? ' ' . esc_attr($opts['class']) : '') . '">';
        echo '<header class="es-desk-head">';
        echo '<a class="es-desk-head__brand" href="' . esc_url(Admin::url()) . '"><span class="es-desk-head__mark">' . esc_html(Appearance::name()) . '</span><span class="es-desk-head__section">Редакция</span></a>';
        echo '<div class="es-desk-head__meta">';
        echo '<a class="es-mode es-mode--' . esc_attr($mode) . '" href="' . esc_url(admin_url('admin.php?page=es-runtime')) . '" title="Режим газеты"><span class="es-mode__dot" aria-hidden="true"></span>' . esc_html(Mode::LABELS[$mode]) . '</a>';
        echo '<a class="es-desk-head__link" href="' . esc_url(Router::home()) . '" target="_blank" rel="noopener">Газета ↗</a>';
        echo '</div></header>';

        // While stopped or waiting for the upgrade only the mode screen exists; other tabs would lead nowhere.
        $recovery = Mode::fault() || !Mode::ready();
        echo '<nav class="es-desk-tabs" aria-label="Разделы редакции">';
        foreach (self::TABS as $slug => [$label, $cap]) {
            if (!current_user_can($cap) || ($recovery && $slug !== 'es-runtime')) {
                continue;
            }
            $active = $slug === $current;
            echo '<a href="' . esc_url(admin_url('admin.php?page=' . $slug)) . '"' . ($active ? ' aria-current="page"' : '') . '>' . esc_html($label) . '</a>';
        }
        echo '</nav>';

        echo '<div class="es-desk-titlebar">';
        echo '<div class="es-desk-titlebar__text">';
        if (!empty($opts['back'])) {
            echo '<a class="es-back" href="' . esc_url($opts['back'][0]) . '">← ' . esc_html($opts['back'][1]) . '</a>';
        }
        echo '<h1 class="es-desk-title">' . wp_kses($title, ['span' => ['class' => true]]) . '</h1>';
        if (!empty($opts['subtitle'])) {
            echo '<p class="es-desk-subtitle">' . wp_kses($opts['subtitle'], ['span' => ['class' => true], 'strong' => [], 'a' => ['href' => true, 'target' => true, 'rel' => true]]) . '</p>';
        }
        echo '</div>';
        if (!empty($opts['actions'])) {
            echo '<div class="es-desk-titlebar__actions">' . $opts['actions'] . '</div>';
        }
        echo '</div>';
        echo '<hr class="wp-header-end">';
        self::notices();
    }

    public static function close(): void
    {
        echo '</div>';
    }

    public static function notices(): void
    {
        $flash = isset($_GET['es_error']) ? Actions::flash() : null;
        if ($flash) {
            $conflict = (int) ($flash['status'] ?? 0) === 409;
            $hint = $conflict
                ? ' Ваши правки восстановлены из копии в браузере. Сверьте их с новой версией — коллега мог изменить этот же материал — и сохраните ещё раз.'
                : ' Несохранённые правки восстановлены из копии в браузере.';
            echo '<div class="es-alert es-alert--error" role="alert" data-es-restore-draft><strong>Не сохранено.</strong> ' . esc_html($flash['message']) . '<span class="es-alert__hint" data-es-restore-hint hidden>' . esc_html($hint) . '</span></div>';
        }
        $key = isset($_GET['es_notice']) && is_string($_GET['es_notice']) ? sanitize_key(wp_unslash($_GET['es_notice'])) : '';
        if ($key !== '' && isset(self::NOTICES[$key])) {
            echo '<div class="es-alert es-alert--ok" role="status" data-es-clear-draft>' . esc_html(self::NOTICES[$key]) . '</div>';
        }
    }

    public static function stateChip(string $state): string
    {
        return '<span class="es-chip es-chip--' . esc_attr($state) . '">' . esc_html(Labels::ISSUE_STATES[$state] ?? $state) . '</span>';
    }

    public static function chip(string $text, string $tone = 'neutral'): string
    {
        return '<span class="es-chip es-chip--' . esc_attr($tone) . '">' . esc_html($text) . '</span>';
    }

    public static function userName(int $id): string
    {
        if ($id <= 0) {
            return 'система';
        }
        $user = get_userdata($id);
        return $user ? (string) $user->display_name : 'пользователь #' . $id;
    }

    /** "21 окт., 14:05" in Moscow time from a UTC MySQL datetime. */
    public static function when(?string $utc): string
    {
        if (!$utc) {
            return '—';
        }
        try {
            $date = (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Europe/Moscow'));
        } catch (\Exception $e) {
            return $utc;
        }
        return $date->format('j') . ' ' . mb_substr(Labels::MONTHS_GENITIVE[(int) $date->format('n') - 1], 0, 3) . '., ' . $date->format('H:i');
    }
}
