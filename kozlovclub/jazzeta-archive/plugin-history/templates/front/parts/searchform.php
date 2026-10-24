<?php
/**
 * Search form (search page, archive). A plain GET form: works without JavaScript and with
 * plain permalinks (the route's own query arguments travel as hidden fields).
 * @var array $v view model; $v['form'] = ['id' => string, 'label' => string]
 */
if (!defined('ABSPATH')) {
    exit;
}
$form = (array) ($v['form'] ?? []);
$id = 'es-q-' . sanitize_key((string) ($form['id'] ?? 'main'));
$label = (string) ($form['label'] ?? 'Поиск по газете');
$action = (string) $v['urls']['search'];
$hidden = [];
$query = (string) wp_parse_url($action, PHP_URL_QUERY);
if ($query !== '') {
    parse_str($query, $hidden);
    $action = (string) strtok($action, '?');
}
$value = (string) ($v['search']['query'] ?? '');
// 0.5.5: a new query stays inside the chosen topic; the topic chip removes it.
$topic = (string) ($v['search']['topic'] ?? '');
if ($topic !== '') {
    $hidden['tema'] = $topic;
}
// 0.5.8: and inside the chosen rubric.
$rubric = (string) ($v['search']['rubric'] ?? '');
if ($rubric !== '') {
    $hidden['rubric'] = $rubric;
}
?>
<form class="es-search" role="search" action="<?php echo esc_url($action); ?>" method="get">
    <label class="es-search__label" for="<?php echo esc_attr($id); ?>"><?php echo esc_html($label); ?></label>
    <span class="es-search__row">
        <input class="es-search__input" type="search" id="<?php echo esc_attr($id); ?>" name="q" value="<?php echo esc_attr($value); ?>" maxlength="100" placeholder="Имя, название, слово" autocomplete="off" enterkeyhint="search">
        <?php foreach ($hidden as $name => $hiddenValue) : ?>
            <?php if (is_scalar($hiddenValue)) : ?><input type="hidden" name="<?php echo esc_attr((string) $name); ?>" value="<?php echo esc_attr((string) $hiddenValue); ?>"><?php endif; ?>
        <?php endforeach; ?>
        <button class="es-search__button" type="submit">
            <svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><circle cx="7" cy="7" r="4.6" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="m10.4 10.4 3.6 3.6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
            <span>Найти</span>
        </button>
    </span>
</form>
