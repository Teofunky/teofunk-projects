<?php
/**
 * One story of the issue, the same markup in every reading mode. One primary target
 * (the headline opens the material) and a separate link to the original source.
 * @var array $v ['story' => array, 'cover' => ?array (lead only), 'heading' => 'h2'|'h3']
 */

use Kozlov\Gazeta\Front\View;

if (!defined('ABSPATH')) {
    exit;
}
$story = $v['story'];
$lead = !empty($story['is_lead']);
$picture = $lead ? ($v['cover'] ?? null) : $story['image'];
$why = null;
foreach ($story['brief'] as $block) {
    if ($block['key'] === 'why') {
        $why = $block;
    }
}
$classes = 'es-story' . ($lead ? ' es-story--lead' : '') . ($picture ? ' es-story--pic' : ' es-story--text') . ' es-story--' . $story['placement'];
foreach ($story['grid'] ?? [] as $hint) {
    $classes .= ' es-story--' . $hint;
}
?>
<article class="<?php echo esc_attr($classes); ?>" id="<?php echo esc_attr($story['anchor']); ?>" data-es-region="<?php echo esc_attr($story['region']); ?>" data-es-category="<?php echo esc_attr($story['category']); ?>" aria-labelledby="<?php echo esc_attr($story['anchor']); ?>-title">
    <?php if ($picture) : ?>
        <?php View::render('front/parts/figure', [
            'image' => $picture,
            'role' => $lead ? 'cover' : 'card',
            'eager' => $lead,
            'sizes' => $lead ? '(min-width: 1100px) 760px, 100vw' : '(min-width: 1100px) 360px, (min-width: 700px) 45vw, 100vw',
            'link' => $story['url'],
        ]); ?>
    <?php endif; ?>
    <div class="es-story__text">
        <p class="es-story__kicker">
            <span class="es-story__num" aria-hidden="true"><?php echo esc_html($story['number_label']); ?></span>
            <span class="es-story__rubric"><?php echo esc_html($story['rubric']); ?></span>
            <?php if ($lead) : ?><span class="es-story__flag">Главное</span><?php endif; ?>
        </p>
        <h2 class="es-story__title" id="<?php echo esc_attr($story['anchor']); ?>-title"><a href="<?php echo esc_url($story['url']); ?>"><?php echo esc_html($story['headline']); ?></a></h2>
        <p class="es-story__dek"><?php echo esc_html($story['lead']); ?></p>
        <?php if ($why) : ?>
            <p class="es-story__why"><strong><?php echo esc_html($why['title']); ?>.</strong> <?php echo esc_html($why['text']); ?></p>
        <?php endif; ?>
        <p class="es-story__meta">
            <?php View::render('front/parts/badge', ['levels' => $story['levels']]); ?>
            <?php if ($story['source']) : ?>
                <a class="es-story__source" href="<?php echo esc_url($story['source']['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($story['source']['publisher']); ?><span class="es-sr"> — первоисточник, откроется в новой вкладке</span><span aria-hidden="true"> ↗</span></a>
                <?php if ($story['source_count'] > 1) : ?><span class="es-story__more-src">+<?php echo (int) ($story['source_count'] - 1); ?></span><?php endif; ?>
            <?php endif; ?>
            <span class="es-story__time"><?php echo esc_html($story['minutes'] . ' мин'); ?></span>
            <?php if ($story['event_date_label']) : ?>
                <span class="es-story__event"><?php echo esc_html('Событие: ' . $story['event_date_label']); ?></span>
            <?php endif; ?>
            <?php if ($story['disclosure']) : ?><span class="es-story__disclosure">Связь с издателем</span><?php endif; ?>
        </p>
        <?php if ($lead && !$picture && $story['opening_html'] !== '') : ?>
            <div class="es-story__opening">
                <?php echo $story['opening_html']; // Built from validated, kses-filtered paragraphs. ?>
                <?php if ($story['opening_more']) : ?>
                    <p class="es-story__continue"><a href="<?php echo esc_url($story['url']); ?>">Читать дальше<span class="es-sr">: <?php echo esc_html($story['headline_plain']); ?></span> →</a></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</article>
