<?php
/**
 * Owner: paste JSON to replace a never-published draft.
 * @var array $v
 */

use Kozlov\Gazeta\Admin\Actions;
use Kozlov\Gazeta\Domain\Labels;

if (!defined('ABSPATH')) {
    exit;
}
$row = $v['row'];
$id = (int) $row['id'];
?>
<form method="post" action="<?php echo esc_url(add_query_arg(['page' => 'es-import', 'issue' => $id], admin_url('admin.php'))); ?>" class="es-card es-narrow" data-es-json-form>
    <input type="hidden" name="operation" value="manual_preview">
    <input type="hidden" name="issue_id" value="<?php echo $id; ?>">
    <input type="hidden" name="version" value="<?php echo (int) $row['version']; ?>">
    <?php wp_nonce_field(Actions::nonceAction('manual_preview', $id, (int) $row['version'])); ?>
    <div class="es-alert es-alert--warn">
        Заменится <strong>весь черновик <?php echo esc_html(Labels::date($row['edition_date'])); ?></strong>
        (сейчас <?php echo esc_html(Labels::count((int) $v['articles'], 'материал', 'материала', 'материалов')); ?>, версия <?php echo (int) $row['version']; ?>), включая ручные правки, соцверсии и заметки.
        Перед заменой сохранится резервная версия; отметки проверки снимутся. Публикации не будет.
    </div>
    <div class="es-drop" data-es-drop>
        <input type="file" accept="application/json,.json" id="es-json-file" data-es-file>
        <label for="es-json-file"><strong>Выберите файл</strong> или перетащите его сюда</label>
    </div>
    <div class="es-field">
        <label for="es-json">JSON для замены <span class="es-hint">дата в JSON должна быть <?php echo esc_html($row['edition_date']); ?></span></label>
        <textarea id="es-json" name="raw" rows="14" class="es-code" spellcheck="false" required data-es-json data-es-expect-date="<?php echo esc_attr($row['edition_date']); ?>"></textarea>
        <p class="es-json-status" data-es-json-status aria-live="polite"></p>
    </div>
    <div class="es-actions">
        <button type="submit" class="es-btn es-btn--primary">Проверить и показать изменения</button>
    </div>
</form>
