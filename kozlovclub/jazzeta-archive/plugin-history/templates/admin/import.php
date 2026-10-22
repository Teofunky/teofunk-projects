<?php
/**
 * Ordinary import + REST instructions.
 * @var array $v
 */

use Kozlov\Gazeta\Admin\Actions;

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="es-layout es-layout--issue">
    <div class="es-main-col">
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="es-card" data-es-json-form>
            <?php Actions::fields('import'); ?>
            <div class="es-card__head">
                <h2 class="es-card__title">Загрузить черновик</h2>
                <p class="es-muted">Создаёт выпуск на дату из JSON или обновляет черновик, который ещё не правил человек. Опубликованное и отредактированное не перезаписывается.</p>
            </div>
            <div class="es-drop" data-es-drop>
                <input type="file" accept="application/json,.json" id="es-json-file" data-es-file>
                <label for="es-json-file"><strong>Выберите файл</strong> или перетащите его сюда</label>
                <span class="es-muted">или вставьте JSON в поле ниже</span>
            </div>
            <div class="es-field">
                <label for="es-json">JSON выпуска</label>
                <textarea id="es-json" name="raw" rows="14" class="es-code" spellcheck="false" required data-es-json></textarea>
                <p class="es-json-status" data-es-json-status aria-live="polite"></p>
            </div>
            <div class="es-field es-field--narrow">
                <label for="es-expected">Текущая версия <span class="es-hint">только для обновления черновика (как If-Match)</span></label>
                <input id="es-expected" type="number" min="0" name="expected" inputmode="numeric">
            </div>
            <div class="es-actions">
                <button type="submit" class="es-btn es-btn--primary">Проверить и импортировать</button>
            </div>
        </form>
    </div>
    <aside class="es-side-col">
        <section class="es-card">
            <h2 class="es-card__title">Для сборщика (REST)</h2>
            <dl class="es-dl es-dl--stack">
                <div><dt>Адрес</dt><dd><code class="es-copyable" data-es-copy><?php echo esc_html($v['endpoint']); ?></code></dd></div>
                <div><dt>Метод</dt><dd><code>POST</code>, <code>Content-Type: application/json</code></dd></div>
                <div><dt>Доступ</dt><dd>отдельный пользователь с ролью «Газета — импортёр» и пароль приложения; не учётная запись владельца</dd></div>
                <div><dt>Обновление</dt><dd>заголовок <code>If-Match: "версия"</code>; без него — 428, устаревшая — 409</dd></div>
                <div><dt>Ограничения</dt><dd>256 КиБ, 10 запросов в минуту, только черновики; публикации через API нет</dd></div>
            </dl>
            <p><a class="es-link" href="<?php echo esc_url($v['schema']); ?>" target="_blank" rel="noopener">Схема JSON 1.1 ↗</a></p>
        </section>
        <section class="es-card">
            <h2 class="es-card__title">Заменить отредактированный черновик</h2>
            <p class="es-muted">Обычный импорт не трогает ручные правки. Чтобы полностью заменить черновик, владелец открывает выпуск и выбирает «Заменить черновик из JSON…» — с просмотром изменений и резервной копией.</p>
        </section>
    </aside>
</div>
