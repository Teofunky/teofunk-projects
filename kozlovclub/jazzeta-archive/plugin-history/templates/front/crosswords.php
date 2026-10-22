<?php
/**
 * «Кроссворды»: every published crossword of the week, newest first (0.5.2).
 * @var array $v
 */
if (!defined('ABSPATH')) {
    exit;
}
$items = $v['crosswords']['items'];
?>
<section class="es-cwlist" aria-labelledby="es-cwlist-title">
    <header class="es-page-head">
        <h1 class="es-page-head__title" id="es-cwlist-title">Кроссворды</h1>
        <p class="es-page-head__intro">Раз в неделю — кроссворд о джазе, музыке и клубе. Ответы открываются через неделю.</p>
    </header>
    <?php if (!$items) : ?>
        <p class="es-searchpage__note">Первый кроссворд готовится.</p>
    <?php else : ?>
        <ol class="es-cwlist__items">
            <?php foreach ($items as $i => $item) : ?>
                <li class="es-cwlist__item<?php echo $i === 0 ? ' es-cwlist__item--fresh' : ''; ?>">
                    <p class="es-cwlist__meta"><?php if ($i === 0) : ?><span class="es-cwlist__fresh">Свежий</span><?php endif; ?><time datetime="<?php echo esc_attr($item['date']); ?>"><?php echo esc_html($item['human_date']); ?></time> · <?php echo esc_html($item['number_label']); ?></p>
                    <h2 class="es-cwlist__title"><a href="<?php echo esc_url($item['url']); ?>"><?php echo esc_html($item['title']); ?></a></h2>
                    <p class="es-cwlist__size"><?php echo esc_html($item['words_label'] . ' · сетка ' . $item['size']); ?></p>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>
</section>
