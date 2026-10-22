<?php
/**
 * The newspaper page body. One semantic markup for all three reading modes:
 * «Газета», «Современный», «Кратко» differ only by the data-es-design attribute on <html>.
 * @var array $v view model from Front\Controller
 */

use Kozlov\Gazeta\Front\View;

if (!defined('ABSPATH')) {
    exit;
}
?>
<div id="es-newspaper" class="es-app es-view--<?php echo esc_attr($v['view']); ?><?php echo $v['private'] ? ' es-is-private' : ''; ?>" data-es-view="<?php echo esc_attr($v['view']); ?>">
    <a class="es-skip" href="#es-main">К содержанию газеты</a>
    <div class="es-topbar">
        <div class="es-topbar__inner">
            <a class="es-topbar__club" href="<?php echo esc_url($v['urls']['club']); ?>">
                <svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M7 3 2 8l5 5M2.5 8H14" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <span>На сайт Клуба Козлова</span>
            </a>
            <button type="button" class="es-topbar__prefs" aria-expanded="false" aria-controls="es-prefs" data-es-prefs-toggle hidden>
                <svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M2 4h7M12 4h2M2 12h2M7 12h7" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" fill="none"/><circle cx="10.5" cy="4" r="1.6" fill="none" stroke="currentColor" stroke-width="1.5"/><circle cx="5.5" cy="12" r="1.6" fill="none" stroke="currentColor" stroke-width="1.5"/></svg>
                <span>Оформление</span>
            </button>
            <?php View::render('front/parts/prefs', $v); ?>
        </div>
    </div>
    <?php View::render('front/parts/status', $v); ?>
    <div class="es-page">
        <?php View::render('front/parts/masthead', $v); ?>
        <main id="es-main" class="es-main" tabindex="-1">
            <?php
            switch ($v['view']) {
                case 'issue':
                    View::render('front/issue', $v);
                    break;
                case 'article':
                    View::render('front/article', $v);
                    break;
                case 'archive':
                    View::render('front/archive', $v);
                    break;
                case 'search':
                    View::render('front/search', $v);
                    break;
                case 'empty':
                    View::render('front/empty', $v);
                    break;
                default:
                    View::render('front/notfound', $v);
            }
            ?>
        </main>
        <?php View::render('front/parts/footer', $v); ?>
    </div>
    <div class="es-progress" aria-hidden="true"><span></span></div>
</div>
