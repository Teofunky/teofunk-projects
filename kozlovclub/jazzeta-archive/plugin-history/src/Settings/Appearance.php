<?php

namespace Kozlov\Gazeta\Settings;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Presentation settings (option es_appearance). Never stores issue or article data.
 * Keys of 0.3.x are kept; 0.4.0 added `typeface` and `design_version`, 0.5.0 adds `design`
 * (reading mode for new readers) and `photo_mono`. Older versions ignore unknown keys.
 */
final class Appearance
{
    public const OPTION = 'es_appearance';

    /** Reading modes. One markup, one URL; the key only selects the composition. */
    public const DESIGNS = [
        'gazeta' => 'Газета',
        'modern' => 'Современный',
        'brief' => 'Кратко',
    ];

    public const DEFAULT_DESIGN = 'gazeta';

    /** Colours that 0.3.x and 0.4.0 stored as their defaults: treated as "not customised". */
    private const LEGACY_DEFAULTS = [
        'paper' => '#f3f0e7',
        'ink' => '#191d1b',
        'accent' => '#8b352d',
    ];

    /** Mode «Газета» palette from the 22.10.2026 brief. */
    public const PALETTE = [
        'paper' => '#f6f3eb',
        'ink' => '#1e1d1a',
        'accent' => '#702d35',
    ];

    public const TYPEFACES = [
        'lora' => 'Газетная антиква (Lora, встроенная) — рекомендуется',
        'georgia' => 'Georgia — системный шрифт',
        'times' => 'Times New Roman — системный шрифт',
    ];

    public static function defaults(): array
    {
        return [
            'name' => 'Ежедневный стандарт',
            'subtitle' => 'Хроника джаза и музыки нашего времени',
            'motto' => 'Читать между нот',
            'logo_id' => 0,
            'club_home_url' => home_url('/'),
            'club_events_url' => home_url('/'),
            'background' => '#152320',
            'paper' => self::PALETTE['paper'],
            'ink' => self::PALETTE['ink'],
            'accent' => self::PALETTE['accent'],
            'font' => 'georgia',
            'typeface' => 'lora',
            'template' => 'classic',
            'layout' => 'editorial',
            'social_template' => 'classic-4x5',
            'config_version' => 1,
            'design_version' => 3,
            'design' => self::DEFAULT_DESIGN,
            // Black-and-white photos change the picture: off until the owner confirms the rights allow it.
            'photo_mono' => 0,
        ];
    }

    public static function get(): array
    {
        $stored = get_option(self::OPTION, []);
        $settings = array_merge(self::defaults(), is_array($stored) ? $stored : []);
        if (!isset(self::TYPEFACES[$settings['typeface']])) {
            $settings['typeface'] = 'lora';
        }
        if (!isset(self::DESIGNS[$settings['design']])) {
            $settings['design'] = self::DEFAULT_DESIGN;
        }
        foreach (self::LEGACY_DEFAULTS as $key => $legacy) {
            if (strtolower((string) $settings[$key]) === $legacy) {
                $settings[$key] = self::PALETTE[$key];
            }
        }
        return $settings;
    }

    public static function name(): string
    {
        return (string) self::get()['name'];
    }

    /** Masthead split: "Ежедневный стандарт" → ["Ежедневный", "стандарт"]. */
    public static function nameParts(): array
    {
        $parts = preg_split('/\s+/u', trim(self::name())) ?: [self::name()];
        $last = array_pop($parts);
        return [implode(' ', $parts), (string) $last];
    }

    public static function sanitize($input): array
    {
        $old = self::get();
        if (!current_user_can('manage_options') || !is_array($input)) {
            return $old;
        }
        $out = $old;
        foreach (['name' => 80, 'subtitle' => 150, 'motto' => 120] as $key => $max) {
            if (!isset($input[$key]) || !is_string($input[$key])) {
                continue;
            }
            $value = trim(sanitize_text_field($input[$key]));
            $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
            if (($key === 'name' && $value === '') || $length > $max) {
                add_settings_error(self::OPTION, $key, 'Проверьте длину поля «' . $key . '». Прежнее значение сохранено.');
            } else {
                $out[$key] = $value;
            }
        }
        foreach (['background', 'paper', 'ink', 'accent'] as $key) {
            $color = isset($input[$key]) && is_string($input[$key]) ? sanitize_hex_color($input[$key]) : null;
            if ($color) {
                $out[$key] = $color;
            }
        }
        foreach (['club_home_url', 'club_events_url'] as $key) {
            if (!isset($input[$key])) {
                continue;
            }
            $url = is_string($input[$key]) ? esc_url_raw($input[$key], ['http', 'https']) : '';
            $parts = wp_parse_url($url);
            if ($url && ($parts['host'] ?? '') === wp_parse_url(home_url(), PHP_URL_HOST) && !isset($parts['user']) && !isset($parts['pass'])) {
                $out[$key] = $url;
            } else {
                add_settings_error(self::OPTION, $key, 'Ссылки клуба должны вести на этот сайт.');
            }
        }
        if (isset($input['logo_id'])) {
            $logo = is_scalar($input['logo_id']) ? absint($input['logo_id']) : 0;
            if (!$logo || (wp_attachment_is_image($logo) && in_array(get_post_mime_type($logo), ['image/png', 'image/jpeg', 'image/webp', 'image/avif', 'image/svg+xml'], true))) {
                $out['logo_id'] = $logo;
            } else {
                add_settings_error(self::OPTION, 'logo', 'Выберите изображение логотипа из медиатеки.');
            }
        }
        if (isset($input['typeface']) && isset(self::TYPEFACES[$input['typeface']])) {
            $out['typeface'] = $input['typeface'];
            // Keep the 0.3.x key meaningful for a rollback.
            $out['font'] = $input['typeface'] === 'times' ? 'times' : 'georgia';
        }
        if (isset($input['design']) && is_string($input['design']) && isset(self::DESIGNS[$input['design']])) {
            $out['design'] = $input['design'];
        }
        // A checkbox: absent from the form means "off".
        if (isset($input['design_form'])) {
            $out['photo_mono'] = !empty($input['photo_mono']) ? 1 : 0;
        }
        // Template identifiers are an allowlist; user input never selects include paths, CSS or JS.
        $out['template'] = 'classic';
        $out['layout'] = 'editorial';
        $out['social_template'] = 'classic-4x5';
        $out['config_version'] = 1;
        $out['design_version'] = 3;
        return $out;
    }

    /**
     * Scoped CSS custom properties. The owner's colours apply to mode «Газета» in the light
     * palette only; «Современный», «Кратко» and the dark palette keep their tested contrast.
     */
    public static function cssVariables(): string
    {
        $s = self::get();
        $color = static function ($value, $fallback) {
            $hex = sanitize_hex_color((string) $value);
            return $hex ?: $fallback;
        };
        $stacks = [
            'lora' => "'ES Serif', Georgia, 'Times New Roman', serif",
            'georgia' => "Georgia, 'Times New Roman', serif",
            'times' => "'Times New Roman', Times, Georgia, serif",
        ];
        return 'html[data-es-design="gazeta"]:not([data-es-theme="dark"]) #es-newspaper,html[data-es-design="gazeta"]:not([data-es-theme="dark"]) body.es-newspaper-page{'
            . '--es-bg:' . $color($s['paper'], self::PALETTE['paper']) . ';'
            . '--es-ink:' . $color($s['ink'], self::PALETTE['ink']) . ';'
            . '--es-accent:' . $color($s['accent'], self::PALETTE['accent']) . ';'
            . '}'
            . '#es-newspaper{--es-serif:' . $stacks[$s['typeface']] . ';}'
            . (empty($s['photo_mono']) ? '' : 'html[data-es-design="gazeta"] #es-newspaper .es-figure:not(.es-figure--plate) .es-figure__img{filter:grayscale(1)}'
                . 'html[data-es-design="gazeta"] #es-newspaper .es-figure:not(.es-figure--plate) .es-figure__credit::after{content:" · ч/б версия"}');
    }
}
