<?php
/**
 * Evidence badge. Cards show the most cautious level of the story; articles show every level.
 * @var array $v ['levels' => string[], 'all' => bool]
 */

use Kozlov\Gazeta\Domain\Labels;

if (!defined('ABSPATH')) {
    exit;
}
$order = ['attributed_single', 'verified_primary', 'verified_independent'];
$levels = array_values(array_intersect($order, (array) $v['levels']));
if (!$levels) {
    return;
}
if (empty($v['all'])) {
    $levels = [$levels[0]];
}
$short = [
    'verified_primary' => 'Первоисточник',
    'verified_independent' => 'Два независимых источника',
    'attributed_single' => 'Один источник',
];
foreach ($levels as $level) : ?>
    <span class="es-badge es-badge--<?php echo esc_attr($level); ?>" title="<?php echo esc_attr(Labels::LEVELS[$level]); ?>">
        <svg class="es-badge__icon" viewBox="0 0 16 16" aria-hidden="true" focusable="false">
            <?php if ($level === 'verified_independent') : ?>
                <path d="M5.5 2 9 5.5 5.5 9 2 5.5Z"/><path d="M10.5 7 14 10.5 10.5 14 7 10.5Z"/>
            <?php elseif ($level === 'verified_primary') : ?>
                <path d="M8 1.5 14.5 8 8 14.5 1.5 8Z"/>
            <?php else : ?>
                <path d="M8 2.3 13.7 8 8 13.7 2.3 8Z" fill="none" stroke-width="1.6"/>
            <?php endif; ?>
        </svg>
        <span class="es-badge__text"><?php echo esc_html(!empty($v['all']) ? Labels::LEVELS[$level] : $short[$level]); ?></span>
    </span>
<?php endforeach; ?>
