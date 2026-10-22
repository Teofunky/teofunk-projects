<?php
/**
 * Thin status strip: preview or closed test. Readers of an open newspaper never see it.
 * @var array $v
 */
if (!defined('ABSPATH')) {
    exit;
}
if ($v['preview']) : ?>
    <div class="es-status es-status--preview" role="status"><div class="es-status__inner">
        <span class="es-status__dot" aria-hidden="true"></span>
        <strong>Предпросмотр</strong>
        <span>рабочая версия <?php echo (int) ($v['issue']['version'] ?? 0); ?> · <?php echo esc_html($v['labels']['state'] ?: 'черновик'); ?> · читатели этого не видят</span>
        <a class="es-status__action" href="<?php echo esc_url($v['urls']['desk_issue'] ?? $v['urls']['desk']); ?>">Вернуться в редакцию</a>
    </div></div>
<?php elseif ($v['private']) : ?>
    <div class="es-status es-status--closed" role="status"><div class="es-status__inner">
        <span class="es-status__dot" aria-hidden="true"></span>
        <strong><?php echo esc_html($v['labels']['mode']); ?></strong>
        <span>газету видит только редакция. Утверждённые выпуски откроются читателям отдельным решением владельца.</span>
        <?php if ($v['is_owner']) : ?>
            <a class="es-status__action" href="<?php echo esc_url($v['urls']['mode']); ?>">Режим газеты</a>
        <?php endif; ?>
    </div></div>
<?php endif; ?>
