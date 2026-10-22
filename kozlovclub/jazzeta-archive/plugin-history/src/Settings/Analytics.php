<?php

namespace Kozlov\Gazeta\Settings;

use Kozlov\Gazeta\Runtime\Mode;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Yandex.Metrika on public newspaper pages only.
 *
 * The club theme hard-codes counter 44419291 in its header; the newspaper no longer uses
 * the theme header, so the same counter is printed here to keep statistics continuous.
 * Only a numeric counter id is stored — never arbitrary HTML or script.
 * Closed mode and previews never output it (and the CSP would block it anyway).
 */
final class Analytics
{
    public const OPTION = 'es_analytics';

    public static function defaults(): array
    {
        return ['metrika_id' => '44419291', 'webvisor' => true];
    }

    public static function get(): array
    {
        $stored = get_option(self::OPTION, []);
        $settings = array_merge(self::defaults(), is_array($stored) ? $stored : []);
        $settings['metrika_id'] = preg_match('/^\d{4,12}$/', (string) $settings['metrika_id']) ? (string) $settings['metrika_id'] : '';
        $settings['webvisor'] = (bool) $settings['webvisor'];
        return $settings;
    }

    public static function sanitize($input): array
    {
        $old = self::get();
        if (!current_user_can('manage_options') || !is_array($input)) {
            return $old;
        }
        $id = isset($input['metrika_id']) ? trim((string) $input['metrika_id']) : '';
        if ($id !== '' && !preg_match('/^\d{4,12}$/', $id)) {
            add_settings_error(self::OPTION, 'metrika', 'Номер счётчика Метрики — только цифры. Прежнее значение сохранено.');
            $id = $old['metrika_id'];
        }
        return ['metrika_id' => $id, 'webvisor' => !empty($input['webvisor'])];
    }

    public static function markup(): string
    {
        if (Mode::current() !== Mode::OPEN) {
            return '';
        }
        $s = self::get();
        if ($s['metrika_id'] === '') {
            return '';
        }
        $id = (int) $s['metrika_id'];
        $options = wp_json_encode([
            'clickmap' => true,
            'trackLinks' => true,
            'accurateTrackBounce' => true,
            'webvisor' => $s['webvisor'],
        ]);
        return "\n<!-- Yandex.Metrika counter -->\n"
            . '<script>(function(m,e,t,r,i,k,a){m[i]=m[i]||function(){(m[i].a=m[i].a||[]).push(arguments)};m[i].l=1*new Date();'
            . 'for(var j=0;j<document.scripts.length;j++){if(document.scripts[j].src===r){return;}}'
            . 'k=e.createElement(t),a=e.getElementsByTagName(t)[0],k.async=1,k.src=r,a.parentNode.insertBefore(k,a)})'
            . '(window,document,"script","https://mc.yandex.ru/metrika/tag.js","ym");'
            . 'ym(' . $id . ',"init",' . $options . ');</script>'
            . '<noscript><div><img src="https://mc.yandex.ru/watch/' . $id . '" style="position:absolute;left:-9999px" alt=""></div></noscript>'
            . "\n<!-- /Yandex.Metrika counter -->\n";
    }
}
