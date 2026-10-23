<?php
/**
 * «Кроссворд недели» (0.5.2; phone keyboard 0.5.3). Without JavaScript the grid is a set of plain one-letter fields
 * (it can be filled by hand or printed); assets/js/crossword.js adds navigation, checking,
 * hints, the timer and progress kept in this browser.
 * @var array $v
 */

use Kozlov\Gazeta\Front\View;

if (!defined('ABSPATH')) {
    exit;
}
$cw = $v['crossword'];
$names = ['a' => 'По горизонтали', 'd' => 'По вертикали'];
?>
<article class="es-cwpage" aria-labelledby="es-cw-title">
    <header class="es-cwpage__head">
        <?php if ($cw['issue_url'] !== '') : ?>
            <p class="es-cwpage__back"><a href="<?php echo esc_url($cw['issue_url']); ?>">← К номеру <?php echo esc_html($cw['number_label'] . ' за ' . $cw['human_date']); ?></a></p>
        <?php endif; ?>
        <p class="es-cwpage__kicker">Кроссворд недели</p>
        <h1 class="es-cwpage__title" id="es-cw-title"><?php echo esc_html($cw['title']); ?></h1>
        <?php if ($cw['intro'] !== '') : ?><p class="es-cwpage__intro"><?php echo esc_html($cw['intro']); ?></p><?php endif; ?>
        <p class="es-cwpage__meta">
            <span><?php echo esc_html($cw['words_label']); ?></span>
            <span>сетка <?php echo (int) $cw['rows'] . '×' . (int) $cw['cols']; ?></span>
            <?php if ($cw['author'] !== '') : ?><span><?php echo esc_html($cw['author']); ?></span><?php endif; ?>
            <span><?php echo $cw['open'] ? 'ответы открыты' : esc_html('ответы — ' . $cw['opens_on']); ?></span>
        </p>
        <?php if ($v['preview'] && !empty($v['urls']['answers'])) : ?>
            <p class="es-cwpage__preview"><a href="<?php echo esc_url($v['urls']['answers']); ?>"><?php echo $cw['open'] ? 'Скрыть ответы' : 'Показать ответы (видит только редакция)'; ?></a></p>
        <?php endif; ?>
    </header>

    <div class="es-cw" style="--cw-cols: <?php echo (int) $cw['cols']; ?>" data-es-cw data-es-cw-data="<?php echo esc_attr((string) wp_json_encode($cw['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?>">
        <div class="es-cw__tools" data-es-cw-tools>
            <span class="es-cw__time" title="Время решения"><span class="es-sr">Время: </span><span data-es-cw-time>0:00</span></span>
            <?php // On a phone (0.5.3) the verb moves to the group label: «Проверить: слово · всё». ?>
            <span class="es-cw__group" role="group" aria-label="Проверка" data-short="Проверить">
                <button type="button" class="es-cw__btn" data-es-cw-action="check-word" aria-label="Проверить слово"><span class="es-cw__long">Проверить&nbsp;</span>слово</button>
                <button type="button" class="es-cw__btn" data-es-cw-action="check-all" aria-label="Проверить всё"><span class="es-cw__long">Проверить&nbsp;</span>всё</button>
            </span>
            <span class="es-cw__group" role="group" aria-label="Подсказки" data-short="Открыть">
                <button type="button" class="es-cw__btn" data-es-cw-action="reveal-letter" aria-label="Открыть букву"><span class="es-cw__long">Открыть&nbsp;</span>букву</button>
                <button type="button" class="es-cw__btn" data-es-cw-action="reveal-word" aria-label="Открыть слово"><span class="es-cw__long">Открыть&nbsp;</span>слово</button>
            </span>
            <span class="es-cw__group es-cw__group--end" role="group" aria-label="Поле">
                <button type="button" class="es-cw__btn es-cw__btn--icon" data-es-cw-action="zoom-out" aria-label="Мельче">−</button>
                <button type="button" class="es-cw__btn es-cw__btn--icon" data-es-cw-action="zoom-in" aria-label="Крупнее">+</button>
                <button type="button" class="es-cw__btn" data-es-cw-action="print">Печать</button>
                <button type="button" class="es-cw__btn" data-es-cw-action="reset">Сначала</button>
            </span>
        </div>

        <div class="es-cw__layout">
            <div class="es-cw__board">
                <div class="es-cw__scroll" data-es-cw-scroll>
                    <div class="es-cw__grid" role="group" aria-label="<?php echo esc_attr('Сетка кроссворда, ' . $cw['rows'] . ' строк на ' . $cw['cols'] . ' столбцов. Стрелки — переход по клеткам, пробел — смена направления, Enter — следующее слово.'); ?>" style="--cw-cols: <?php echo (int) $cw['cols']; ?>"><?php
                        // Compact on purpose: a 25×25 grid is 625 elements. <i> is an empty square,
                        // <span> a letter cell (row-major order; crossword.js counts positions).
                        $out = '';
                        foreach ($cw['cells'] as $row) {
                            foreach ($row as $cell) {
                                if ($cell === null) {
                                    $out .= '<i></i>';
                                    continue;
                                }
                                $out .= '<span>' . ($cell['n'] ? '<em aria-hidden="true">' . (int) $cell['n'] . '</em>' : '')
                                    . '<input maxlength="1" autocomplete="off" aria-label="' . esc_attr($cell['label']) . '"></span>';
                            }
                        }
                        echo $out; // Built from integers and esc_attr() only.
                        ?></div>
                </div>
                <div class="es-cw__current" data-es-cw-current>
                    <button type="button" class="es-cw__nav" data-es-cw-action="prev" aria-label="Предыдущее слово">‹</button>
                    <p class="es-cw__clue" aria-live="polite"><span class="es-cw__clue-id" data-es-cw-current-id></span> <span data-es-cw-current-text></span></p>
                    <button type="button" class="es-cw__nav" data-es-cw-action="next" aria-label="Следующее слово">›</button>
                </div>
                <p class="es-cw__status" role="status" data-es-cw-status></p>
                <noscript><p class="es-cw__nojs">Чтобы переходить по клеткам и проверять ответы, включите JavaScript. Без него клетки можно заполнять вручную или распечатать кроссворд.</p></noscript>
            </div>

            <div class="es-cw__clues">
                <?php foreach ($names as $d => $name) : ?>
                    <section class="es-cw__list" aria-labelledby="es-cw-h-<?php echo esc_attr($d); ?>">
                        <h2 class="es-cw__h" id="es-cw-h-<?php echo esc_attr($d); ?>"><?php echo esc_html($name); ?></h2>
                        <ol>
                            <?php foreach ($cw['clues'][$d] as $clue) : ?>
                                <li data-es-cw-clue="<?php echo esc_attr($d . $clue['n']); ?>"><span class="es-cw__cbtn"><span class="es-cw__cn"><?php echo (int) $clue['n']; ?></span> <span class="es-cw__ct"><?php echo esc_html($clue['clue']); ?> <span class="es-cw__enum">(<?php echo esc_html($clue['enum']); ?>)</span></span></span></li>
                            <?php endforeach; ?>
                        </ol>
                    </section>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <?php if ($cw['open']) : ?>
        <details class="es-cwpage__answers">
            <summary>Ответы</summary>
            <?php View::render('front/parts/crossword-solution', ['cw' => $cw, 'id' => 'es-cw-sol']); ?>
        </details>
    <?php else : ?>
        <p class="es-cwpage__later">Ответы и источники вопросов появятся здесь <?php echo esc_html($cw['opens_on']); ?> или с выходом следующего кроссворда.</p>
    <?php endif; ?>

    <?php if (!empty($cw['newer'])) : ?>
        <p class="es-cwpage__newer">Вышел новый кроссворд: <a href="<?php echo esc_url($cw['newer']['url']); ?>"><?php echo esc_html($cw['newer']['title']); ?></a> (<?php echo esc_html($cw['newer']['human_date']); ?>)</p>
    <?php endif; ?>

    <?php if (!empty($cw['previous'])) : ?>
        <section class="es-cwpage__prev" aria-labelledby="es-cw-prev-title">
            <h2 class="es-cwpage__h2" id="es-cw-prev-title">Ответы на прошлый кроссворд</h2>
            <p class="es-cwpage__prevlead"><a href="<?php echo esc_url($cw['previous']['url']); ?>"><?php echo esc_html($cw['previous']['plain_title']); ?></a> · <?php echo esc_html($cw['previous']['human_date']); ?></p>
            <details class="es-cwpage__answers">
                <summary>Показать ответы</summary>
                <?php View::render('front/parts/crossword-solution', ['cw' => $cw['previous'], 'id' => 'es-cw-prev']); ?>
            </details>
        </section>
    <?php endif; ?>

    <p class="es-cwpage__all"><a href="<?php echo esc_url($v['urls']['crosswords']); ?>">Все кроссворды</a></p>
</article>
