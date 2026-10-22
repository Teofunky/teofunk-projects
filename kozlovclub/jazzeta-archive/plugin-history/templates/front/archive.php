<?php
/**
 * «Все выпуски»: every published issue by month, newest first, with its lead headline.
 * @var array $v
 */

use Kozlov\Gazeta\Domain\Labels;

if (!defined('ABSPATH')) {
    exit;
}
$archive = $v['archive'];
?>
<section class="es-archive" aria-labelledby="es-archive-title">
    <header class="es-page-head">
        <h1 class="es-page-head__title" id="es-archive-title">Все выпуски</h1>
        <p class="es-page-head__intro">
            <?php if ($archive['total']) : ?>
                <?php echo esc_html(Labels::count((int) $archive['total'], 'выпуск', 'выпуска', 'выпусков')); ?>. Каждый остаётся по своему адресу — с источниками и датами.
            <?php else : ?>
                Первый выпуск ещё не опубликован.
            <?php endif; ?>
        </p>
    </header>

    <?php foreach ($archive['groups'] as $month => $rows) : ?>
        <section class="es-month" aria-labelledby="es-month-<?php echo esc_attr(md5($month)); ?>">
            <h2 class="es-month__title" id="es-month-<?php echo esc_attr(md5($month)); ?>"><?php echo esc_html($month); ?></h2>
            <ol class="es-issues">
                <?php foreach ($rows as $row) : ?>
                    <li class="es-issue-row">
                        <p class="es-issue-row__meta">
                            <span class="es-issue-row__no"><?php echo esc_html($row['number_label']); ?></span>
                            <time datetime="<?php echo esc_attr($row['edition_date']); ?>"><?php echo esc_html($row['weekday'] . ', ' . $row['human_date']); ?></time>
                            <?php if ($row['count_label'] !== '') : ?><span><?php echo esc_html($row['count_label']); ?></span><?php endif; ?>
                        </p>
                        <h3 class="es-issue-row__lead"><a href="<?php echo esc_url($row['url']); ?>"><?php echo esc_html($row['lead_headline'] !== '' ? $row['lead_headline'] : 'Выпуск ' . $row['number_label']); ?></a></h3>
                        <?php if (!empty($row['headlines'])) : ?>
                            <ul class="es-issue-row__more">
                                <?php foreach (array_slice($row['headlines'], 0, 3) as $headline) : ?>
                                    <li><?php echo esc_html($headline); ?></li>
                                <?php endforeach; ?>
                                <?php if (count($row['headlines']) > 3) : ?><li class="es-issue-row__rest">и ещё <?php echo esc_html(Labels::count(count($row['headlines']) - 3, 'материал', 'материала', 'материалов')); ?></li><?php endif; ?>
                            </ul>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ol>
        </section>
    <?php endforeach; ?>

    <?php if ($archive['pages'] > 1) : ?>
        <nav class="es-pagination" aria-label="Страницы списка выпусков">
            <?php if ($archive['prev']) : ?><a rel="prev" href="<?php echo esc_url($archive['prev']); ?>">← Новее</a><?php else : ?><span></span><?php endif; ?>
            <span class="es-pagination__state">Страница <?php echo (int) $archive['page']; ?> из <?php echo (int) $archive['pages']; ?></span>
            <?php if ($archive['next']) : ?><a rel="next" href="<?php echo esc_url($archive['next']); ?>">Старше →</a><?php else : ?><span></span><?php endif; ?>
        </nav>
    <?php endif; ?>
</section>
