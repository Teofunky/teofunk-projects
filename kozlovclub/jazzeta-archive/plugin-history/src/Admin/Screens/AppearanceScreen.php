<?php

namespace Kozlov\Gazeta\Admin\Screens;

use Kozlov\Gazeta\Admin\Ui;
use Kozlov\Gazeta\Data\Issues;
use Kozlov\Gazeta\Http\Router;
use Kozlov\Gazeta\Settings\Analytics;
use Kozlov\Gazeta\Settings\Appearance;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reading mode for new readers with previews of all three modes, name, motto, palette, typeface,
 * club link and the statistics counter, with a live masthead preview.
 */
final class AppearanceScreen
{
    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Нет прав.', '', ['response' => 403]);
        }
        $s = Appearance::get();
        $a = Analytics::get();
        Ui::open('Оформление', 'es-appearance', ['subtitle' => 'Название и вид газеты. Адрес всегда /gazeta/.']);
        settings_errors(Appearance::OPTION);
        settings_errors(Analytics::OPTION);
        $field = static function (string $key, string $label, string $hint = '', string $type = 'text') use ($s) {
            echo '<div class="es-field"><label for="es-a-' . esc_attr($key) . '">' . esc_html($label) . ($hint ? ' <span class="es-hint">' . esc_html($hint) . '</span>' : '') . '</label>';
            echo '<input id="es-a-' . esc_attr($key) . '" type="' . esc_attr($type) . '" name="' . esc_attr(Appearance::OPTION . '[' . $key . ']') . '" value="' . esc_attr((string) $s[$key]) . '" data-es-preview="' . esc_attr($key) . '"></div>';
        };
        echo '<form method="post" action="' . esc_url(admin_url('options.php')) . '" class="es-layout es-layout--issue" data-es-dirty-guard>';
        settings_fields('es_appearance_group');
        echo '<div class="es-main-col">';
        self::designSection($s);
        echo '<section class="es-card"><h2 class="es-card__title">Издание</h2>';
        $field('name', 'Название', 'последнее слово становится главным в шапке');
        $field('subtitle', 'Подзаголовок');
        $field('motto', 'Девиз под названием');
        $field('club_home_url', 'Главная страница клуба', 'куда ведёт «На главную сайта клуба» вверху и внизу газеты; других ссылок на сайт клуба нет', 'url');
        echo '<input type="hidden" name="' . esc_attr(Appearance::OPTION) . '[club_events_url]" value="' . esc_attr((string) $s['club_events_url']) . '">';
        $logoId = (int) $s['logo_id'];
        $logoImage = $logoId ? wp_get_attachment_image($logoId, 'medium', false, ['alt' => '']) : '';
        echo '<div class="es-field"><span class="es-label-like">Логотип вместо текстовой шапки <span class="es-hint">необязательно</span></span>';
        echo '<div class="es-logo-pick">';
        echo '<span class="es-logo-pick__thumb" data-es-logo-thumb>' . ($logoImage ?: '<span class="es-muted">Не выбран — в шапке набранное название</span>') . '</span>';
        echo '<span class="es-logo-pick__actions"><button type="button" class="es-btn es-btn--small" data-es-logo-pick>Выбрать в медиатеке</button>';
        echo '<button type="button" class="es-linkbtn" data-es-logo-clear' . ($logoId ? '' : ' hidden') . '>Убрать логотип</button></span>';
        echo '<input type="hidden" name="' . esc_attr(Appearance::OPTION) . '[logo_id]" value="' . $logoId . '" data-es-logo-id>';
        echo '</div></div>';
        echo '</section><section class="es-card"><h2 class="es-card__title">Шрифт и цвета</h2>';
        echo '<span class="es-label-like">Шрифт газеты</span><div class="es-segmented es-segmented--wrap">';
        foreach (Appearance::TYPEFACES as $key => $label) {
            echo '<label><input type="radio" name="' . esc_attr(Appearance::OPTION) . '[typeface]" value="' . esc_attr($key) . '"' . checked($s['typeface'], $key, false) . ' data-es-preview="typeface"><span>' . esc_html($label) . '</span></label>';
        }
        echo '</div><div class="es-colors">';
        foreach (['background' => 'Фон вокруг листа', 'paper' => 'Бумага', 'ink' => 'Текст', 'accent' => 'Акцент'] as $key => $label) {
            echo '<label class="es-color"><input type="color" name="' . esc_attr(Appearance::OPTION . '[' . $key . ']') . '" value="' . esc_attr((string) $s[$key]) . '" data-es-preview="' . esc_attr($key) . '"><span>' . esc_html($label) . '</span><code>' . esc_html((string) $s[$key]) . '</code></label>';
        }
        echo '</div><p class="es-muted">После смены цветов проверьте контраст текста на телефоне. Цвета действуют в режиме «Газета» в светлой палитре; «Современный», «Кратко» и тёмная палитра держат проверенный контраст. Исходная палитра «Газеты»: бумага #f6f3eb, текст #1e1d1a, акцент #702d35.</p></section>';
        echo '<section class="es-card"><h2 class="es-card__title">Статистика</h2>';
        echo '<div class="es-field es-field--narrow"><label for="es-metrika">Номер счётчика Яндекс.Метрики <span class="es-hint">пусто — без счётчика</span></label>';
        echo '<input id="es-metrika" type="text" inputmode="numeric" pattern="\d{4,12}" name="' . esc_attr(Analytics::OPTION) . '[metrika_id]" value="' . esc_attr($a['metrika_id']) . '"></div>';
        echo '<label class="es-check"><input type="checkbox" name="' . esc_attr(Analytics::OPTION) . '[webvisor]" value="1"' . checked($a['webvisor'], true, false) . '> <span>Вебвизор, как на остальных страницах клуба</span></label>';
        echo '<p class="es-muted">Счётчик подключается только в открытом режиме. В закрытом тесте и предпросмотре внешние скрипты запрещены.</p></section></div>';

        echo '<aside class="es-side-col es-side-col--sticky"><section class="es-card"><h2 class="es-card__title">Шапка газеты</h2>';
        [$over, $main] = Appearance::nameParts();
        echo '<div class="es-mast-preview" data-es-mast-preview style="--p-bg:' . esc_attr((string) $s['background']) . ';--p-paper:' . esc_attr((string) $s['paper']) . ';--p-ink:' . esc_attr((string) $s['ink']) . ';--p-accent:' . esc_attr((string) $s['accent']) . '">';
        echo '<div class="es-mast-preview__sheet"><span class="es-mast-preview__sub" data-es-p-subtitle>' . esc_html((string) $s['subtitle']) . '</span>';
        echo '<span class="es-mast-preview__over" data-es-p-over>' . esc_html($over) . '</span><span class="es-mast-preview__main"><span data-es-p-main>' . esc_html($main) . '</span></span>';
        echo '<span class="es-mast-preview__motto" data-es-p-motto>' . esc_html((string) $s['motto']) . '</span></div></div>';
        echo '<div class="es-actions"><button type="submit" class="es-btn es-btn--primary">Сохранить оформление</button></div>';
        echo '<p class="es-savehint" data-es-dirty-label>Изменений нет</p></section></aside>';
        echo '</form>';
        Ui::close();
    }

    /** Mode for new readers, black-and-white photos and side-by-side previews of the three modes. */
    private static function designSection(array $s): void
    {
        $name = Appearance::OPTION;
        $hints = [
            'gazeta' => 'Тёплая бумага, антиква, полоса с главным материалом и колонками. По умолчанию.',
            'modern' => 'Белый лист, крупный главный материал, карточки и оглавление номера.',
            'brief' => 'Нумерованная текстовая сводка: заголовок, суть, источник. Без больших картинок.',
        ];
        echo '<section class="es-card" aria-labelledby="es-design-title"><h2 class="es-card__title" id="es-design-title">Вид газеты для новых читателей</h2>';
        echo '<p class="es-muted">Читатель может переключить вид сам — его выбор хранится в его браузере и не меняется от этой настройки. Адреса страниц, тексты и статусы во всех видах одни и те же.</p>';
        echo '<input type="hidden" name="' . esc_attr($name) . '[design_form]" value="1">';
        echo '<div class="es-design-pick">';
        foreach (Appearance::DESIGNS as $key => $label) {
            echo '<label class="es-design-pick__opt"><input type="radio" name="' . esc_attr($name) . '[design]" value="' . esc_attr($key) . '"' . checked($s['design'], $key, false) . '>';
            echo '<span class="es-design-pick__name">' . esc_html($label) . ($key === Appearance::DEFAULT_DESIGN ? ' <span class="es-hint">исходный</span>' : '') . '</span>';
            echo '<span class="es-design-pick__hint">' . esc_html($hints[$key]) . '</span></label>';
        }
        echo '</div>';
        if ($s['design'] !== Appearance::DEFAULT_DESIGN) {
            // Placed after the radios: its value wins in the submitted form.
            echo '<p><button type="submit" class="es-btn" name="' . esc_attr($name) . '[design]" value="' . esc_attr(Appearance::DEFAULT_DESIGN) . '">Вернуть «Газету» для новых читателей</button></p>';
        }
        echo '<label class="es-check"><input type="checkbox" name="' . esc_attr($name) . '[photo_mono]" value="1"' . checked(!empty($s['photo_mono']), true, false) . '> <span>Чёрно-белые фото в режиме «Газета». <span class="es-muted">Это изменение снимка: включайте, только если права на все фото его допускают. Под фото появится пометка «ч/б версия».</span></span></label>';

        $issue = Issues::latestPublished();
        if (!$issue) {
            $rows = Issues::listing(1, false)['items'] ?? [];
            $issue = $rows ? Issues::find((int) $rows[0]['id']) : null;
        }
        // Six frames load only when the block is opened (desk.js), not with every visit to the settings.
        echo '<details class="es-details es-design-previews" data-es-design-previews><summary>Предпросмотр трёх видов: телефон и компьютер</summary>';
        if (!$issue) {
            echo '<p class="es-muted">Предпросмотр появится, когда в газете будет хотя бы один выпуск.</p></details></section>';
            return;
        }
        $base = Router::preview((int) $issue['id'], (int) $issue['version']);
        echo '<p class="es-muted">Выпуск ' . esc_html((string) $issue['edition_date']) . ', рабочая версия. Предпросмотр ничего не публикует. ';
        echo '<label class="es-check es-check--inline"><input type="checkbox" data-es-preview-dark> <span>Тёмная палитра</span></label></p>';
        echo '<div class="es-design-previews__grid">';
        foreach (Appearance::DESIGNS as $key => $label) {
            $src = add_query_arg(['design' => $key, 'theme' => 'light'], $base);
            echo '<figure class="es-design-previews__item"><figcaption>' . esc_html($label) . '</figcaption>';
            echo '<div class="es-design-previews__pair">';
            echo '<div class="es-frame es-frame--phone"><iframe tabindex="-1" title="' . esc_attr($label . ' — телефон, 390 пикселей') . '" data-es-preview-src="' . esc_url($src) . '"></iframe></div>';
            echo '<div class="es-frame es-frame--desktop"><iframe tabindex="-1" title="' . esc_attr($label . ' — компьютер, 1280 пикселей') . '" data-es-preview-src="' . esc_url($src) . '"></iframe></div>';
            echo '</div></figure>';
        }
        echo '</div></details></section>';
    }
}
