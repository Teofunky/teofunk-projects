<?php
/**
 * "Номер в двух словах": one sentence per story with a link to the material. Since 0.5.7 the whole
 * block is a closed «Поделиться номером» drawer; the 4:5 cards for social networks sit in a second
 * closed <details> inside it; copy and PNG buttons appear only with JS.
 * Nothing is posted anywhere automatically.
 * @var array $v
 */

if (!defined('ABSPATH')) {
    exit;
}
$issue = $v['issue'];
[$over, $main] = $v['name_parts'];
$s = $v['settings'];
$payload = [
    'brand' => $v['brand'],
    'over' => $over,
    'main' => $main,
    'date' => $issue['date'],
    'human_date' => $issue['human_date'],
    'number' => $issue['number_label'],
    'draft' => $v['private'],
    'issue_url' => $v['private'] ? '' : $issue['url'],
    'palette' => ['paper' => $s['paper'], 'ink' => $s['ink'], 'accent' => $s['accent']],
    'items' => $issue['digest'],
];
?>
<?php // 0.5.7: a closed drawer under the issue. Open, the same five sentences repeated the issue a second time. ?>
<details class="es-digest" id="es-digest">
    <summary class="es-digest__summary">Поделиться номером <span class="es-digest__hint">коротко для мессенджера и карточки для соцсетей</span></summary>
    <div class="es-digest__head">
        <h2 id="es-digest-title" class="es-digest__title">Номер в двух словах</h2>
        <button type="button" class="es-button es-button--quiet" data-es-copy-digest hidden>
            <svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M5 2h7a2 2 0 0 1 2 2v7h-2V4H5Z"/><path d="M2 6a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2Z"/></svg>
            Скопировать для мессенджера
        </button>
    </div>
    <ol class="es-digest__list">
        <?php foreach ($issue['digest'] as $index => $item) : ?>
            <li>
                <span class="es-digest__num" aria-hidden="true"><?php echo esc_html(str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT)); ?></span>
                <?php if ($item['url']) : ?>
                    <a href="<?php echo esc_url($item['url']); ?>"><?php echo esc_html($item['telegram']); ?></a>
                <?php else : ?>
                    <span><?php echo esc_html($item['telegram']); ?></span>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ol>
    <details class="es-digest__social">
        <summary>Карточки 1080×1350 для соцсетей</summary>
        <ol class="es-digest__cards">
            <?php foreach ($issue['digest'] as $index => $item) : ?>
                <li class="es-social">
                    <div class="es-social__card" aria-hidden="true">
                        <span class="es-social__mast"><?php echo esc_html($over); ?><br><?php echo esc_html($main); ?></span>
                        <span class="es-social__rubric"><?php echo esc_html(str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) . ' / ' . $item['rubric']); ?></span>
                        <?php $len = function_exists('mb_strlen') ? mb_strlen($item['instagram']) : strlen($item['instagram']); ?>
                        <span class="es-social__text<?php echo $len > 110 ? ' es-social__text--l' : ($len > 80 ? ' es-social__text--m' : ''); ?>"><?php echo esc_html($item['instagram']); ?></span>
                        <span class="es-social__foot"><span><?php echo esc_html($issue['human_date']); ?></span><span><?php echo esc_html($v['private'] ? 'Черновик' : $issue['number_label']); ?></span></span>
                    </div>
                    <p class="es-social__sentence"><?php echo esc_html($item['instagram']); ?></p>
                    <p class="es-social__actions"><button type="button" data-es-png="<?php echo esc_attr($item['id']); ?>" hidden>Скачать PNG</button></p>
                </li>
            <?php endforeach; ?>
        </ol>
    </details>
    <p class="es-digest__status" role="status" data-es-digest-status><?php echo $v['private'] ? 'Закрытый просмотр: карточки помечены как черновик, ссылок на неопубликованные материалы нет.' : ''; ?></p>
    <script type="application/json" id="es-digest-data"><?php echo wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
</details>
