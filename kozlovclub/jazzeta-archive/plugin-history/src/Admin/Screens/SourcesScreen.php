<?php

namespace Kozlov\Gazeta\Admin\Screens;

use Kozlov\Gazeta\Admin\Ui;

if (!defined('ABSPATH')) {
    exit;
}

/** The agreed starting catalogue of 30 sites. A list to work from, not proof of RSS access or facts. */
final class SourcesScreen
{
    public static function render(): void
    {
        if (!current_user_can('es_edit')) {
            wp_die('Нет прав.', '', ['response' => 403]);
        }
        $catalog = json_decode((string) file_get_contents(ES_DIR . 'sources.json'), true);
        $catalog = is_array($catalog) ? $catalog : [];
        $groups = [];
        foreach ($catalog as $source) {
            $groups[(string) ($source['region'] ?? 'Другое')][] = $source;
        }
        Ui::open('Источники', 'es-sources', ['subtitle' => count($catalog) . ' согласованных сайтов. Каталог не подтверждает RSS, факты или права на фото.']);
        echo '<div class="es-filterbar" data-es-filterbar><input type="search" placeholder="Найти источник" data-es-filter-input aria-label="Найти источник"> ';
        foreach (['A', 'B', 'C'] as $priority) {
            echo '<label class="es-chip-check"><input type="checkbox" value="' . esc_attr($priority) . '" checked data-es-filter-priority><span>Приоритет ' . esc_html($priority) . '</span></label>';
        }
        echo '</div>';
        foreach ($groups as $region => $sources) {
            echo '<section class="es-card"><h2 class="es-card__title">' . esc_html($region) . ' <span class="es-muted">· ' . count($sources) . '</span></h2><ul class="es-sources-grid">';
            foreach ($sources as $source) {
                $host = preg_replace('/^www\./', '', (string) wp_parse_url((string) ($source['url'] ?? ''), PHP_URL_HOST));
                echo '<li class="es-source-card" data-es-source data-priority="' . esc_attr((string) ($source['priority'] ?? '')) . '">';
                echo '<span class="es-source-card__priority es-source-card__priority--' . esc_attr(strtolower((string) ($source['priority'] ?? ''))) . '">' . esc_html((string) ($source['priority'] ?? '')) . '</span>';
                echo '<a class="es-source-card__name" href="' . esc_url((string) ($source['url'] ?? '')) . '" target="_blank" rel="noopener noreferrer">' . esc_html((string) ($source['name'] ?? $host)) . '</a>';
                echo '<span class="es-source-card__host">' . esc_html($host) . ' ↗</span>';
                echo '<span class="es-source-card__role">' . esc_html((string) ($source['role'] ?? '')) . '</span></li>';
            }
            echo '</ul></section>';
        }
        Ui::close();
    }
}
