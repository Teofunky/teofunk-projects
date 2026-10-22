<?php

namespace Kozlov\Gazeta\Admin\Screens;

use Kozlov\Gazeta\Admin\Ui;
use Kozlov\Gazeta\Data\Integrity;

if (!defined('ABSPATH')) {
    exit;
}

/** "Проверка данных": the read-only dry-run report. */
final class IntegrityScreen
{
    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Нет прав.', '', ['response' => 403]);
        }
        $result = Integrity::run();
        $labels = ['ok' => 'в порядке', 'warn' => 'внимание', 'error' => 'ошибка'];
        $countLabels = [
            'issues_draft' => 'черновиков', 'issues_published' => 'опубликовано', 'issues_withdrawn' => 'снято',
            'articles' => 'статей всего', 'articles_public' => 'опубликованных статей', 'revisions' => 'версий в истории', 'imports' => 'импортов',
        ];
        Ui::open('Проверка данных', 'es-integrity', ['subtitle' => 'Только чтение: ничего не меняет. Запускайте до и после обновления плагина.']);
        echo '<div class="es-alert ' . ($result['ok'] ? 'es-alert--ok' : 'es-alert--error') . '">' . ($result['ok'] ? 'Ошибок в данных газеты не найдено.' : 'Есть ошибки — не обновляйте плагин, пока они не разобраны.') . '</div>';
        echo '<div class="es-summary-row">';
        foreach ($countLabels as $key => $label) {
            if (!isset($result['counts'][$key])) {
                continue;
            }
            echo '<div class="es-summary"><strong>' . (int) $result['counts'][$key] . '</strong><span>' . esc_html($label) . '</span></div>';
        }
        echo '</div><section class="es-card"><ul class="es-checklist es-checklist--wide">';
        foreach ($result['checks'] as $check) {
            $cls = $check['status'] === 'ok' ? 'is-ok' : ($check['status'] === 'warn' ? 'is-warn' : 'is-bad');
            echo '<li class="' . esc_attr($cls) . '"><span class="es-checklist__icon" aria-hidden="true">' . ($check['status'] === 'ok' ? '✓' : '!') . '</span><div>';
            echo '<span>' . esc_html($check['title']) . '</span> <span class="es-chip es-chip--' . esc_attr($check['status']) . '">' . esc_html($labels[$check['status']]) . '</span>';
            echo '<ul class="es-checklist__details">';
            foreach ($check['details'] as $detail) {
                echo '<li>' . esc_html($detail) . '</li>';
            }
            if ($check['more']) {
                echo '<li>и ещё ' . (int) $check['more'] . '</li>';
            }
            echo '</ul></div></li>';
        }
        echo '</ul></section>';
        echo '<details class="es-card es-details"><summary>Текст отчёта для пересылки</summary><textarea class="es-code" rows="12" readonly data-es-select-all>' . esc_textarea(Integrity::report($result)) . '</textarea></details>';
        Ui::close();
    }
}
