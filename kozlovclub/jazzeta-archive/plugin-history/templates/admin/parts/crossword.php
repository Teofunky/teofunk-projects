<?php
/**
 * «Кроссворд недели» in the issue screen (0.5.2): load a file or plain lines, see the grid
 * with answers (the desk sees them; readers do not), rebuild, preview, remove.
 * @var array $v ['id' => int, 'version' => int, 'crossword' => ?array, 'preview' => string, 'published' => bool]
 */

use Kozlov\Gazeta\Admin\Actions;
use Kozlov\Gazeta\Domain\Crossword;
use Kozlov\Gazeta\Domain\Labels;

if (!defined('ABSPATH')) {
    exit;
}
$id = (int) $v['id'];
$version = (int) $v['version'];
$cw = $v['crossword'];
$names = ['a' => 'По горизонтали', 'd' => 'По вертикали'];
?>
<section class="es-card es-cwdesk" id="crossword" aria-labelledby="es-cwdesk-title">
    <div class="es-card__head">
        <h2 class="es-card__title" id="es-cwdesk-title">Кроссворд недели</h2>
        <p class="es-muted">Пришлите файл формата <code><?php echo esc_html(Crossword::FORMAT); ?></code> или вставьте строки «Ответ — вопрос». Сетку собирает плагин. Читатели увидят кроссворд вместе с номером после утверждения, ответы — через неделю.</p>
    </div>

    <?php if ($cw) :
        $grid = Crossword::grid($cw);
        $clues = Crossword::clues($cw);
        $unplaced = array_map(static function ($i) use ($cw) {
            return $cw['words'][$i]['answer'];
        }, (array) $cw['unplaced']);
        $notes = [];
        foreach ($cw['words'] as $word) {
            if (($word['note'] ?? '') !== '') {
                $notes[$word['letters']] = $word['note'];
            }
        }
        ?>
        <div class="es-cwdesk__summary">
            <strong><?php echo esc_html($cw['title']); ?></strong>
            <span class="es-muted"><?php echo esc_html(Labels::count(count($cw['placements']), 'слово', 'слова', 'слов') . ' · сетка ' . $cw['rows'] . '×' . $cw['cols']); ?></span>
        </div>
        <?php if ($unplaced) : ?>
            <div class="es-alert es-alert--warn"><strong>Не вошли в сетку:</strong> <?php echo esc_html(implode(', ', $unplaced)); ?>. Попробуйте «Другая сетка» или замените эти слова — в кроссворде их не будет.</div>
        <?php endif; ?>
        <?php foreach ((array) ($cw['warnings'] ?? []) as $warning) : ?>
            <p class="es-hint"><?php echo esc_html($warning); ?></p>
        <?php endforeach; ?>

        <div class="es-cwdesk__view">
            <div class="es-cwdesk__grid" style="--cw-cols: <?php echo (int) $cw['cols']; ?>" aria-hidden="true">
                <?php for ($r = 0; $r < $cw['rows']; $r++) : ?>
                    <?php for ($c = 0; $c < $cw['cols']; $c++) : ?>
                        <?php if (isset($grid[$r][$c])) : ?><span class="es-cwdesk__c"><?php echo esc_html($grid[$r][$c]); ?></span><?php else : ?><span class="es-cwdesk__b"></span><?php endif; ?>
                    <?php endfor; ?>
                <?php endfor; ?>
            </div>
            <div class="es-cwdesk__lists">
                <?php foreach ($names as $d => $name) : ?>
                    <div>
                        <h3 class="es-cwdesk__h"><?php echo esc_html($name); ?></h3>
                        <ol class="es-cwdesk__list">
                            <?php foreach ($clues[$d] as $clue) :
                                $letters = strtr(mb_strtoupper(str_replace([' ', '-'], '', $clue['answer'])), ['Ё' => 'Е']);
                                ?>
                                <li>
                                    <b><?php echo (int) $clue['n']; ?>.</b> <span class="es-cwdesk__a"><?php echo esc_html($clue['answer']); ?></span> — <?php echo esc_html($clue['clue']); ?>
                                    <?php if ($clue['source'] !== '') : ?> <a href="<?php echo esc_url($clue['source']); ?>" target="_blank" rel="noopener noreferrer">источник</a><?php endif; ?>
                                    <?php if (isset($notes[$letters])) : ?><br><span class="es-hint">Заметка: <?php echo esc_html($notes[$letters]); ?></span><?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="es-actions es-cwdesk__actions">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="es-inline-form">
                <?php Actions::fields('crossword', $id, $version); ?>
                <button type="submit" name="intent" value="rebuild" class="es-btn">Другая сетка</button>
            </form>
            <a class="es-btn" href="<?php echo esc_url($v['preview']); ?>" target="_blank" rel="noopener">Предпросмотр ↗</a>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="es-inline-form" data-es-confirm="Убрать кроссворд из этого номера? Его можно будет загрузить снова; прежняя версия останется в истории.">
                <?php Actions::fields('crossword', $id, $version); ?>
                <button type="submit" name="intent" value="remove" class="es-btn es-btn--danger">Убрать кроссворд</button>
            </form>
        </div>
        <?php if (!empty($v['published'])) : ?><p class="es-hint">Номер уже вышел: изменения кроссворда читатели увидят после повторного утверждения.</p><?php endif; ?>
    <?php endif; ?>

    <details class="es-details"<?php echo $cw ? '' : ' open'; ?>>
        <summary><?php echo $cw ? 'Заменить другим файлом' : 'Загрузить кроссворд'; ?></summary>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-es-cw-form>
            <?php Actions::fields('crossword', $id, $version); ?>
            <div class="es-drop" data-es-drop>
                <input type="file" accept="application/json,.json,text/plain,.txt" id="es-cw-file" data-es-file>
                <label for="es-cw-file"><strong>Выберите файл</strong> (.json или .txt) или перетащите его сюда</label>
                <span class="es-muted">или вставьте содержимое в поле ниже</span>
            </div>
            <div class="es-field">
                <label for="es-cw-source">JSON <code><?php echo esc_html(Crossword::FORMAT); ?></code> или строки «Ответ — вопрос»</label>
                <textarea id="es-cw-source" name="crossword_source" rows="10" class="es-code" spellcheck="false" required data-es-cw-source placeholder="Маросейка — Улица, на которой стоит Клуб Алексея Козлова&#10;Паркер — Альт-саксофонист по прозвищу Bird"></textarea>
                <p class="es-json-status" data-es-cw-status aria-live="polite"></p>
            </div>
            <div class="es-grid-2">
                <div class="es-field">
                    <label for="es-cw-title">Название <span class="es-hint">если в файле нет</span></label>
                    <input id="es-cw-title" type="text" name="crossword_title" maxlength="80">
                </div>
                <div class="es-field">
                    <label for="es-cw-intro">Вступление <span class="es-hint">необязательно</span></label>
                    <input id="es-cw-intro" type="text" name="crossword_intro" maxlength="300">
                </div>
            </div>
            <div class="es-actions">
                <button type="submit" name="intent" value="build" class="es-btn es-btn--primary">Проверить и собрать сетку</button>
            </div>
        </form>
    </details>
</section>
