<?php
/**
 * Front page of an issue. The order of the markup is the reading order on a phone in every
 * mode; the desktop compositions of «Газета», «Современный» and «Кратко» are pure CSS grids.
 * @var array $v
 */

use Kozlov\Gazeta\Domain\Labels;
use Kozlov\Gazeta\Front\View;

if (!defined('ABSPATH')) {
    exit;
}
$issue = $v['issue'];
[$older, $newer] = $v['neighbours'];
$stories = $issue['stories'];
?>
<?php if (empty($issue['is_latest']) && !$v['preview']) : ?>
    <p class="es-notice">Это номер из подшивки. <a href="<?php echo esc_url($v['urls']['home']); ?>">Свежий номер →</a></p>
<?php endif; ?>

<div class="es-issue" data-es-count="<?php echo (int) $issue['count']; ?>">
    <?php if (count($issue['topics']) > 1 || $issue['count'] > 3) : ?>
        <nav class="es-rubrics" aria-label="Рубрики и темы номера">
            <div class="es-rubrics__scroll">
                <span class="es-rubrics__label">В номере:</span>
                <ul>
                    <?php
                    // 0.5.5: rubrics, then tags. Without JavaScript each chip leads to its first material;
                    // with it, the chip shows only the materials of that rubric or tag.
                    $firstTag = true;
                    foreach ($issue['topics'] as $topic) :
                        $class = 'es-rubrics__item es-rubrics__item--' . $topic['kind'];
                        if ($topic['kind'] === 'tag' && $firstTag) {
                            $class .= ' es-rubrics__item--first-tag';
                            $firstTag = false;
                        }
                        ?>
                        <li class="<?php echo esc_attr($class); ?>"><a href="#<?php echo esc_attr($topic['anchor']); ?>" data-es-topic="<?php echo esc_attr($topic['key']); ?>"><?php echo esc_html($topic['label']); ?><?php if ($topic['count'] > 1) : ?> <span class="es-rubrics__count"><?php echo (int) $topic['count']; ?></span><?php endif; ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php if ($issue['regions']['russia'] && ($issue['regions']['world'] || $issue['regions']['mixed'])) : ?>
                <div class="es-filter" role="group" aria-label="Показать материалы" hidden data-es-filters>
                    <button type="button" data-es-filter="" aria-pressed="true">Все</button>
                    <button type="button" data-es-filter="russia" aria-pressed="false">Россия</button>
                    <button type="button" data-es-filter="world" aria-pressed="false">Мир</button>
                </div>
            <?php endif; ?>
        </nav>
        <p class="es-filter-empty" hidden data-es-filter-empty>В этом номере таких материалов нет. <button type="button" data-es-filter-reset>Показать все</button></p>
        <p class="es-sr" role="status" data-es-filter-status></p>
    <?php endif; ?>

    <div class="es-flow">
        <?php foreach ($stories as $index => $story) : ?>
            <?php View::render('front/parts/story', ['story' => $story, 'cover' => $index === 0 ? $issue['cover'] : null]); ?>
            <?php if ($index === 0 && $issue['count'] > 2) : ?>
                <aside class="es-toc" aria-labelledby="es-toc-title">
                    <h2 class="es-toc__title" id="es-toc-title">Коротко о номере</h2>
                    <ol class="es-toc__list">
                        <?php foreach ($stories as $item) : ?>
                            <li>
                                <a href="#<?php echo esc_attr($item['anchor']); ?>">
                                    <span class="es-toc__num" aria-hidden="true"><?php echo esc_html($item['number_label']); ?></span>
                                    <span class="es-toc__head"><?php echo esc_html($item['headline']); ?></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                    <p class="es-toc__foot"><?php echo esc_html($issue['count_label'] . ' · ≈ ' . $issue['minutes'] . ' мин чтения'); ?></p>
                </aside>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <?php if (!empty($issue['crossword'])) : ?>
        <?php View::render('front/parts/crossword-teaser', ['teaser' => $issue['crossword']]); ?>
    <?php endif; ?>

    <p class="es-issue__end"><span>Это весь номер<?php echo $issue['number'] ? ' ' . esc_html($issue['number_label']) : ''; ?></span></p>

    <?php if (count($issue['digest']) >= 3) : ?>
        <?php View::render('front/parts/digest', $v); ?>
    <?php endif; ?>

    <?php if (!$v['preview'] && ($older || $newer)) : ?>
        <nav class="es-issue-nav" aria-label="Соседние выпуски">
            <?php if ($older) : ?>
                <a class="es-issue-nav__link" rel="prev" href="<?php echo esc_url(\Kozlov\Gazeta\Http\Router::issue($older['edition_date'])); ?>">
                    <span class="es-issue-nav__dir">← Предыдущий</span>
                    <span class="es-issue-nav__label"><?php echo esc_html(Labels::issueNumber($older['number']) . ' · ' . Labels::date($older['edition_date'])); ?></span>
                </a>
            <?php endif; ?>
            <a class="es-issue-nav__all" href="<?php echo esc_url($v['urls']['archive']); ?>">Все выпуски</a>
            <?php if ($newer) : ?>
                <a class="es-issue-nav__link es-issue-nav__link--next" rel="next" href="<?php echo esc_url(\Kozlov\Gazeta\Http\Router::issue($newer['edition_date'])); ?>">
                    <span class="es-issue-nav__dir">Следующий →</span>
                    <span class="es-issue-nav__label"><?php echo esc_html(Labels::issueNumber($newer['number']) . ' · ' . Labels::date($newer['edition_date'])); ?></span>
                </a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
</div>
