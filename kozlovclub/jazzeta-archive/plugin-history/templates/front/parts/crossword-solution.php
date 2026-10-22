<?php
/**
 * Solved grid and the list of answers (open a week after publication).
 * The small grid is decorative for screen readers; the list carries the same content.
 * @var array $v ['cw' => array from Presenter::crossword() with open = true, 'id' => string]
 */
if (!defined('ABSPATH')) {
    exit;
}
$cw = $v['cw'];
$names = ['a' => 'По горизонтали', 'd' => 'По вертикали'];
?>
<div class="es-cwsol">
    <div class="es-cwsol__grid" style="--cw-cols: <?php echo (int) $cw['cols']; ?>" aria-hidden="true"><?php
        $out = '';
        foreach ($cw['cells'] as $row) {
            foreach ($row as $cell) {
                $out .= $cell === null ? '<i></i>' : '<b>' . ($cell['n'] ? '<em>' . (int) $cell['n'] . '</em>' : '') . esc_html($cell['letter']) . '</b>';
            }
        }
        echo $out; // Integers and esc_html() only.
        ?></div>
    <div class="es-cwsol__lists">
        <?php foreach ($names as $d => $name) : ?>
            <section aria-labelledby="<?php echo esc_attr($v['id'] . '-' . $d); ?>">
                <h3 class="es-cwsol__h" id="<?php echo esc_attr($v['id'] . '-' . $d); ?>"><?php echo esc_html($name); ?></h3>
                <ol class="es-cwsol__list">
                    <?php foreach ($cw['clues'][$d] as $clue) : ?>
                        <li><b><?php echo (int) $clue['n']; ?>.</b> <span class="es-cwsol__a"><?php echo esc_html($clue['answer']); ?></span> — <?php echo esc_html($clue['clue']); ?><?php if ($clue['source'] !== '') : ?> <a class="es-cwsol__src" href="<?php echo esc_url($clue['source']); ?>" rel="nofollow noopener" target="_blank">источник<span class="es-sr"> (откроется в новой вкладке)</span></a><?php endif; ?></li>
                    <?php endforeach; ?>
                </ol>
            </section>
        <?php endforeach; ?>
    </div>
</div>
