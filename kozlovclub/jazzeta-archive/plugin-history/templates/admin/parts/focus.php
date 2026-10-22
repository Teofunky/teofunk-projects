<?php
/**
 * Focus point of a photo (0.5.1): the editor clicks the important part of the picture (a face,
 * an instrument) and sees at once how the frames of the newspaper will crop it.
 * Keyboard: arrows move the point by 5 %, Shift+arrows by 1 %, Home returns to the centre.
 * Without JavaScript the block stays hidden and the photo is cropped around its centre.
 * @var array $v ['id' => string, 'name' => string, 'src' => string, 'focus' => ?array{x:int,y:int}, 'save' => bool, 'published' => bool]
 */
if (!defined('ABSPATH')) {
    exit;
}
$id = sanitize_key((string) $v['id']);
$name = (string) $v['name'];
$focus = is_array($v['focus'] ?? null) ? $v['focus'] : ['x' => 50, 'y' => 50];
$x = (int) ($focus['x'] ?? 50);
$y = (int) ($focus['y'] ?? 50);
$src = (string) ($v['src'] ?? '');
?>
<div class="es-focus" data-es-focus hidden>
    <p class="es-focus__title" id="es-focus-title-<?php echo esc_attr($id); ?>"><strong>Кадр</strong> — щёлкните по главному на фото: лицу, инструменту. Эта часть останется видна, как бы ни обрезала рамка.</p>
    <div class="es-focus__body">
        <div class="es-focus__pad" tabindex="0" role="group" aria-labelledby="es-focus-title-<?php echo esc_attr($id); ?>" aria-describedby="es-focus-value-<?php echo esc_attr($id); ?> es-focus-keys-<?php echo esc_attr($id); ?>" data-es-focus-pad>
            <img src="<?php echo esc_url($src); ?>" alt="" data-es-focus-img>
            <span class="es-focus__dot" aria-hidden="true" data-es-focus-dot></span>
        </div>
        <div class="es-focus__previews" aria-hidden="true">
            <figure><span class="es-focus__frame es-focus__frame--wide"><img src="<?php echo esc_url($src); ?>" alt="" data-es-focus-preview></span><figcaption>Первая полоса, статья · 3:2</figcaption></figure>
            <figure><span class="es-focus__frame es-focus__frame--cinema"><img src="<?php echo esc_url($src); ?>" alt="" data-es-focus-preview></span><figcaption>Статья в «Кратко» · 16:9</figcaption></figure>
        </div>
    </div>
    <p class="es-focus__state">
        <span id="es-focus-value-<?php echo esc_attr($id); ?>" data-es-focus-value aria-live="polite"></span>
        <span class="es-sr-only" id="es-focus-keys-<?php echo esc_attr($id); ?>">Стрелки двигают точку на 5 %, Shift со стрелкой — на 1 %, Home возвращает в центр.</span>
        <button type="button" class="es-btn es-btn--small" data-es-focus-reset>По центру</button>
        <?php if (!empty($v['save'])) : ?>
            <button type="submit" name="intent" value="focus" class="es-btn es-btn--small es-btn--primary" data-es-focus-save>Сохранить кадр</button>
            <span class="es-hint" data-es-focus-note hidden>Есть другие правки — кадр сохранится вместе с ними кнопкой «Сохранить».</span>
        <?php endif; ?>
    </p>
    <?php if (!empty($v['published'])) : ?><p class="es-hint">Номер уже вышел: читатели увидят новый кадр после повторного одобрения. Отметка проверки текста при смене кадра не снимается.</p><?php endif; ?>
    <p class="es-hint">Мессенджеры обрезают превью ссылки сами, обычно по центру.</p>
    <input type="hidden" name="<?php echo esc_attr($name); ?>[x]" value="<?php echo $x; ?>" data-es-focus-x>
    <input type="hidden" name="<?php echo esc_attr($name); ?>[y]" value="<?php echo $y; ?>" data-es-focus-y>
</div>
