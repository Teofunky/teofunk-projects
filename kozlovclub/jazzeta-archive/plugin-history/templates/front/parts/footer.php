<?php
/**
 * Colophon: who publishes, how the newspaper works, the way back to the club.
 * @var array $v
 */
if (!defined('ABSPATH')) {
    exit;
}
?>
<footer class="es-colophon">
    <div class="es-colophon__brand">
        <p class="es-colophon__name"><?php echo esc_html($v['brand']); ?>.</p>
        <p class="es-colophon__by">Газета Клуба Алексея Козлова</p>
    </div>
    <div class="es-colophon__principles">
        <div>
            <h2>Главное — за несколько минут</h2>
            <p>Несколько содержательных историй в день. Номер заканчивается — ленты без конца нет.</p>
        </div>
        <div>
            <h2>Сначала источник</h2>
            <p>Даты, имена и цифры сверяются с источниками. Оценки редакции отделены от фактов.</p>
        </div>
    </div>
    <nav class="es-colophon__links" aria-label="Газета и клуб">
        <a href="<?php echo esc_url($v['urls']['home']); ?>">Свежий номер</a>
        <a href="<?php echo esc_url($v['urls']['archive']); ?>">Все выпуски</a>
        <a class="es-colophon__club" href="<?php echo esc_url($v['urls']['club']); ?>">← На сайт Клуба Козлова</a>
    </nav>
</footer>
