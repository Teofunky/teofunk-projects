<?php
/**
 * «Кроссворд недели» on the issue page (0.5.2).
 * @var array $v ['teaser' => array from Presenter::crosswordTeaser()]
 */
if (!defined('ABSPATH')) {
    exit;
}
$t = $v['teaser'];
?>
<section class="es-cwteaser" id="es-crossword" aria-labelledby="es-cwteaser-title">
    <div class="es-cwteaser__mark" aria-hidden="true">
        <svg viewBox="0 0 40 40" focusable="false"><path d="M2 2h12v12H2zM14 2h12v12H14zM26 14h12v12H26zM14 14h12v12H14zM14 26h12v12H14z" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M26 2h12v12H26zM2 14h12v12H2zM2 26h12v12H2zM26 26h12v12H26z" fill="currentColor"/></svg>
    </div>
    <div class="es-cwteaser__body">
        <p class="es-cwteaser__kicker">Кроссворд недели</p>
        <h2 class="es-cwteaser__title" id="es-cwteaser-title"><a href="<?php echo esc_url($t['url']); ?>"><?php echo esc_html($t['title']); ?></a></h2>
        <?php if ($t['intro'] !== '') : ?><p class="es-cwteaser__intro"><?php echo esc_html($t['intro']); ?></p><?php endif; ?>
        <p class="es-cwteaser__meta"><?php echo esc_html($t['words_label'] . ' · сетка ' . $t['size'] . ' · ответы — через неделю'); ?></p>
        <p class="es-cwteaser__go"><a class="es-cta" href="<?php echo esc_url($t['url']); ?>">Решать кроссворд</a></p>
    </div>
</section>
