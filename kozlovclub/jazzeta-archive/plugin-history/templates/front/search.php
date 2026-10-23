<?php
/**
 * «Поиск по газете»: published materials only, best matches first.
 * @var array $v
 */

use Kozlov\Gazeta\Domain\Labels;
use Kozlov\Gazeta\Front\View;

if (!defined('ABSPATH')) {
    exit;
}
$search = $v['search'];
$chips = (array) ($search['topics'] ?? []) + ['rubrics' => [], 'tags' => []];
$topicLabel = (string) ($search['topic_label'] ?? '');
$inTopic = $topicLabel !== '' ? ' в теме «' . $topicLabel . '»' : '';
$byQuery = !empty($search['has_words']) ? ' по запросу «' . $search['query'] . '»' : '';
?>
<section class="es-searchpage" aria-labelledby="es-search-title">
    <header class="es-page-head">
        <h1 class="es-page-head__title" id="es-search-title">Поиск по газете</h1>
        <?php View::render('front/parts/searchform', $v + ['form' => ['id' => 'page', 'label' => $topicLabel !== '' ? 'Что найти в теме «' . $topicLabel . '»' : 'Что найти в опубликованных номерах']]); ?>
    </header>

    <?php if ($chips['rubrics'] || $chips['tags']) : ?>
        <nav class="es-topicnav" aria-label="Рубрики и темы газеты">
            <?php foreach (['rubrics' => 'Рубрики', 'tags' => 'Темы'] as $group => $title) : ?>
                <?php if (!$chips[$group]) : ?>
                    <?php continue; ?>
                <?php endif; ?>
                <div class="es-topicnav__row">
                    <span class="es-topicnav__label"><?php echo esc_html($title); ?></span>
                    <ul>
                        <?php foreach ($chips[$group] as $chip) : ?>
                            <li><a class="es-topicnav__chip" href="<?php echo esc_url($chip['url']); ?>"<?php echo $chip['active'] ? ' aria-current="true"' : ''; ?>><?php echo esc_html($chip['label']); ?><?php if ($chip['count'] > 0) : ?> <span class="es-topicnav__count"><?php echo (int) $chip['count']; ?></span><?php endif; ?><?php if ($chip['active']) : ?><span class="es-topicnav__x" aria-hidden="true">×</span><span class="es-sr"> — показать все темы</span><?php endif; ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>

    <?php if (!empty($search['unavailable'])) : ?>
        <p class="es-searchpage__note" role="status">Поиск временно недоступен. Все номера по датам — в разделе <a href="<?php echo esc_url($v['urls']['archive']); ?>">«Все выпуски»</a>.</p>
    <?php elseif (!$search['searched']) : ?>
        <p class="es-searchpage__note">Ищите по именам, названиям и словам из заголовков и текстов всех опубликованных материалов. Форма слова не важна: «Дворжак» найдёт и «с&nbsp;Дворжаком».</p>
    <?php elseif (!$search['understood']) : ?>
        <p class="es-searchpage__note" role="status">Слишком короткий запрос. Добавьте имя или слово подлиннее.</p>
    <?php elseif (!$search['total']) : ?>
        <?php if ($byQuery === '') : ?>
            <p class="es-searchpage__note" role="status">В теме «<?php echo esc_html($topicLabel); ?>» пока нет опубликованных материалов. Все номера по датам — в разделе <a href="<?php echo esc_url($v['urls']['archive']); ?>">«Все выпуски»</a>.</p>
        <?php else : ?>
            <p class="es-searchpage__note" role="status"><?php echo esc_html(Labels::ucfirst(ltrim($byQuery . $inTopic))); ?> ничего не нашлось. Проверьте написание или попробуйте другое слово.<?php if ($inTopic !== '') : ?> <a href="<?php echo esc_url($search['topic_clear']); ?>">Искать во всех темах</a>.<?php endif; ?> Все номера по датам — в разделе <a href="<?php echo esc_url($v['urls']['archive']); ?>">«Все выпуски»</a>.</p>
        <?php endif; ?>
    <?php else : ?>
        <p class="es-searchpage__summary" role="status">
            <?php echo esc_html(Labels::plural((int) $search['total'], 'Найден', 'Найдено', 'Найдено') . ' ' . Labels::count((int) $search['total'], 'материал', 'материала', 'материалов') . $byQuery . $inTopic); ?><?php if ($search['pages'] > 1) : ?><span class="es-searchpage__page"> · страница <?php echo (int) $search['page']; ?> из <?php echo (int) $search['pages']; ?></span><?php endif; ?>
        </p>
        <ol class="es-results" start="<?php echo (int) (($search['page'] - 1) * \Kozlov\Gazeta\Data\Search::PER_PAGE + 1); ?>">
            <?php foreach ($search['items'] as $item) : ?>
                <li class="es-result">
                    <p class="es-result__meta">
                        <?php if ($item['rubric'] !== '') : ?><span class="es-result__rubric"><?php echo esc_html($item['rubric']); ?></span><?php endif; ?>
                        <?php if (!empty($item['tags'])) : ?><span class="es-result__tags"><?php echo esc_html(implode(' · ', $item['tags'])); ?></span><?php endif; ?>
                        <a class="es-result__issue" href="<?php echo esc_url($item['issue_url']); ?>"><time datetime="<?php echo esc_attr($item['date']); ?>"><?php echo esc_html($item['human_date']); ?></time> · <?php echo esc_html($item['number_label']); ?></a>
                    </p>
                    <h2 class="es-result__title"><a href="<?php echo esc_url($item['url']); ?>"><?php echo $item['headline_html']; // Escaped by Data\Search::highlight(). ?></a></h2>
                    <?php if ($item['snippet_html'] !== '') : ?><p class="es-result__text"><?php echo $item['snippet_html']; // Escaped by Data\Search::snippet(). ?></p><?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
        <?php if ($search['pages'] > 1) : ?>
            <nav class="es-pagination" aria-label="Страницы результатов поиска">
                <?php if ($search['prev']) : ?><a rel="prev" href="<?php echo esc_url($search['prev']); ?>">← Назад</a><?php else : ?><span></span><?php endif; ?>
                <span class="es-pagination__state">Страница <?php echo (int) $search['page']; ?> из <?php echo (int) $search['pages']; ?></span>
                <?php if ($search['next']) : ?><a rel="next" href="<?php echo esc_url($search['next']); ?>">Дальше →</a><?php else : ?><span></span><?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>
