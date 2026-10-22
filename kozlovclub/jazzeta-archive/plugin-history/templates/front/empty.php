<?php
/**
 * No published issue yet: a neutral screen, never a draft.
 * @var array $v
 */
if (!defined('ABSPATH')) {
    exit;
}
?>
<section class="es-blank" aria-labelledby="es-blank-title">
    <h1 class="es-blank__title" id="es-blank-title">Первый номер готовится к выходу</h1>
    <p class="es-blank__text">Каждый день — несколько историй о джазе и музыке нашего времени: проверенные факты, источники и спокойная интонация. Номер выходит после того, как его прочитает и утвердит редакция.</p>
    <p class="es-blank__actions">
        <a class="es-cta" href="<?php echo esc_url($v['urls']['club']); ?>">На сайт Клуба Козлова</a>
        <?php if ($v['can_edit']) : ?>
            <a class="es-cta es-cta--quiet" href="<?php echo esc_url($v['urls']['desk']); ?>">Редакция газеты</a>
        <?php endif; ?>
    </p>
</section>
