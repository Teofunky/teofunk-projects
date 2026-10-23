<?php
/**
 * Masthead: name, edition line (date, number, size of the issue), the two sections and search.
 * On the issue page the name is the page's only H1; elsewhere the H1 belongs to the content.
 * @var array $v
 */
if (!defined('ABSPATH')) {
    exit;
}
$s = $v['settings'];
[$over, $main] = $v['name_parts'];
$issue = $v['issue'];
$isFront = $v['view'] === 'issue';
$homeUrl = $v['preview'] && $issue ? $issue['url'] : $v['urls']['home'];
$logo = (int) $s['logo_id'] ? wp_get_attachment_image((int) $s['logo_id'], 'medium_large', false, ['alt' => $v['brand'], 'loading' => 'eager', 'class' => 'es-mast__logo']) : '';
$nameTag = $isFront ? 'h1' : 'p';
?>
<header class="es-mast<?php echo $isFront ? ' es-mast--front' : ' es-mast--inner'; ?>">
    <p class="es-mast__tagline"><?php echo esc_html($s['subtitle']); ?></p>
    <<?php echo $nameTag; ?> class="es-mast__brand">
        <a class="es-mast__name" href="<?php echo esc_url($homeUrl); ?>">
            <?php if ($logo) : ?>
                <?php echo $logo; // wp_get_attachment_image escapes its attributes. ?>
            <?php else : ?>
                <?php if ($over !== '') : ?><span class="es-mast__over"><?php echo esc_html($over); ?></span> <?php endif; ?>
                <span class="es-mast__main"><?php echo esc_html($main); ?></span>
            <?php endif; ?>
            <?php if ($isFront && $issue) : ?><span class="es-sr">, номер за <?php echo esc_html($issue['long_date']); ?></span><?php endif; ?>
        </a>
    </<?php echo $nameTag; ?>>
    <p class="es-mast__motto"><?php echo esc_html($s['motto']); ?></p>
    <div class="es-mast__line">
        <?php if ($issue) : ?>
            <p class="es-mast__edition">
                <span class="es-mast__when"><time datetime="<?php echo esc_attr($issue['date']); ?>"><?php echo esc_html($issue['long_date']); ?></time><?php if ($issue['number']) : ?><span class="es-mast__no"><?php echo esc_html($issue['number_label']); ?></span><?php endif; ?></span>
            </p>
        <?php else : ?>
            <p class="es-mast__edition"><span>Газета Клуба Алексея Козлова</span></p>
        <?php endif; ?>
        <nav class="es-mast__nav" aria-label="Газета">
            <a href="<?php echo esc_url($homeUrl); ?>"<?php echo $isFront && !empty($issue['is_latest']) ? ' aria-current="page"' : ''; ?>>Свежий номер</a>
            <a href="<?php echo esc_url($v['urls']['archive']); ?>"<?php echo $v['view'] === 'archive' ? ' aria-current="page"' : ''; ?>>Все выпуски</a>
            <a class="es-mast__search" href="<?php echo esc_url($v['urls']['search']); ?>"<?php echo $v['view'] === 'search' ? ' aria-current="page"' : ''; ?>><svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><circle cx="7" cy="7" r="4.6" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="m10.4 10.4 3.6 3.6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>Поиск</a>
        </nav>
    </div>
</header>
