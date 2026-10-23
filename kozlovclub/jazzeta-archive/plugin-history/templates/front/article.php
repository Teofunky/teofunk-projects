<?php
/**
 * Article page. Same markup in all modes: head, optional picture, editorial blocks only when
 * filled, text, what is confirmed (only if the editor chose to show it), sources, disclosure,
 * development, neighbours in the issue.
 * @var array $v
 */

use Kozlov\Gazeta\Domain\Labels;
use Kozlov\Gazeta\Front\View;

if (!defined('ABSPATH')) {
    exit;
}
$a = $v['article'];
$issue = $v['issue'];
$canonical = $v['seo']['canonical'];
$back = $issue['url'] . '#' . $a['anchor'];
$telegram = $canonical !== '' ? 'https://t.me/share/url?url=' . rawurlencode($canonical) . '&text=' . rawurlencode($a['headline_plain']) : '';
$vk = $canonical !== '' ? 'https://vk.com/share.php?url=' . rawurlencode($canonical) : '';
$hasFacts = $a['facts']['confirmed'] || $a['facts']['single'];
?>
<article class="es-article" data-es-article aria-labelledby="es-article-title">
    <nav class="es-crumbs" aria-label="Путь">
        <a class="es-crumbs__back" href="<?php echo esc_url($back); ?>"><span aria-hidden="true">←</span> К номеру<?php echo $issue['number'] ? ' ' . esc_html($issue['number_label']) : ''; ?> за <?php echo esc_html($issue['human_date']); ?></a>
    </nav>

    <header class="es-article__head">
        <p class="es-article__kicker">
            <span><?php echo esc_html($a['rubric']); ?></span>
        </p>
        <h1 class="es-article__title" id="es-article-title"><?php echo esc_html($a['headline']); ?></h1>
        <p class="es-article__dek"><?php echo esc_html($a['lead']); ?></p>
        <p class="es-article__byline">
            <span>Выпуск <time datetime="<?php echo esc_attr($issue['date']); ?>"><?php echo esc_html($issue['human_date']); ?></time></span>
        </p>
    </header>

    <?php if ($a['image']) : ?>
        <?php View::render('front/parts/figure', ['image' => $a['image'], 'role' => 'article', 'eager' => true, 'sizes' => '(min-width: 1100px) 760px, 100vw']); ?>
    <?php endif; ?>

    <div class="es-article__grid">
        <div class="es-article__main">
            <?php if ($a['brief']) : ?>
                <div class="es-brief">
                    <?php foreach ($a['brief'] as $block) : ?>
                        <section class="es-brief__block es-brief__block--<?php echo esc_attr($block['key']); ?>" aria-labelledby="es-brief-<?php echo esc_attr($block['key']); ?>">
                            <h2 class="es-brief__title" id="es-brief-<?php echo esc_attr($block['key']); ?>"><?php echo esc_html($block['title']); ?></h2>
                            <p><?php echo esc_html($block['text']); ?></p>
                        </section>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="es-prose">
                <?php echo $a['body_html']; // Sanitised by the validator's wp_kses allowlist on save. ?>
            </div>

            <?php if ($a['development_note'] !== '') : ?>
                <section class="es-development" aria-labelledby="es-development-title">
                    <h2 id="es-development-title">Что нового с прошлого раза</h2>
                    <p><?php echo esc_html($a['development_note']); ?></p>
                </section>
            <?php endif; ?>

            <?php if ($hasFacts) : ?>
                <section class="es-facts" aria-labelledby="es-facts-title">
                    <h2 class="es-facts__title" id="es-facts-title">Что известно</h2>
                    <?php if ($a['facts']['confirmed']) : ?>
                        <h3 class="es-facts__sub">Подтверждено</h3>
                        <ul><?php foreach ($a['facts']['confirmed'] as $fact) : ?><li><?php echo esc_html($fact); ?></li><?php endforeach; ?></ul>
                    <?php endif; ?>
                    <?php if ($a['facts']['single']) : ?>
                        <h3 class="es-facts__sub">Пока известно из одного источника</h3>
                        <ul><?php foreach ($a['facts']['single'] as $fact) : ?><li><?php echo esc_html($fact); ?></li><?php endforeach; ?></ul>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <?php if ($a['disclosure']) : ?>
                <aside class="es-disclosure" aria-labelledby="es-disclosure-title">
                    <h2 id="es-disclosure-title">Связь с издателем</h2>
                    <p><?php echo esc_html($a['disclosure']); ?></p>
                </aside>
            <?php endif; ?>

            <?php if ($a['sources']) : ?>
                <?php // 0.5.6: one line per source — who published it, what, when. No evidence badges or source types. ?>
                <section class="es-sources" id="es-sources" aria-labelledby="es-sources-title">
                    <h2 class="es-sources__title" id="es-sources-title">Источники</h2>
                    <ol class="es-sources__list">
                        <?php foreach ($a['sources'] as $source) : ?>
                            <li>
                                <span class="es-sources__pub"><?php echo esc_html($source['publisher']); ?></span><span class="es-sources__dash" aria-hidden="true"> — </span><a href="<?php echo esc_url($source['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($source['title'] !== '' ? $source['title'] : $source['host']); ?><span class="es-sr"> (откроется в новой вкладке)</span></a><?php if ($source['date']) : ?><span class="es-sources__date">, <time datetime="<?php echo esc_attr($source['datetime']); ?>"><?php echo esc_html($source['date']); ?></time></span><?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                </section>
            <?php endif; ?>
        </div>

        <aside class="es-rail" aria-label="О материале">
            <div class="es-rail__block es-rail__block--topics">
                <span class="es-rail__label">Темы</span>
                <p class="es-topics-line">
                    <a href="<?php echo esc_url($a['category_url']); ?>"><?php echo esc_html($a['category_label']); ?></a><?php foreach ($a['tag_links'] as $tag) : ?><a href="<?php echo esc_url($tag['url']); ?>"><?php echo esc_html($tag['label']); ?></a><?php endforeach; ?>
                </p>
            </div>
            <?php if ($canonical !== '') : ?>
                <div class="es-rail__block es-share">
                    <span class="es-rail__label">Поделиться</span>
                    <div class="es-share__row">
                        <a class="es-share__btn" href="<?php echo esc_url($telegram); ?>" target="_blank" rel="noopener noreferrer">Telegram</a>
                        <a class="es-share__btn" href="<?php echo esc_url($vk); ?>" target="_blank" rel="noopener noreferrer">ВКонтакте</a>
                        <button type="button" class="es-share__btn" data-es-copy-link="<?php echo esc_attr($canonical); ?>" hidden>Ссылка</button>
                    </div>
                    <span class="es-share__status" role="status" data-es-copy-status></span>
                </div>
            <?php endif; ?>
        </aside>
    </div>

    <nav class="es-pager" aria-label="Материалы этого номера">
        <?php if ($a['prev']) : ?>
            <a class="es-pager__link" rel="prev" href="<?php echo esc_url($a['prev']['url']); ?>"><span class="es-pager__dir">← Предыдущий материал</span><span class="es-pager__title"><?php echo esc_html($a['prev']['headline']); ?></span></a>
        <?php endif; ?>
        <?php if ($a['next']) : ?>
            <a class="es-pager__link es-pager__link--next" rel="next" href="<?php echo esc_url($a['next']['url']); ?>"><span class="es-pager__dir">Следующий материал →</span><span class="es-pager__title"><?php echo esc_html($a['next']['headline']); ?></span></a>
        <?php endif; ?>
    </nav>

    <section class="es-also" aria-labelledby="es-also-title">
        <h2 class="es-also__title" id="es-also-title">Весь номер<?php echo $issue['number'] ? ' ' . esc_html($issue['number_label']) : ''; ?></h2>
        <ol class="es-also__list">
            <?php foreach ($issue['stories'] as $story) : ?>
                <li<?php echo $story['slug'] === $a['slug'] ? ' aria-current="true"' : ''; ?>>
                    <a href="<?php echo esc_url($story['url']); ?>"<?php echo $story['slug'] === $a['slug'] ? ' aria-current="page"' : ''; ?>>
                        <span class="es-also__num" aria-hidden="true"><?php echo esc_html($story['number_label']); ?></span>
                        <span class="es-also__head"><?php echo esc_html($story['headline']); ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ol>
        <p class="es-also__links"><a href="<?php echo esc_url($back); ?>">← Вернуться к номеру</a> <a href="<?php echo esc_url($v['urls']['archive']); ?>">Все выпуски</a></p>
    </section>
</article>
