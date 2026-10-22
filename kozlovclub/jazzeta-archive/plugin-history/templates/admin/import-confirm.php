<?php
/**
 * Owner: readable diff and explicit consent before replacing a draft.
 * @var array $v
 */

use Kozlov\Gazeta\Admin\Actions;
use Kozlov\Gazeta\Admin\Admin;

if (!defined('ABSPATH')) {
    exit;
}
$row = $v['row'];
$id = (int) $row['id'];
$version = (int) $row['version'];
$kinds = ['added' => ['Будет добавлен', 'ok'], 'changed' => ['Будет изменён', 'warn'], 'removed' => ['Будет убран', 'bad'], 'same' => ['Без изменений', 'neutral']];
$diffTags = ['del' => [], 'ins' => []];
?>
<div class="es-summary-row">
    <?php foreach ($kinds as $kind => [$label, $tone]) : ?>
        <div class="es-summary es-summary--<?php echo esc_attr($tone); ?>"><strong><?php echo (int) ($v['counts'][$kind] ?? 0); ?></strong><span><?php echo esc_html($label); ?></span></div>
    <?php endforeach; ?>
    <div class="es-summary"><strong><?php echo (int) $v['social_before']; ?> → <?php echo (int) $v['social_after']; ?></strong><span>соцверсий</span></div>
</div>

<ol class="es-changes">
    <?php foreach ($v['changes'] as $change) : [$label, $tone] = $kinds[$change['kind']]; ?>
        <li class="es-card es-change es-change--<?php echo esc_attr($change['kind']); ?>">
            <div class="es-change__head">
                <span class="es-chip es-chip--<?php echo esc_attr($tone); ?>"><?php echo esc_html($label); ?></span>
                <code><?php echo esc_html($change['id']); ?></code>
                <?php if ($change['fields']) : ?><span class="es-muted">меняется: <?php echo esc_html(implode(', ', $change['fields'])); ?></span><?php endif; ?>
            </div>
            <?php if ($change['kind'] === 'changed') : ?>
                <p class="es-diff es-diff--headline"><?php echo wp_kses($change['diff_headline'], $diffTags); ?></p>
                <p class="es-diff"><?php echo wp_kses($change['diff_lead'], $diffTags); ?></p>
                <details class="es-details es-details--quiet"><summary>Изменения в тексте</summary><p class="es-diff"><?php echo wp_kses($change['diff_body'], $diffTags); ?></p></details>
            <?php else : ?>
                <p class="es-change__title"><?php echo esc_html($change['headline']); ?></p>
            <?php endif; ?>
        </li>
    <?php endforeach; ?>
</ol>

<details class="es-card es-details">
    <summary>Полный JSON, который будет записан</summary>
    <pre class="es-code es-code--block"><?php echo esc_html($v['pretty']); ?></pre>
</details>

<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="es-card es-confirm">
    <?php Actions::fields('manual_import', $id, $version); ?>
    <input type="hidden" name="payload_nonce" value="<?php echo esc_attr($v['payload_nonce']); ?>">
    <textarea name="raw" hidden><?php echo esc_textarea($v['raw']); ?></textarea>
    <label class="es-check">
        <input type="checkbox" name="confirm" value="1" required>
        <span>Я проверил изменения и заменяю весь этот черновик, включая прежние ручные правки. Текущая версия сохранится в истории как «Резервная копия перед ручным импортом».</span>
    </label>
    <div class="es-actions">
        <button type="submit" class="es-btn es-btn--primary">Заменить черновик</button>
        <a class="es-btn" href="<?php echo esc_url(Admin::url($id)); ?>">Отмена</a>
    </div>
</form>
