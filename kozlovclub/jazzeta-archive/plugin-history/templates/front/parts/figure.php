<?php
/**
 * A picture with its credit. Media-library images come with width/height, srcset and sizes;
 * an older plain URL sits in a fixed-ratio frame so the page never jumps. The editorial
 * illustration appears only when an editor chose it as the issue cover.
 * @var array $v ['image' => array, 'role' => 'cover'|'card'|'article', 'eager' => bool, 'sizes' => string, 'link' => string]
 */

use Kozlov\Gazeta\Front\Presenter;
use Kozlov\Gazeta\Front\View;

if (!defined('ABSPATH')) {
    exit;
}
$image = $v['image'];
$role = $v['role'] ?? 'card';
$eager = !empty($v['eager']);
$sizes = $v['sizes'] ?? '(min-width: 1100px) 720px, 100vw';
$link = $v['link'] ?? '';
$kind = $image['kind'] ?? 'photo';
$attrs = [
    'class' => 'es-figure__img',
    'alt' => (string) ($image['alt'] ?? ''),
    'decoding' => 'async',
    'sizes' => $sizes,
    'loading' => $eager ? 'eager' : 'lazy',
];
if ($eager) {
    $attrs['fetchpriority'] = 'high';
}
// Which part of the photo stays in a frame of another shape (0.5.1: the editor's focus point).
$position = $kind === 'photo' ? Presenter::objectPosition($image) : '';
if ($position !== '') {
    $attrs['style'] = 'object-position: ' . $position;
}
$html = '';
if ($kind === 'photo' && !empty($image['attachment_id'])) {
    $html = (string) wp_get_attachment_image((int) $image['attachment_id'], 'large', false, $attrs);
}
?>
<figure class="es-figure es-figure--<?php echo esc_attr($role); ?><?php echo $kind === 'plate' ? ' es-figure--plate' : ''; ?>">
    <?php if ($link !== '') : ?><a class="es-figure__link" href="<?php echo esc_url($link); ?>" tabindex="-1" aria-hidden="true"><?php endif; ?>
    <span class="es-figure__frame">
        <?php if ($kind === 'plate') : ?>
            <?php echo View::svg('plate-' . $image['plate']); // Static asset from tools/build_plates.py. ?>
        <?php elseif ($html !== '') : ?>
            <?php echo $html; // Built by wp_get_attachment_image(), attributes escaped by WordPress. ?>
        <?php else : ?>
            <img class="es-figure__img" src="<?php echo esc_url((string) $image['url']); ?>" alt="<?php echo esc_attr((string) ($image['alt'] ?? '')); ?>" decoding="async"<?php echo $eager ? ' fetchpriority="high"' : ' loading="lazy"'; ?><?php echo $position !== '' ? ' style="object-position: ' . esc_attr($position) . '"' : ' data-es-autofocus'; ?>>
        <?php endif; ?>
    </span>
    <?php if ($link !== '') : ?></a><?php endif; ?>
    <?php if (!empty($image['credit'])) : ?>
        <figcaption class="es-figure__credit"><?php echo esc_html($kind === 'plate' ? 'Иллюстрация редакции · ' . (Presenter::PLATE_NAMES[$image['plate']] ?? '') : $image['credit']); ?></figcaption>
    <?php endif; ?>
</figure>
