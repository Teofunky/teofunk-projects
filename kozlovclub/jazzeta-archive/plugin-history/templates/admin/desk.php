<?php
/**
 * Desk home.
 * @var array $v
 */

use Kozlov\Gazeta\Admin\Actions;
use Kozlov\Gazeta\Admin\Admin;
use Kozlov\Gazeta\Admin\Ui;
use Kozlov\Gazeta\Domain\Labels;
use Kozlov\Gazeta\Runtime\Mode;

if (!defined('ABSPATH')) {
    exit;
}
$t = $v['today_issue'];
?>
<div class="es-layout es-layout--desk">
    <section class="es-card es-today" aria-labelledby="es-today-title">
        <p class="es-eyebrow">Номер на сегодня</p>
        <h2 class="es-today__date" id="es-today-title"><?php echo esc_html($v['today_label']); ?></h2>

        <?php if ($t) :
            $row = $t['row'];
            $s = $t['summary'];
            $ratio = $s['total'] ? $s['done'] / $s['total'] : 0;
            ?>
            <div class="es-today__state">
                <?php echo Ui::stateChip($row['state']); ?>
                <span class="es-today__number"><?php echo esc_html($row['number'] ? Labels::issueNumber($row['number']) : 'номер — при публикации'); ?></span>
                <span class="es-muted">версия <?php echo (int) $row['version']; ?> · изменён <?php echo esc_html(Ui::when($row['updated_at'])); ?></span>
            </div>
            <div class="es-stats">
                <div class="es-stat"><strong><?php echo (int) $t['articles']; ?></strong><span><?php echo esc_html(Labels::plural((int) $t['articles'], 'материал', 'материала', 'материалов')); ?></span></div>
                <div class="es-stat"><strong><?php echo (int) $s['articles_reviewed']; ?><small>/<?php echo (int) $s['articles_total']; ?></small></strong><span>проверено человеком</span></div>
                <div class="es-stat<?php echo $t['failing'] ? ' es-stat--warn' : ' es-stat--ok'; ?>"><strong><?php echo count($t['failing']); ?></strong><span><?php echo $t['failing'] ? esc_html(Labels::plural(count($t['failing']), 'пункт мешает', 'пункта мешают', 'пунктов мешают')) . ' публикации' : 'препятствий нет'; ?></span></div>
            </div>
            <div class="es-meter" role="img" aria-label="Готовность: <?php echo (int) $s['done']; ?> из <?php echo (int) $s['total']; ?> проверок">
                <span style="--es-p:<?php echo esc_attr((string) round($ratio, 3)); ?>"></span>
            </div>
            <p class="es-meter__label">Готовность: <?php echo (int) $s['done']; ?> из <?php echo (int) $s['total']; ?> проверок</p>
            <?php if ($t['failing']) : ?>
                <ul class="es-todo">
                    <?php foreach (array_slice($t['failing'], 0, 4) as $item) : ?>
                        <li>
                            <?php if ($item['article']) : ?>
                                <a href="<?php echo esc_url(Admin::url((int) $row['id'], $item['article'])); ?>"><?php echo esc_html($item['title']); ?></a>
                            <?php else : ?>
                                <a href="<?php echo esc_url(Admin::url((int) $row['id'])); ?>"><?php echo esc_html($item['title']); ?></a>
                            <?php endif; ?>
                            <span><?php echo esc_html(\Kozlov\Gazeta\Domain\Readiness::detail($item, (string) ($item['details'][0] ?? ''))); ?></span>
                        </li>
                    <?php endforeach; ?>
                    <?php if (count($t['failing']) > 4) : ?><li class="es-muted">и ещё <?php echo count($t['failing']) - 4; ?> — на странице выпуска</li><?php endif; ?>
                </ul>
            <?php endif; ?>
            <div class="es-actions">
                <a class="es-btn es-btn--primary" href="<?php echo esc_url(Admin::url((int) $row['id'])); ?>">Открыть выпуск</a>
                <a class="es-btn" href="<?php echo esc_url($t['preview']); ?>" target="_blank" rel="noopener">Предпросмотр ↗</a>
            </div>
        <?php else : ?>
            <p class="es-today__empty">Черновика на сегодня пока нет. Сборщик присылает его через защищённый REST-импорт; можно также создать выпуск вручную.</p>
            <div class="es-actions">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php Actions::fields('create'); ?>
                    <input type="hidden" name="edition_date" value="<?php echo esc_attr($v['today']); ?>">
                    <button class="es-btn es-btn--primary" type="submit">Создать выпуск на сегодня</button>
                </form>
                <?php if ($v['can_import']) : ?>
                    <a class="es-btn" href="<?php echo esc_url(admin_url('admin.php?page=es-import')); ?>">Импортировать JSON</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>

    <aside class="es-card es-facts" aria-label="Состояние газеты">
        <h2 class="es-card__title">Газета сейчас</h2>
        <dl class="es-dl">
            <div><dt>Режим</dt><dd><a class="es-mode es-mode--<?php echo esc_attr($v['mode']); ?>" href="<?php echo esc_url(admin_url('admin.php?page=es-runtime')); ?>"><span class="es-mode__dot" aria-hidden="true"></span><?php echo esc_html(Mode::LABELS[$v['mode']]); ?></a></dd></div>
            <div><dt>Последний опубликованный</dt><dd>
                <?php if ($v['latest']) : ?>
                    <a href="<?php echo esc_url(Admin::url((int) $v['latest']['id'])); ?>"><?php echo esc_html(Labels::issueNumber($v['latest']['number']) . ' · ' . Labels::date($v['latest']['edition_date'])); ?></a>
                <?php else : ?>
                    <span class="es-muted">ещё не было</span>
                <?php endif; ?>
            </dd></div>
            <div><dt>Последний импорт</dt><dd>
                <?php if ($v['import']) : ?>
                    <?php echo esc_html(Ui::when($v['import']['created_at'])); ?> · <?php echo esc_html(Ui::userName((int) $v['import']['actor_id'])); ?>
                <?php else : ?>
                    <span class="es-muted">не было</span>
                <?php endif; ?>
            </dd></div>
            <div><dt>Публикует</dt><dd><?php echo esc_html($v['owner']); ?><?php echo $v['is_owner'] ? ' <span class="es-chip es-chip--ok">это вы</span>' : ''; ?></dd></div>
        </dl>
        <p class="es-note">Автоматика только готовит черновики. Публикация и открытие газеты читателям — ручные действия владельца.</p>
    </aside>
</div>

<section class="es-card es-strip" aria-labelledby="es-strip-title">
    <div class="es-card__head">
        <h2 class="es-card__title" id="es-strip-title">Две недели</h2>
        <p class="es-legend-inline">
            <span class="es-dotkey es-dotkey--published">опубликован</span>
            <span class="es-dotkey es-dotkey--draft">черновик</span>
            <span class="es-dotkey es-dotkey--withdrawn">снят</span>
            <span class="es-dotkey es-dotkey--none">нет выпуска</span>
        </p>
    </div>
    <ol class="es-strip__days">
        <?php foreach ($v['days'] as $day) :
            $state = $day['row'] ? $day['row']['state'] : 'none';
            $url = $day['row'] ? Admin::url((int) $day['row']['id']) : '';
            ?>
            <li class="es-day es-day--<?php echo esc_attr($state); ?><?php echo $day['today'] ? ' es-day--today' : ''; ?>">
                <?php if ($url) : ?><a href="<?php echo esc_url($url); ?>"><?php else : ?><span><?php endif; ?>
                    <span class="es-day__wd"><?php echo esc_html($day['weekday']); ?></span>
                    <span class="es-day__n"><?php echo esc_html($day['day']); ?></span>
                    <?php if ($day['row'] && $day['row']['number']) : ?>
                        <span class="es-day__state">№&nbsp;<?php echo (int) $day['row']['number']; ?></span>
                    <?php elseif ($day['row']) : ?>
                        <span class="es-day__state"><span class="es-day__long"><?php echo esc_html(Labels::ISSUE_STATES[$state]); ?></span><span class="es-day__short" aria-hidden="true"><?php echo esc_html(mb_substr(Labels::ISSUE_STATES[$state], 0, 4)); ?>.</span></span>
                    <?php else : ?>
                        <span class="es-day__state">—</span>
                    <?php endif; ?>
                <?php if ($url) : ?></a><?php else : ?></span><?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ol>
</section>

<section class="es-card" aria-labelledby="es-all-title">
    <div class="es-card__head">
        <h2 class="es-card__title" id="es-all-title">Все выпуски</h2>
        <form method="get" class="es-inline-form" role="search">
            <input type="hidden" name="page" value="es-newsroom">
            <label class="es-sr-only" for="es-find-date">Дата выпуска</label>
            <input id="es-find-date" type="date" name="date" value="<?php echo esc_attr($v['search']); ?>">
            <button class="es-btn es-btn--small" type="submit">Найти</button>
            <?php if ($v['search'] !== '') : ?><a class="es-link" href="<?php echo esc_url(Admin::url()); ?>">Сбросить</a><?php endif; ?>
        </form>
    </div>
    <?php if ($v['listing']['items']) : ?>
        <table class="es-table">
            <thead><tr><th scope="col">Дата</th><th scope="col">Номер</th><th scope="col">Состояние</th><th scope="col">Версия</th><th scope="col">Изменён</th></tr></thead>
            <tbody>
                <?php foreach ($v['listing']['items'] as $row) : ?>
                    <tr>
                        <td><a class="es-strong-link" href="<?php echo esc_url(Admin::url((int) $row['id'])); ?>"><?php echo esc_html(Labels::longDate($row['edition_date'])); ?></a></td>
                        <td><?php echo esc_html($row['number'] ? Labels::issueNumber($row['number']) : '—'); ?></td>
                        <td><?php echo Ui::stateChip($row['state']); ?><?php echo (int) $row['human_touched'] ? '' : ' <span class="es-chip es-chip--auto" title="Создан импортом, человек ещё не правил">авто</span>'; ?></td>
                        <td><?php echo (int) $row['version']; ?><?php echo (int) $row['public_version'] ? ' <span class="es-muted">/ опубл. ' . (int) $row['public_version'] . '</span>' : ''; ?></td>
                        <td><?php echo esc_html(Ui::when($row['updated_at'])); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ($v['listing']['pages'] > 1) : ?>
            <nav class="es-pager-admin" aria-label="Страницы списка">
                <?php if ($v['page'] > 1) : ?><a class="es-btn es-btn--small" href="<?php echo esc_url(add_query_arg('paged', $v['page'] - 1, Admin::url())); ?>">← Новее</a><?php endif; ?>
                <span><?php echo (int) $v['page']; ?> из <?php echo (int) $v['listing']['pages']; ?></span>
                <?php if ($v['page'] < $v['listing']['pages']) : ?><a class="es-btn es-btn--small" href="<?php echo esc_url(add_query_arg('paged', $v['page'] + 1, Admin::url())); ?>">Старше →</a><?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php else : ?>
        <p class="es-empty">Выпусков<?php echo $v['search'] !== '' ? ' на эту дату' : ''; ?> пока нет.</p>
    <?php endif; ?>
    <details class="es-details">
        <summary>Создать выпуск на другую дату</summary>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="es-inline-form">
            <?php Actions::fields('create'); ?>
            <label for="es-new-date">Дата выпуска</label>
            <input id="es-new-date" type="date" name="edition_date" required value="<?php echo esc_attr($v['today']); ?>">
            <button class="es-btn" type="submit">Создать пустой черновик</button>
        </form>
    </details>
</section>
