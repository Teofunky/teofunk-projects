<?php
/**
 * Article editor.
 * @var array $v
 */

use Kozlov\Gazeta\Admin\Actions;
use Kozlov\Gazeta\Admin\Admin;
use Kozlov\Gazeta\Admin\Ui;
use Kozlov\Gazeta\Domain\Duplicates;
use Kozlov\Gazeta\Domain\Labels;
use Kozlov\Gazeta\Front\View;
use Kozlov\Gazeta\Http\Router;

if (!defined('ABSPATH')) {
    exit;
}
$a = $v['article'];
$issue = $v['issue'];
$id = (int) $issue['id'];
$version = (int) $issue['version'];
$sourceIds = array_column($a['sources'], 'id');
$localTime = static function ($iso): string {
    if (!$iso) {
        return '';
    }
    try {
        return (new \DateTimeImmutable($iso))->setTimezone(new \DateTimeZone('Europe/Moscow'))->format('Y-m-d\TH:i');
    } catch (\Exception $e) {
        return '';
    }
};
$sourceRow = static function ($index, array $s) use ($localTime) {
    $n = 'article[sources][' . $index . ']';
    $url = (string) ($s['url'] ?? '');
    ?>
    <fieldset class="es-repeat-row es-source-row" data-es-row>
        <legend class="es-repeat-row__legend">
            <span class="es-repeat-row__badge" data-es-source-label><?php echo esc_html($s['id'] ?? ''); ?></span>
            <span data-es-source-title><?php echo esc_html($s['publisher'] ?? 'Новый источник'); ?></span>
        </legend>
        <div class="es-grid-2">
            <div class="es-field es-field--narrow">
                <label>ID</label>
                <input type="text" name="<?php echo esc_attr($n); ?>[id]" value="<?php echo esc_attr($s['id'] ?? ''); ?>" required pattern="[A-Za-z0-9_\-]{1,64}" data-es-source-id>
            </div>
            <div class="es-field">
                <label>Издание или организация</label>
                <input type="text" name="<?php echo esc_attr($n); ?>[publisher]" value="<?php echo esc_attr($s['publisher'] ?? ''); ?>" required data-es-source-publisher>
            </div>
        </div>
        <div class="es-field">
            <label>Заголовок публикации</label>
            <input type="text" name="<?php echo esc_attr($n); ?>[title]" value="<?php echo esc_attr($s['title'] ?? ''); ?>" required>
        </div>
        <div class="es-field">
            <label>Ссылка <span class="es-muted">— только https</span></label>
            <div class="es-input-with-action">
                <input type="url" name="<?php echo esc_attr($n); ?>[url]" value="<?php echo esc_attr($url); ?>" required pattern="https://.+" data-es-url>
                <a class="es-btn es-btn--small" href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener noreferrer" data-es-open-url>Открыть ↗</a>
            </div>
        </div>
        <div class="es-grid-3">
            <div class="es-field">
                <label>Тип источника</label>
                <select name="<?php echo esc_attr($n); ?>[type]">
                    <?php foreach (Labels::SOURCE_TYPES as $key => $label) : ?>
                        <option value="<?php echo esc_attr($key); ?>"<?php selected(($s['type'] ?? 'official'), $key); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="es-field">
                <label>Опубликовано</label>
                <input type="text" name="<?php echo esc_attr($n); ?>[published_at]" value="<?php echo esc_attr($s['published_at'] ?? ''); ?>" placeholder="2026-10-20" inputmode="numeric">
            </div>
            <div class="es-field">
                <div class="es-label-row">
                    <label>Проверено редакцией <span class="es-muted">(МСК)</span></label>
                    <button type="button" class="es-linkbtn" data-es-now title="Подставить текущее московское время">сейчас</button>
                </div>
                <input type="datetime-local" name="<?php echo esc_attr($n); ?>[verification_checked_at]" value="<?php echo esc_attr($localTime($s['verification_checked_at'] ?? '')); ?>" data-es-checked-at>
            </div>
        </div>
        <div class="es-field">
            <label>Цепочка зависимости <span class="es-muted">— одинаковая у перепечаток одного пресс-релиза</span></label>
            <input type="text" name="<?php echo esc_attr($n); ?>[dependency_group]" value="<?php echo esc_attr($s['dependency_group'] ?? ''); ?>">
        </div>
        <details class="es-details es-details--quiet">
            <summary>Идентификаторы публикации (необязательно)</summary>
            <div class="es-grid-2">
                <div class="es-field"><label>ID публикации у источника</label><input type="text" name="<?php echo esc_attr($n); ?>[publication_id]" value="<?php echo esc_attr($s['publication_id'] ?? ''); ?>"></div>
                <div class="es-field"><label>SHA-256 текста источника</label><input type="text" name="<?php echo esc_attr($n); ?>[content_hash]" value="<?php echo esc_attr($s['content_hash'] ?? ''); ?>" pattern="[a-f0-9]{64}"></div>
            </div>
        </details>
        <button type="button" class="es-link es-link--danger" data-es-remove-row>Убрать источник</button>
    </fieldset>
    <?php
};
$claimRow = static function ($index, array $c) use ($sourceIds) {
    $n = 'article[claims][' . $index . ']';
    $selected = (array) ($c['source_ids'] ?? []);
    $level = (string) ($c['verification'] ?? 'unverified');
    ?>
    <fieldset class="es-repeat-row es-claim-row es-claim-row--<?php echo esc_attr($level); ?>" data-es-row>
        <legend class="es-repeat-row__legend"><span class="es-repeat-row__badge"><?php echo esc_html($c['id'] ?? ''); ?></span> Утверждение</legend>
        <div class="es-grid-2">
            <div class="es-field es-field--narrow">
                <label>ID</label>
                <input type="text" name="<?php echo esc_attr($n); ?>[id]" value="<?php echo esc_attr($c['id'] ?? ''); ?>" required pattern="[A-Za-z0-9_\-]{1,64}">
            </div>
            <div class="es-field">
                <label>Уровень подтверждения</label>
                <select name="<?php echo esc_attr($n); ?>[verification]" data-es-level>
                    <?php foreach (Labels::LEVELS as $key => $label) : ?>
                        <option value="<?php echo esc_attr($key); ?>"<?php selected($level, $key); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="es-field">
            <label>Существенное утверждение текста</label>
            <textarea name="<?php echo esc_attr($n); ?>[statement]" rows="2" required data-es-autogrow><?php echo esc_textarea($c['statement'] ?? ''); ?></textarea>
        </div>
        <div class="es-field">
            <span class="es-label-like">Подтверждающие источники</span>
            <div class="es-chips-select" data-es-claim-sources data-name="<?php echo esc_attr($n); ?>[source_ids][]">
                <?php foreach ($sourceIds as $sid) : ?>
                    <label class="es-chip-check"><input type="checkbox" name="<?php echo esc_attr($n); ?>[source_ids][]" value="<?php echo esc_attr($sid); ?>"<?php checked(in_array($sid, $selected, true)); ?>><span><?php echo esc_html($sid); ?></span></label>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="es-field">
            <label>Доказательство, точная выдержка или формула атрибуции</label>
            <textarea name="<?php echo esc_attr($n); ?>[editorial_note]" rows="2" data-es-autogrow><?php echo esc_textarea($c['editorial_note'] ?? ''); ?></textarea>
        </div>
        <button type="button" class="es-link es-link--danger" data-es-remove-row>Убрать утверждение</button>
    </fieldset>
    <?php
};
$quoteRow = static function ($index, array $q) use ($sourceIds) {
    $n = 'article[quotes][' . $index . ']';
    ?>
    <fieldset class="es-repeat-row" data-es-row>
        <legend class="es-repeat-row__legend">Прямая цитата</legend>
        <div class="es-grid-2">
            <div class="es-field"><label>Оригинал</label><textarea name="<?php echo esc_attr($n); ?>[original]" rows="2" data-es-autogrow><?php echo esc_textarea($q['original'] ?? ''); ?></textarea></div>
            <div class="es-field"><label>Перевод на русский</label><textarea name="<?php echo esc_attr($n); ?>[translation_ru]" rows="2" data-es-autogrow><?php echo esc_textarea($q['translation_ru'] ?? ''); ?></textarea></div>
        </div>
        <div class="es-grid-2">
            <div class="es-field">
                <label>Источник цитаты</label>
                <select name="<?php echo esc_attr($n); ?>[source_id]" data-es-source-select>
                    <?php foreach ($sourceIds as $sid) : ?><option value="<?php echo esc_attr($sid); ?>"<?php selected(($q['source_id'] ?? ''), $sid); ?>><?php echo esc_html($sid); ?></option><?php endforeach; ?>
                </select>
            </div>
            <label class="es-check es-check--inline"><input type="checkbox" name="<?php echo esc_attr($n); ?>[verified]" value="1"<?php checked(!empty($q['verified'])); ?> required> <span>Оригинал и перевод сверены</span></label>
        </div>
        <button type="button" class="es-link es-link--danger" data-es-remove-row>Убрать цитату</button>
    </fieldset>
    <?php
};
?>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="es-editor" data-es-editor data-es-dirty-guard data-es-draft-key="<?php echo esc_attr($v['draft_key']); ?>">
    <?php Actions::fields('article', $id, $version); ?>
    <input type="hidden" name="article[id]" value="<?php echo esc_attr($a['id']); ?>">

    <div class="es-layout es-layout--editor">
        <div class="es-main-col">
            <section class="es-card es-write">
                <div class="es-field es-field--headline">
                    <label for="es-headline">Заголовок <span class="es-hint" data-es-count-for="es-headline" data-es-count-mode="words" data-es-range="6-12">6–12 слов: кто, что сделал, в чём суть</span></label>
                    <textarea id="es-headline" name="article[headline]" rows="2" required minlength="6" maxlength="230" data-es-autogrow data-es-count><?php echo esc_textarea($a['headline']); ?></textarea>
                </div>
                <div class="es-field es-field--lead">
                    <label for="es-lead">Лид <span class="es-hint" data-es-count-for="es-lead" data-es-range="80-320">1–2 предложения: кто, что, когда и почему важно</span></label>
                    <textarea id="es-lead" name="article[lead]" rows="3" required minlength="15" maxlength="1500" data-es-autogrow data-es-count><?php echo esc_textarea($a['lead']); ?></textarea>
                </div>
                <div class="es-field es-field--body">
                    <label for="es_article_body">Текст <span class="es-hint">обычно 100–180 слов; абзацы, выделения, ссылки, списки и цитатные блоки</span></label>
                    <?php
                    wp_editor($v['body_html'], 'es_article_body', [
                        'textarea_name' => 'body',
                        'media_buttons' => false,
                        'textarea_rows' => 14,
                        'teeny' => true,
                        'quicktags' => ['buttons' => 'strong,em,link,ul,ol,li,block,close'],
                        'tinymce' => ['toolbar1' => 'bold,italic,bullist,numlist,blockquote,link,unlink,undo,redo', 'content_style' => 'body{font-family:Georgia,serif;font-size:17px;line-height:1.6;max-width:680px}'],
                    ]);
                    ?>
                </div>
                <div class="es-field">
                    <label for="es-summary">Краткая выжимка <span class="es-hint" data-es-count-for="es-summary">одной фразой; уходит в описание страницы</span></label>
                    <textarea id="es-summary" name="article[summary]" rows="2" required minlength="10" maxlength="1500" data-es-autogrow data-es-count><?php echo esc_textarea($a['summary']); ?></textarea>
                </div>
            </section>

            <section class="es-card" aria-labelledby="es-sources-title">
                <div class="es-card__head">
                    <h2 class="es-card__title" id="es-sources-title">Источники</h2>
                    <p class="es-muted">Читатель видит издание, заголовок, тип и дату. Две ссылки на один пресс-релиз — одна цепочка.</p>
                </div>
                <div class="es-repeat" data-es-repeat="sources">
                    <div class="es-repeat__items" data-es-items>
                        <?php foreach ($a['sources'] as $index => $source) { $sourceRow($index, $source); } ?>
                    </div>
                    <template data-es-template><?php $sourceRow('__INDEX__', ['id' => '', 'type' => 'independent_editorial']); ?></template>
                    <button type="button" class="es-btn es-btn--ghost" data-es-add-row>+ Источник</button>
                </div>
            </section>

            <section class="es-card" aria-labelledby="es-claims-title">
                <div class="es-card__head">
                    <h2 class="es-card__title" id="es-claims-title">Факты и доказательства</h2>
                    <p class="es-muted">Каждое существенное утверждение текста и соцверсий — отдельной строкой. «Не проверено» блокирует публикацию.</p>
                </div>
                <div class="es-repeat" data-es-repeat="claims">
                    <div class="es-repeat__items" data-es-items>
                        <?php foreach ($a['claims'] as $index => $claim) { $claimRow($index, $claim); } ?>
                    </div>
                    <template data-es-template><?php $claimRow('__INDEX__', ['verification' => 'unverified']); ?></template>
                    <button type="button" class="es-btn es-btn--ghost" data-es-add-row>+ Утверждение</button>
                </div>
            </section>

            <section class="es-card" aria-labelledby="es-quotes-title">
                <div class="es-card__head">
                    <h2 class="es-card__title" id="es-quotes-title">Прямые цитаты</h2>
                    <p class="es-muted">Только дословно и после сверки с оригиналом. Если доступ к оригиналу ненадёжен — пишите косвенной речью.</p>
                </div>
                <div class="es-repeat" data-es-repeat="quotes">
                    <div class="es-repeat__items" data-es-items>
                        <?php foreach ($a['quotes'] as $index => $quote) { $quoteRow($index, $quote); } ?>
                    </div>
                    <template data-es-template><?php $quoteRow('__INDEX__', []); ?></template>
                    <button type="button" class="es-btn es-btn--ghost" data-es-add-row>+ Цитата</button>
                </div>
            </section>

            <section class="es-card" aria-labelledby="es-brief-title">
                <div class="es-card__head">
                    <h2 class="es-card__title" id="es-brief-title">Почему важно, контекст, что дальше</h2>
                    <p class="es-muted">Необязательные блоки над текстом статьи. Пустой блок читатель не увидит. Только подтверждённое; оценка редакции должна читаться как оценка. До <?php echo (int) \Kozlov\Gazeta\Data\Meta::BRIEF_MAX; ?> знаков.</p>
                </div>
                <?php foreach (['why' => ['Почему это важно', 'Чем событие существенно для читателя — одной-двумя фразами.'], 'context' => ['Контекст', 'Что нужно знать, чтобы понять новость.'], 'next' => ['Что дальше', 'Только подтверждённый следующий шаг: дата, релиз, концерт.']] as $key => [$label, $hint]) : ?>
                    <div class="es-field">
                        <label for="es-brief-<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?> <span class="es-hint"><?php echo esc_html($hint); ?></span></label>
                        <textarea id="es-brief-<?php echo esc_attr($key); ?>" name="brief[<?php echo esc_attr($key); ?>]" rows="2" maxlength="<?php echo (int) \Kozlov\Gazeta\Data\Meta::BRIEF_MAX; ?>" data-es-autogrow data-es-count><?php echo esc_textarea($v['brief'][$key] ?? ''); ?></textarea>
                    </div>
                <?php endforeach; ?>
                <label class="es-switch">
                    <input type="checkbox" name="facts_visible" value="1"<?php checked($v['facts_visible']); ?>>
                    <span class="es-switch__ui" aria-hidden="true"></span>
                    <span><strong>Показать читателю «Что известно»</strong> — подтверждённые факты и факты из одного источника; непроверенные не показываются никогда.</span>
                </label>
                <?php if ($v['facts_visible'] && !$v['facts_preview']['confirmed'] && !$v['facts_preview']['single']) : ?>
                    <p class="es-hint">Сейчас показывать нечего: нет фактов со статусом проверки.</p>
                <?php endif; ?>
            </section>

            <section class="es-card" aria-labelledby="es-social-title">
                <div class="es-card__head">
                    <h2 class="es-card__title" id="es-social-title">Соцверсии</h2>
                    <p class="es-muted">В номере может быть до пяти. Сейчас в номере: <?php echo (int) $v['social_count']; ?>. Автопубликации нет.</p>
                </div>
                <label class="es-switch">
                    <input type="checkbox" name="social_enabled" value="1"<?php checked((bool) $v['social']); ?> data-es-social-toggle>
                    <span class="es-switch__ui" aria-hidden="true"></span>
                    <span><strong>Включить материал в «Номер в двух словах»</strong></span>
                </label>
                <div class="es-social-edit" data-es-social-fields>
                    <div class="es-social-edit__fields">
                        <div class="es-field">
                            <label for="es-tg">Telegram <span class="es-hint" data-es-count-for="es-tg" data-es-range="60-300">1–2 информативные фразы</span></label>
                            <textarea id="es-tg" name="telegram_sentence" rows="3" maxlength="600" data-es-autogrow data-es-count><?php echo esc_textarea($v['social']['telegram_sentence'] ?? $a['summary']); ?></textarea>
                        </div>
                        <div class="es-field">
                            <label for="es-ig">Instagram <span class="es-hint" data-es-count-for="es-ig" data-es-range="40-110">ровно одно предложение без кликбейта</span></label>
                            <textarea id="es-ig" name="instagram_sentence" rows="3" maxlength="600" data-es-autogrow data-es-count data-es-card-text><?php echo esc_textarea($v['social']['instagram_sentence'] ?? $a['summary']); ?></textarea>
                        </div>
                    </div>
                    <div class="es-minicard" aria-hidden="true">
                        <?php [$cardOver, $cardMain] = \Kozlov\Gazeta\Settings\Appearance::nameParts(); ?>
                        <span class="es-minicard__mast"><?php echo $cardOver !== '' ? esc_html($cardOver) . '<br>' : ''; ?><?php echo esc_html($cardMain); ?></span>
                        <span class="es-minicard__rubric" data-es-card-rubric><?php echo esc_html(Labels::region($a['region']) . ' · ' . Labels::category($a['category'])); ?></span>
                        <span class="es-minicard__text" data-es-card-preview><?php echo esc_html($v['social']['instagram_sentence'] ?? $a['summary']); ?></span>
                        <span class="es-minicard__foot"><span><?php echo esc_html(Labels::date($issue['edition_date'])); ?></span><span>4:5</span></span>
                    </div>
                </div>
            </section>

            <section class="es-card" aria-labelledby="es-extra-title">
                <div class="es-card__head"><h2 class="es-card__title" id="es-extra-title">Иллюстрация, раскрытие, сюжет</h2></div>
                <div class="es-media" data-es-media>
                    <div class="es-media__preview" data-es-media-preview>
                        <?php if (!empty($a['media_rights']['image_url'])) : ?>
                            <img src="<?php echo esc_url($a['media_rights']['image_url']); ?>" alt="">
                        <?php else : ?>
                            <span>Без фото — материал выйдет текстом</span>
                        <?php endif; ?>
                    </div>
                    <div class="es-media__fields">
                        <div class="es-field">
                            <label>Фото из медиатеки сайта</label>
                            <div class="es-input-with-action">
                                <input type="url" name="article[media_rights][image_url]" value="<?php echo esc_attr($a['media_rights']['image_url'] ?? ''); ?>" placeholder="https://kozlovclub.ru/wp-content/uploads/…" data-es-media-url>
                                <button type="button" class="es-btn es-btn--small" data-es-media-pick>Выбрать</button>
                            </div>
                        </div>
                        <div class="es-grid-2">
                            <div class="es-field">
                                <label>Права</label>
                                <select name="article[media_rights][status]">
                                    <?php foreach (Labels::MEDIA_RIGHTS as $key => $label) : ?><option value="<?php echo esc_attr($key); ?>"<?php selected($a['media_rights']['status'], $key); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?>
                                </select>
                            </div>
                            <div class="es-field">
                                <label>Автор / правообладатель</label>
                                <input type="text" name="article[media_rights][credit]" value="<?php echo esc_attr($a['media_rights']['credit']); ?>">
                            </div>
                        </div>
                    </div>
                </div>
                <?php if (!$v['is_new']) : ?>
                    <?php View::render('admin/parts/focus', ['id' => 'article', 'name' => 'focus', 'src' => (string) $v['focus_src'], 'focus' => $v['focus'], 'save' => true, 'published' => $v['issue_published']]); ?>
                <?php endif; ?>
                <div class="es-field">
                    <label for="es-disclosure">Связь с издателем <span class="es-hint">видна читателю; обязательна для материалов о клубе и Jazzist</span></label>
                    <textarea id="es-disclosure" name="article[conflict_disclosure]" rows="2" data-es-autogrow><?php echo esc_textarea((string) ($a['conflict_disclosure'] ?? '')); ?></textarea>
                </div>
                <?php if ($v['matches'] || $v['recent']) : ?>
                    <div class="es-alert es-alert--warn">
                        <strong>Похоже на уже опубликованное.</strong>
                        <ul>
                            <?php foreach ($v['matches'] as $match) : ?>
                                <li><a href="<?php echo esc_url(Router::article($match['slug'])); ?>" target="_blank" rel="noopener"><?php echo esc_html($match['slug']); ?></a> — <?php echo esc_html(implode(', ', array_map(static function ($r) { return Duplicates::REASONS[$r] ?? $r; }, $match['reasons']))); ?></li>
                            <?php endforeach; ?>
                            <?php foreach ($v['recent'] as $recent) : ?>
                                <li>Сюжет выходил <?php echo esc_html(Labels::date($recent['edition_date'])); ?>: <a href="<?php echo esc_url(Router::article($recent['slug'])); ?>" target="_blank" rel="noopener"><?php echo esc_html($recent['slug']); ?></a></li>
                            <?php endforeach; ?>
                        </ul>
                        <span>Свяжите материалы ниже и запишите, что произошло нового.</span>
                    </div>
                <?php endif; ?>
                <div class="es-grid-2">
                    <div class="es-field">
                        <label for="es-prev">Предыдущие материалы сюжета <span class="es-hint">адреса через запятую</span></label>
                        <input id="es-prev" type="text" name="previous_article_ids" value="<?php echo esc_attr(implode(', ', $a['previous_article_ids'] ?? [])); ?>" placeholder="2026-10-14-story-…">
                    </div>
                    <div class="es-field">
                        <label for="es-newfact">Что нового по сравнению с прошлым материалом</label>
                        <textarea id="es-newfact" name="new_fact" rows="2" data-es-autogrow><?php echo esc_textarea((string) ($a['development_note'] ?? '')); ?></textarea>
                    </div>
                </div>
                <div class="es-field">
                    <label for="es-private">Приватные заметки материала <span class="es-hint">по одной на строку; читатели не видят</span></label>
                    <textarea id="es-private" name="editorial_notes" rows="3" data-es-autogrow><?php echo esc_textarea(implode("\n", $a['editorial_notes'])); ?></textarea>
                </div>
            </section>
        </div>

        <aside class="es-side-col es-side-col--sticky">
            <section class="es-card es-status-box">
                <?php if ($v['is_new']) : ?>
                    <p class="es-review es-review--todo">Новый материал</p>
                <?php elseif ($v['reviewed']) : ?>
                    <p class="es-review es-review--ok">✓ Проверено в этой версии</p>
                    <p class="es-muted"><?php echo esc_html($v['reviewer'] . ' · ' . Ui::when($v['reviewed_at'])); ?>. Любая правка текста снимет отметку.</p>
                <?php else : ?>
                    <p class="es-review es-review--todo">Нужна проверка человеком</p>
                <?php endif; ?>
                <?php if ($v['blockers']) : ?>
                    <ul class="es-problems">
                        <?php foreach ($v['blockers'] as $problem) : ?><li><?php echo esc_html($problem); ?></li><?php endforeach; ?>
                    </ul>
                <?php elseif (!$v['is_new']) : ?>
                    <p class="es-muted">Структурных замечаний к сохранённой версии нет.</p>
                <?php endif; ?>
            </section>

            <section class="es-card es-props">
                <span class="es-label-like">Регион</span>
                <div class="es-segmented" role="radiogroup" aria-label="Регион">
                    <?php foreach (Labels::REGIONS as $key => $label) : ?>
                        <label><input type="radio" name="article[region]" value="<?php echo esc_attr($key); ?>"<?php checked($a['region'], $key); ?> data-es-rubric-part><span><?php echo esc_html($label); ?></span></label>
                    <?php endforeach; ?>
                </div>
                <div class="es-field">
                    <label for="es-category">Рубрика</label>
                    <select id="es-category" name="article[category]" data-es-rubric-part>
                        <?php foreach (Labels::CATEGORIES as $key => $label) : ?><option value="<?php echo esc_attr($key); ?>"<?php selected($a['category'], $key); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <span class="es-label-like">Место на полосе</span>
                <div class="es-segmented es-segmented--wrap" role="radiogroup" aria-label="Место на полосе">
                    <?php foreach (Labels::PLACEMENTS as $key => $label) : ?>
                        <label><input type="radio" name="article[placement]" value="<?php echo esc_attr($key); ?>"<?php checked($a['placement'], $key); ?>><span><?php echo esc_html($label); ?></span></label>
                    <?php endforeach; ?>
                </div>
                <div class="es-grid-2">
                    <div class="es-field">
                        <label for="es-score">Значимость</label>
                        <input id="es-score" type="number" name="article[story_score]" min="0" max="100" step="1" value="<?php echo esc_attr((string) $a['story_score']); ?>">
                    </div>
                    <div class="es-field">
                        <label for="es-event-date">Дата события</label>
                        <input id="es-event-date" type="date" name="article[event_date]" value="<?php echo esc_attr((string) ($a['event_date'] ?? '')); ?>">
                    </div>
                </div>
                <details class="es-details es-details--quiet">
                    <summary>Идентификаторы сюжета</summary>
                    <div class="es-field"><label>ID события</label><input type="text" name="article[event_id]" value="<?php echo esc_attr($a['event_id'] ?? $a['story_group_id']); ?>" pattern="[a-z0-9][a-z0-9_\-]{0,119}"></div>
                    <div class="es-field"><label>ID сюжета (поиск повторов)</label><input type="text" name="article[story_group_id]" value="<?php echo esc_attr($a['story_group_id']); ?>" pattern="[a-z0-9][a-z0-9_\-]{0,119}"></div>
                    <p class="es-muted">ID материала: <code><?php echo esc_html($a['id']); ?></code>. Адрес статьи после первого сохранения не меняется.</p>
                </details>
            </section>

            <section class="es-card es-review-box" id="es-review">
                <div class="es-field">
                    <label for="es-review-note">Итог проверки <span class="es-hint">факты, источники, атрибуция, цитаты, права</span></label>
                    <textarea id="es-review-note" name="review_note" rows="4" data-es-autogrow><?php echo esc_textarea($v['review_note']); ?></textarea>
                </div>
                <label class="es-check"><input type="checkbox" name="reviewed" value="1"> <span>Я проверил текущую версию материала и его соцверсии</span></label>
                <div class="es-actions es-actions--stack">
                    <button type="submit" name="intent" value="save" class="es-btn">Сохранить</button>
                    <button type="submit" name="intent" value="review" class="es-btn es-btn--primary">Сохранить и подтвердить</button>
                </div>
                <p class="es-savehint" data-es-dirty-label>Изменений нет</p>
                <p class="es-muted es-kbd-hint">⌘S / Ctrl+S — сохранить</p>
            </section>

            <?php if (!$v['is_new']) : ?>
                <details class="es-card es-details es-danger">
                    <summary>Убрать материал из номера</summary>
                    <p class="es-muted">Материал исчезнет из рабочей версии. Опубликованная версия номера изменится только после нового утверждения.</p>
                    <label class="es-check"><input type="checkbox" name="confirm_delete" value="1"> <span>Да, убрать</span></label>
                    <button type="submit" name="intent" value="delete" class="es-btn es-btn--danger" formnovalidate>Убрать материал</button>
                </details>
            <?php endif; ?>
        </aside>
    </div>
    <div class="es-mobile-save" aria-hidden="true">
        <span class="es-mobile-save__state">Есть несохранённые изменения</span>
        <a class="es-mobile-save__link" href="#es-review" tabindex="-1">К проверке ↓</a>
        <button type="submit" name="intent" value="save" class="es-btn es-btn--primary es-btn--small" tabindex="-1">Сохранить</button>
    </div>
    <input type="hidden" name="form_complete" value="yes">
</form>
