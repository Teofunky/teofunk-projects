<?php
/**
 * Issue screen.
 * @var array $v
 */

use Kozlov\Gazeta\Admin\Actions;
use Kozlov\Gazeta\Admin\Admin;
use Kozlov\Gazeta\Admin\Ui;
use Kozlov\Gazeta\Domain\Labels;
use Kozlov\Gazeta\Front\View;
use Kozlov\Gazeta\Runtime\Mode;

if (!defined('ABSPATH')) {
    exit;
}
$row = $v['row'];
$id = (int) $row['id'];
$version = (int) $row['version'];
$editorial = $v['doc']['editorial'];
$s = $v['summary'];
$localTime = static function (string $iso): string {
    try {
        return (new \DateTimeImmutable($iso))->setTimezone(new \DateTimeZone('Europe/Moscow'))->format('Y-m-d\TH:i');
    } catch (\Exception $e) {
        return '';
    }
};
$placementTone = ['lead' => 'lead', 'feature' => 'feature', 'regular' => 'neutral', 'brief' => 'quiet'];
?>
<div class="es-layout es-layout--issue">
    <div class="es-main-col">
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="es-card es-assembly" data-es-assembly data-es-dirty-guard>
            <?php Actions::fields('issue', $id, $version); ?>
            <div class="es-card__head">
                <h2 class="es-card__title">Материалы номера</h2>
                <p class="es-muted"><?php echo esc_html(Labels::count(count($v['articles']), 'материал', 'материала', 'материалов')); ?> · соцверсий <?php echo (int) $v['social_count']; ?> из 5 · порядок можно менять перетаскиванием</p>
            </div>

            <?php if ($v['articles']) : ?>
                <ol class="es-stories" data-es-sortable>
                    <?php foreach ($v['articles'] as $index => $a) : ?>
                        <li class="es-story" data-id="<?php echo esc_attr($a['id']); ?>" draggable="true">
                            <span class="es-story__handle" aria-hidden="true" title="Перетащите, чтобы изменить порядок">⋮⋮</span>
                            <span class="es-story__pos"><?php echo esc_html(str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT)); ?></span>
                            <div class="es-story__body">
                                <div class="es-story__meta">
                                    <?php echo Ui::chip(Labels::PLACEMENTS[$a['placement']] ?? $a['placement'], $placementTone[$a['placement']] ?? 'neutral'); ?>
                                    <span class="es-muted"><?php echo esc_html($a['rubric']); ?></span>
                                    <?php if ($a['disclosure']) : ?><?php echo Ui::chip('связь с издателем', 'accent'); ?><?php endif; ?>
                                </div>
                                <a class="es-story__title" href="<?php echo esc_url($a['edit']); ?>"><?php echo esc_html($a['headline']); ?></a>
                                <div class="es-story__facts">
                                    <span><?php echo esc_html($a['words'] . ' слов · ' . $a['minutes'] . ' мин'); ?></span>
                                    <span><?php echo esc_html(Labels::count($a['sources'], 'источник', 'источника', 'источников')); ?></span>
                                    <span class="es-levels">
                                        <?php foreach ($a['levels'] as $level) : ?>
                                            <span class="es-level es-level--<?php echo esc_attr($level); ?>" title="<?php echo esc_attr(Labels::LEVELS[$level] ?? $level); ?>"><?php echo esc_html(Labels::LEVELS_SHORT[$level] ?? $level); ?></span>
                                        <?php endforeach; ?>
                                    </span>
                                    <span class="<?php echo $a['social'] ? '' : 'es-muted'; ?>"><?php echo $a['social'] ? 'есть соцверсия' : 'без соцверсии'; ?></span>
                                    <span class="es-muted">значимость <?php echo esc_html((string) $a['score']); ?></span>
                                </div>
                            </div>
                            <div class="es-story__status">
                                <?php if ($a['reviewed']) : ?>
                                    <span class="es-review es-review--ok" title="<?php echo esc_attr($a['reviewer'] . ', ' . Ui::when($a['reviewed_at'])); ?>">✓ Проверено</span>
                                <?php else : ?>
                                    <span class="es-review es-review--todo">Нужна проверка</span>
                                <?php endif; ?>
                                <?php if ($a['blockers']) : ?><span class="es-count es-count--bad" title="Препятствия для подтверждения"><?php echo (int) $a['blockers']; ?> <?php echo esc_html(Labels::plural((int) $a['blockers'], 'замечание', 'замечания', 'замечаний')); ?></span><?php endif; ?>
                                <?php if ($a['matches']) : ?><span class="es-count es-count--warn" title="Совпадения с опубликованным архивом">повтор?</span><?php endif; ?>
                                <span class="es-story__links">
                                    <a href="<?php echo esc_url($a['edit']); ?>">Править</a>
                                    <a href="<?php echo esc_url($a['preview']); ?>" target="_blank" rel="noopener">Смотреть ↗</a>
                                </span>
                                <span class="es-story__move">
                                    <button type="button" class="es-icon-btn" data-es-move="-1" aria-label="Поднять «<?php echo esc_attr($a['headline']); ?>»">↑</button>
                                    <button type="button" class="es-icon-btn" data-es-move="1" aria-label="Опустить «<?php echo esc_attr($a['headline']); ?>»">↓</button>
                                </span>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php else : ?>
                <div class="es-empty es-empty--big">
                    <p>В выпуске пока нет материалов.</p>
                    <p><a class="es-btn es-btn--primary" href="<?php echo esc_url(Admin::url($id, 'new')); ?>">Добавить первый материал</a>
                    <?php if (current_user_can('es_import')) : ?> <a class="es-btn" href="<?php echo esc_url(admin_url('admin.php?page=es-import')); ?>">Импортировать JSON</a><?php endif; ?></p>
                </div>
            <?php endif; ?>
            <input type="hidden" name="order" value="<?php echo esc_attr(implode(',', array_column($v['articles'], 'id'))); ?>" data-es-order>

            <?php
            $cover = $v['cover'];
            $coverKind = $cover['kind'] ?? 'lead';
            $coverThumb = !empty($cover['attachment_id']) ? (string) wp_get_attachment_image_url((int) $cover['attachment_id'], 'medium') : '';
            ?>
            <fieldset class="es-cover" data-es-cover>
                <legend>Обложка номера</legend>
                <p class="es-muted">Главная картинка первой полосы и превью в мессенджерах. Одна и та же картинка не повторяется сама: каждый номер — своё решение.</p>
                <div class="es-cover__choices">
                    <label class="es-check"><input type="radio" name="cover_kind" value="lead"<?php checked($coverKind, 'lead'); ?>> <span>Фото главного материала<?php echo $v['lead_photo'] === '' ? ' <span class="es-muted">(у главного материала фото нет — номер выйдет без картинки)</span>' : ''; ?></span></label>
                    <label class="es-check"><input type="radio" name="cover_kind" value="photo"<?php checked($coverKind, 'photo'); ?>> <span>Своё фото из медиатеки</span></label>
                    <label class="es-check"><input type="radio" name="cover_kind" value="plate"<?php checked($coverKind, 'plate'); ?>> <span>Иллюстрация редакции</span></label>
                    <label class="es-check"><input type="radio" name="cover_kind" value="none"<?php checked($coverKind, 'none'); ?>> <span>Без картинки</span></label>
                </div>
                <div class="es-cover__photo" data-es-cover-for="photo">
                    <div class="es-cover__thumb" data-es-cover-thumb><?php if ($coverThumb) : ?><img src="<?php echo esc_url($coverThumb); ?>" alt=""><?php else : ?><span>Фото не выбрано</span><?php endif; ?></div>
                    <div class="es-cover__fields">
                        <input type="hidden" name="cover_attachment" value="<?php echo (int) ($cover['attachment_id'] ?? 0); ?>" data-es-cover-id>
                        <p><button type="button" class="es-btn es-btn--small" data-es-cover-pick>Выбрать в медиатеке</button></p>
                        <div class="es-field">
                            <label for="es-cover-alt">Описание для незрячих (alt)</label>
                            <input id="es-cover-alt" type="text" name="cover_alt" maxlength="300" value="<?php echo esc_attr($cover['alt'] ?? ''); ?>" data-es-cover-alt>
                        </div>
                        <div class="es-field">
                            <label for="es-cover-credit">Автор и лицензия <span class="es-hint">обязательно; без подтверждённых прав фото не ставим</span></label>
                            <input id="es-cover-credit" type="text" name="cover_credit" maxlength="300" value="<?php echo esc_attr($cover['credit'] ?? ''); ?>" data-es-cover-credit>
                        </div>
                    </div>
                </div>
                <div data-es-cover-for="photo">
                    <?php View::render('admin/parts/focus', ['id' => 'cover', 'name' => 'cover_focus', 'src' => !empty($cover['attachment_id']) ? (string) wp_get_attachment_image_url((int) $cover['attachment_id'], 'large') : '', 'focus' => $cover['focus'] ?? null, 'save' => false, 'published' => (int) ($v['row']['public_version'] ?? 0) > 0]); ?>
                </div>
                <p class="es-hint" data-es-cover-for="lead">Кадр фото главного материала задаётся в его карточке, в блоке «Иллюстрация».</p>
                <div class="es-field es-cover__plate" data-es-cover-for="plate">
                    <label for="es-cover-plate">Иллюстрация</label>
                    <select id="es-cover-plate" name="cover_plate">
                        <?php foreach ($v['plates'] as $key => $label) : ?><option value="<?php echo esc_attr($key); ?>"<?php selected($cover['plate'] ?? '', $key); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?>
                    </select>
                </div>
            </fieldset>

            <details class="es-details es-editorial"<?php echo $editorial['blocking_issues'] || !$editorial['ready_for_human_review'] ? ' open' : ''; ?>>
                <summary>Сборка номера и редакционные пометки</summary>
                <label class="es-switch">
                    <input type="checkbox" name="ready" value="1"<?php checked(!empty($editorial['ready_for_human_review'])); ?>>
                    <span class="es-switch__ui" aria-hidden="true"></span>
                    <span><strong>Номер готов к итоговой проверке владельцем</strong><br><span class="es-muted">Ставьте, когда состав окончательный и блокеры сняты.</span></span>
                </label>
                <div class="es-field">
                    <label for="es-blocking">Блокеры выпуска <span class="es-muted">— каждый с новой строки; пока они есть, публикация невозможна</span></label>
                    <textarea id="es-blocking" name="blocking_issues" rows="3" data-es-autogrow><?php echo esc_textarea(implode("\n", $editorial['blocking_issues'])); ?></textarea>
                </div>
                <div class="es-field">
                    <label for="es-notes">Приватные заметки номера <span class="es-muted">— читатели их не видят</span></label>
                    <textarea id="es-notes" name="generation_notes" rows="3" data-es-autogrow><?php echo esc_textarea(implode("\n", $editorial['generation_notes'])); ?></textarea>
                </div>
                <div class="es-field-row">
                    <div class="es-field">
                        <label for="es-scan-start">Источники смотрели с <span class="es-muted">(МСК)</span></label>
                        <input id="es-scan-start" type="datetime-local" name="scan_start" value="<?php echo esc_attr($localTime($editorial['source_scan_window_start'])); ?>">
                    </div>
                    <div class="es-field">
                        <label for="es-scan-end">по</label>
                        <input id="es-scan-end" type="datetime-local" name="scan_end" value="<?php echo esc_attr($localTime($editorial['source_scan_window_end'])); ?>">
                    </div>
                </div>
            </details>

            <div class="es-savebar" data-es-savebar>
                <span class="es-savebar__state" data-es-dirty-label>Изменений нет</span>
                <button type="submit" class="es-btn es-btn--primary">Сохранить сборку</button>
            </div>
        </form>

        <section class="es-card es-history" id="history" aria-labelledby="es-history-title">
            <div class="es-card__head">
                <h2 class="es-card__title" id="es-history-title">История версий</h2>
                <p class="es-muted">Каждое сохранение — отдельная версия. Восстановление копирует её в рабочий черновик; опубликованное не меняется.</p>
            </div>
            <?php if ($v['revision']) : $rev = $v['revision']; ?>
                <div class="es-snapshot">
                    <div class="es-snapshot__head">
                        <strong>Версия <?php echo (int) $rev['row']['version']; ?> · <?php echo esc_html(Labels::HISTORY_ACTIONS[$rev['row']['action']] ?? $rev['row']['action']); ?></strong>
                        <span class="es-muted"><?php echo esc_html(Ui::when($rev['row']['created_at']) . ' · ' . Ui::userName((int) $rev['row']['actor_id'])); ?></span>
                    </div>
                    <ol class="es-snapshot__list">
                        <?php foreach ($rev['headlines'] as $headline) : ?><li><?php echo esc_html($headline); ?></li><?php endforeach; ?>
                    </ol>
                    <p class="es-muted">Проверено в этой версии: <?php echo (int) $rev['reviewed']; ?> · отметка готовности: <?php echo $rev['ready'] ? 'да' : 'нет'; ?></p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="es-inline-form" data-es-confirm="Перенести эту версию в рабочий черновик? Текущие правки останутся в истории.">
                        <?php Actions::fields('restore', $id, $version); ?>
                        <input type="hidden" name="revision_id" value="<?php echo (int) $rev['row']['id']; ?>">
                        <button class="es-btn" type="submit">Вернуть в рабочий черновик</button>
                        <a class="es-link" href="<?php echo esc_url(Admin::url($id) . '#history'); ?>">Закрыть</a>
                    </form>
                </div>
            <?php endif; ?>
            <ol class="es-timeline">
                <?php foreach ($v['history']['items'] as $item) : ?>
                    <li class="es-timeline__item es-timeline__item--<?php echo esc_attr($item['action']); ?>">
                        <span class="es-timeline__v">v<?php echo (int) $item['version']; ?></span>
                        <span class="es-timeline__what"><?php echo esc_html(Labels::HISTORY_ACTIONS[$item['action']] ?? $item['action']); ?></span>
                        <span class="es-timeline__who"><?php echo esc_html(Ui::userName((int) $item['actor_id']) . ' · ' . Ui::when($item['created_at'])); ?></span>
                        <a class="es-link" href="<?php echo esc_url(add_query_arg('revision', (int) $item['id'], Admin::url($id)) . '#history'); ?>">Посмотреть</a>
                    </li>
                <?php endforeach; ?>
            </ol>
            <?php if ($v['history']['pages'] > 1) : ?>
                <nav class="es-pager-admin">
                    <?php if ($v['history_page'] > 1) : ?><a class="es-btn es-btn--small" href="<?php echo esc_url(add_query_arg('history_page', $v['history_page'] - 1, Admin::url($id)) . '#history'); ?>">← Новее</a><?php endif; ?>
                    <span><?php echo (int) $v['history_page']; ?> из <?php echo (int) $v['history']['pages']; ?></span>
                    <?php if ($v['history_page'] < $v['history']['pages']) : ?><a class="es-btn es-btn--small" href="<?php echo esc_url(add_query_arg('history_page', $v['history_page'] + 1, Admin::url($id)) . '#history'); ?>">Старше →</a><?php endif; ?>
                </nav>
            <?php endif; ?>
        </section>
    </div>

    <aside class="es-side-col">
        <section class="es-card es-readiness" aria-labelledby="es-ready-title">
            <div class="es-ring" style="--es-p:<?php echo esc_attr((string) ($s['total'] ? round($s['done'] / $s['total'], 3) : 0)); ?>" role="img" aria-label="Готово <?php echo (int) $s['done']; ?> из <?php echo (int) $s['total']; ?>">
                <span><?php echo (int) $s['done']; ?><small>/<?php echo (int) $s['total']; ?></small></span>
            </div>
            <div>
                <h2 class="es-card__title" id="es-ready-title">Готовность к публикации</h2>
                <p class="es-muted"><?php echo $v['blocking'] ? 'Сначала уберите отмеченные пункты.' : 'Структурные проверки пройдены.'; ?></p>
            </div>
            <?php if (!$v['blocking']) : ?><details class="es-details es-checklist-all"><summary>Все <?php echo (int) $s['total']; ?> проверок</summary><?php endif; ?>
            <ul class="es-checklist">
                <?php foreach ($v['items'] as $item) : ?>
                    <li class="<?php echo $item['ok'] ? 'is-ok' : 'is-bad'; ?>">
                        <span class="es-checklist__icon" aria-hidden="true"><?php echo $item['ok'] ? '✓' : '!'; ?></span>
                        <div>
                            <?php if ($item['article']) : ?>
                                <a href="<?php echo esc_url(Admin::url($id, $item['article'])); ?>"><?php echo esc_html($item['title']); ?></a>
                            <?php else : ?>
                                <span><?php echo esc_html($item['title']); ?></span>
                            <?php endif; ?>
                            <?php if (!$item['ok'] && $item['details']) : ?>
                                <ul class="es-checklist__details">
                                    <?php foreach (array_slice($item['details'], 0, 4) as $detail) : ?><li><?php echo esc_html(\Kozlov\Gazeta\Domain\Readiness::detail($item, $detail)); ?></li><?php endforeach; ?>
                                    <?php if (count($item['details']) > 4) : ?><li>и ещё <?php echo count($item['details']) - 4; ?></li><?php endif; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if (!$v['blocking']) : ?></details><?php endif; ?>
        </section>

        <section class="es-card es-publish" aria-labelledby="es-publish-title">
            <?php
            $isPublished = $row['state'] === 'published';
            $isCurrent = $isPublished && (int) $row['version'] === (int) $row['public_version'];
            $publishTitle = $isPublished ? 'На сайте' : ($row['state'] === 'withdrawn' ? 'Выпуск снят' : 'Публикация');
            ?>
            <h2 class="es-card__title" id="es-publish-title"><?php echo esc_html($publishTitle); ?></h2>
            <?php if ($isPublished) : ?>
                <p class="es-pubstate<?php echo $isCurrent ? ' es-pubstate--current' : ''; ?>">
                    <?php if ($isCurrent) : ?>
                        <strong>✓ Читатели видят текущую версию.</strong> Утверждена <?php echo esc_html(Ui::when($row['public_updated_at'])); ?><?php echo $row['approved_by'] ? ' · ' . esc_html(Ui::userName((int) $row['approved_by'])) : ''; ?>.
                    <?php else : ?>
                        <strong>В черновике есть изменения.</strong> Читатели видят версию <?php echo (int) $row['public_version']; ?>; правки появятся на сайте после нового утверждения.
                    <?php endif; ?>
                </p>
            <?php elseif ($row['state'] === 'withdrawn') : ?>
                <p class="es-pubstate">Выпуск скрыт от читателей; номер <?php echo esc_html(Labels::issueNumber($row['number'])); ?> и снимок сохранены. Его можно вернуть на сайт тем же утверждением.</p>
            <?php endif; ?>
            <?php if ($v['is_owner'] && !$isCurrent) : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-es-publish>
                    <?php Actions::fields('publish', $id, $version); ?>
                    <label class="es-check">
                        <input type="checkbox" name="confirm" value="1" required<?php echo $v['blocking'] ? ' disabled' : ''; ?>>
                        <span>Я прочитал текущую версию: факты, цитаты, права на изображения и соцверсии проверены.</span>
                    </label>
                    <button type="submit" class="es-btn es-btn--publish"<?php echo $v['blocking'] ? ' disabled' : ''; ?>>
                        <?php echo $isPublished ? 'Утвердить новую версию' : ($row['state'] === 'withdrawn' ? 'Вернуть выпуск на сайт' : 'Опубликовать выпуск'); ?>
                    </button>
                    <?php if ($v['blocking']) : ?><p class="es-muted">Кнопка станет доступна, когда в списке готовности не останется замечаний.</p><?php endif; ?>
                    <?php if ($v['mode'] !== Mode::OPEN) : ?>
                        <p class="es-note">Газета в режиме «<?php echo esc_html(Mode::LABELS[$v['mode']]); ?>». Опубликованный выпуск увидит только редакция, пока вы отдельно не <a href="<?php echo esc_url(admin_url('admin.php?page=es-runtime')); ?>">откроете газету читателям</a>.</p>
                    <?php endif; ?>
                </form>
            <?php endif; ?>
            <?php if ($v['is_owner']) : ?>
                <?php if ($isPublished) : ?>
                    <details class="es-details es-danger">
                        <summary>Снять выпуск с публикации</summary>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <?php Actions::fields('withdraw', $id, $version); ?>
                            <label class="es-check"><input type="checkbox" name="confirm" value="1" required> <span>Скрыть весь выпуск и его материалы от читателей. Данные и номер сохранятся.</span></label>
                            <button type="submit" class="es-btn es-btn--danger">Снять с публикации</button>
                        </form>
                    </details>
                <?php endif; ?>
            <?php else : ?>
                <p>Публикует только владелец газеты: <strong><?php echo esc_html($v['owner']); ?></strong>. Подготовьте номер и снимите замечания — владелец увидит его готовым в пульте.</p>
            <?php endif; ?>
            <?php if ($v['can_replace']) : ?>
                <p class="es-side-link"><a href="<?php echo esc_url(add_query_arg(['page' => 'es-import', 'issue' => $id], admin_url('admin.php'))); ?>">Заменить черновик из JSON…</a></p>
            <?php endif; ?>
        </section>
    </aside>
</div>
