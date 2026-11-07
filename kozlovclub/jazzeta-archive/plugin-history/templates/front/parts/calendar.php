<?php
/**
 * 0.5.11: «Этот день в джазе» — anniversaries of the issue's date, under the stories.
 * Plain list in every reading mode; the columns of «Газета» and «Современный» are CSS only.
 * @var array $v ['calendar' => Presenter::calendar()]
 */

if (!defined('ABSPATH')) {
    exit;
}
$calendar = $v['calendar'];
?>
<section class="es-calendar" aria-labelledby="es-calendar-title">
    <h2 class="es-calendar__title" id="es-calendar-title"><?php echo esc_html($calendar['title']); ?> <span class="es-calendar__date">· <?php echo esc_html($calendar['date_label']); ?></span></h2>
    <ol class="es-calendar__list">
        <?php foreach ($calendar['items'] as $item) : ?>
            <li class="es-calendar__item<?php echo $item['round'] ? ' es-calendar__item--round' : ''; ?><?php echo $item['club'] ? ' es-calendar__item--club' : ''; ?>">
                <span class="es-calendar__year"><?php echo (int) $item['year']; ?></span>
                <span class="es-calendar__body">
                    <span class="es-calendar__label"><?php echo esc_html($item['label']); ?></span>
                    <span class="es-calendar__name"><?php echo esc_html($item['name']); ?></span>
                    <?php if ($item['occupation'] !== '') : ?>
                        <span class="es-calendar__occ"><?php echo esc_html($item['occupation']); ?></span>
                    <?php endif; ?>
                </span>
            </li>
        <?php endforeach; ?>
    </ol>
</section>
