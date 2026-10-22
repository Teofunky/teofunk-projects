<?php
/**
 * Reader settings panel «Оформление». Works only with JavaScript (the toggle stays hidden
 * without it); choices live in this browser only and never reach the server or the page cache.
 * @var array $v
 */
if (!defined('ABSPATH')) {
    exit;
}
$designs = [
    'gazeta' => ['Газета', 'Полоса с главным материалом и колонками'],
    'modern' => ['Современный', 'Главное крупно, карточки с пояснениями'],
    'brief' => ['Кратко', 'Нумерованная сводка без картинок'],
];
?>
<div class="es-prefs" id="es-prefs" role="group" aria-labelledby="es-prefs-title" hidden data-es-prefs>
    <div class="es-prefs__head">
        <h2 class="es-prefs__title" id="es-prefs-title">Оформление</h2>
        <button type="button" class="es-prefs__close" data-es-prefs-close aria-label="Закрыть настройки">
            <svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M3.5 3.5l9 9M12.5 3.5l-9 9" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
        </button>
    </div>
    <fieldset class="es-prefs__designs">
        <legend>Вид газеты</legend>
        <?php foreach ($designs as $key => [$name, $hint]) : ?>
            <label class="es-design-opt">
                <input type="radio" name="es-design" value="<?php echo esc_attr($key); ?>" data-es-pref="design">
                <span class="es-design-opt__thumb es-design-opt__thumb--<?php echo esc_attr($key); ?>" aria-hidden="true">
                    <?php if ($key === 'gazeta') : ?>
                        <svg viewBox="0 0 64 44"><rect x="0.5" y="0.5" width="63" height="43" rx="3" class="t-bg"/><rect x="18" y="5" width="28" height="4" class="t-ink"/><rect x="6" y="12" width="52" height="1" class="t-ink"/><rect x="6" y="16" width="30" height="4" class="t-ink"/><rect x="6" y="22" width="30" height="2" class="t-mute"/><rect x="6" y="26" width="26" height="2" class="t-mute"/><rect x="40" y="16" width="18" height="12" class="t-img"/><rect x="6" y="32" width="16" height="2" class="t-ink"/><rect x="25" y="32" width="15" height="2" class="t-ink"/><rect x="43" y="32" width="15" height="2" class="t-ink"/><rect x="6" y="36" width="15" height="1.5" class="t-mute"/><rect x="25" y="36" width="14" height="1.5" class="t-mute"/><rect x="43" y="36" width="14" height="1.5" class="t-mute"/></svg>
                    <?php elseif ($key === 'modern') : ?>
                        <svg viewBox="0 0 64 44"><rect x="0.5" y="0.5" width="63" height="43" rx="3" class="t-bg"/><rect x="6" y="5" width="20" height="3" class="t-ink"/><rect x="6" y="11" width="34" height="6" class="t-ink"/><rect x="6" y="19" width="34" height="11" class="t-img"/><rect x="44" y="11" width="14" height="2" class="t-acc"/><rect x="44" y="16" width="14" height="1.5" class="t-ink"/><rect x="44" y="20" width="14" height="1.5" class="t-ink"/><rect x="44" y="24" width="14" height="1.5" class="t-ink"/><rect x="6" y="33" width="24" height="7" rx="1" class="t-card"/><rect x="34" y="33" width="24" height="7" rx="1" class="t-card"/></svg>
                    <?php else : ?>
                        <svg viewBox="0 0 64 44"><rect x="0.5" y="0.5" width="63" height="43" rx="3" class="t-bg"/><rect x="10" y="5" width="30" height="3" class="t-ink"/><rect x="10" y="12" width="4" height="3" class="t-acc"/><rect x="17" y="12" width="36" height="3" class="t-ink"/><rect x="17" y="17" width="32" height="1.5" class="t-mute"/><rect x="10" y="23" width="4" height="3" class="t-acc"/><rect x="17" y="23" width="34" height="3" class="t-ink"/><rect x="17" y="28" width="30" height="1.5" class="t-mute"/><rect x="10" y="34" width="4" height="3" class="t-acc"/><rect x="17" y="34" width="36" height="3" class="t-ink"/><rect x="17" y="39" width="28" height="1.5" class="t-mute"/></svg>
                    <?php endif; ?>
                </span>
                <span class="es-design-opt__name"><?php echo esc_html($name); ?></span>
                <span class="es-design-opt__hint"><?php echo esc_html($hint); ?></span>
                <span class="es-design-opt__mark" aria-hidden="true">Выбрано</span>
            </label>
        <?php endforeach; ?>
    </fieldset>
    <div class="es-prefs__rows">
        <fieldset class="es-prefs__row">
            <legend>Цвет</legend>
            <div class="es-seg">
                <label><input type="radio" name="es-theme" value="auto" data-es-pref="theme"><span>Как в системе</span></label>
                <label><input type="radio" name="es-theme" value="light" data-es-pref="theme"><span>Светлый</span></label>
                <label><input type="radio" name="es-theme" value="dark" data-es-pref="theme"><span>Тёмный</span></label>
            </div>
        </fieldset>
        <div class="es-prefs__row" role="group" aria-labelledby="es-size-label">
            <span class="es-prefs__label" id="es-size-label">Размер текста</span>
            <div class="es-stepper">
                <button type="button" data-es-size-step="-1" aria-label="Уменьшить текст">A−</button>
                <output class="es-stepper__value" data-es-size-value aria-live="polite">100%</output>
                <button type="button" data-es-size-step="1" aria-label="Увеличить текст">A+</button>
            </div>
        </div>
        <fieldset class="es-prefs__row">
            <legend>Плотность</legend>
            <div class="es-seg">
                <label><input type="radio" name="es-density" value="normal" data-es-pref="density"><span>Обычно</span></label>
                <label><input type="radio" name="es-density" value="airy" data-es-pref="density"><span>Просторно</span></label>
            </div>
        </fieldset>
    </div>
    <p class="es-prefs__note">Настройки сохраняются только в этом браузере. <button type="button" class="es-linkish" data-es-prefs-reset>Вернуть как было</button></p>
</div>
