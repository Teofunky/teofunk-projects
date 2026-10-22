<?php

namespace Kozlov\Gazeta\Admin\Screens;

use Kozlov\Gazeta\Admin\Ui;
use Kozlov\Gazeta\Runtime\Mode;

if (!defined('ABSPATH')) {
    exit;
}

/** Mode and recovery: closed / open / paused, fault details, finishing an in-place upgrade. */
final class ModeScreen
{
    public static function render(): void
    {
        if (!current_user_can('es_edit') && !current_user_can('manage_options')) {
            wp_die('Нет прав.', '', ['response' => 403]);
        }
        $state = Mode::state();
        $fault = Mode::fault();
        $canStop = current_user_can('manage_options') || Mode::isOwner();
        Ui::open('Режим газеты', 'es-runtime', ['subtitle' => 'Кто видит газету и как её быстро остановить']);
        if (!Mode::ready()) {
            self::upgradeBox();
        }
        if ($fault) {
            echo '<div class="es-alert es-alert--error"><strong>Газета остановлена из-за ошибки.</strong> '
                . esc_html(($fault['at'] ?? '') . ' · ' . ($fault['type'] ?? '') . ' · ' . ($fault['file'] ?? '') . ':' . ($fault['line'] ?? ''))
                . '. Подробности — в журнале ошибок сервера. Устраните причину, затем верните закрытый режим.</div>';
        }
        $cards = [
            Mode::CLOSED => ['Закрытый тест', 'Газету видят только пользователи с правом редакции. Утверждённые выпуски скрыты от читателей и поисковиков.'],
            Mode::OPEN => ['Открыта читателям', 'Все утверждённые выпуски доступны по адресу /gazeta/, появляются в карте сайта и счётчике.'],
            Mode::PAUSED => ['Остановлена', 'Газету не видит никто, импорт и правки запрещены. Данные сохраняются.'],
        ];
        echo '<div class="es-modes">';
        foreach ($cards as $mode => [$title, $text]) {
            $current = $state['mode'] === $mode;
            echo '<section class="es-card es-modecard es-modecard--' . esc_attr($mode) . ($current ? ' is-current' : '') . '">';
            echo '<p class="es-eyebrow">' . ($current ? 'Сейчас' : 'Режим') . '</p>';
            echo '<h2 class="es-modecard__title"><span class="es-mode__dot" aria-hidden="true"></span>' . esc_html($title) . '</h2>';
            echo '<p>' . esc_html($text) . '</p>';
            if ($current) {
                echo '<p class="es-muted">С ' . esc_html(Ui::when(gmdate('Y-m-d H:i:s', (int) strtotime($state['changed_at'] ?: 'now')))) . ' · ' . esc_html(Ui::userName($state['changed_by'])) . '</p>';
            } else {
                self::action($mode, $canStop, (bool) $fault);
            }
            echo '</section>';
        }
        echo '</div>';
        self::emergency();
        Ui::close();
    }

    private static function action(string $mode, bool $canStop, bool $fault): void
    {
        if ($mode === Mode::OPEN) {
            if (!Mode::isOwner()) {
                echo '<p class="es-muted">Открыть газету может только выбранный владелец.</p>';
                return;
            }
            if (Mode::current() !== Mode::CLOSED || $fault || !Mode::ready()) {
                echo '<p class="es-muted">Открыть можно только из закрытого режима без ошибок.</p>';
                return;
            }
            self::form($mode, 'Открыть газету читателям', 'Я проверил резервную копию, исключения кэша для /gazeta/ и все утверждённые выпуски — они станут видны всем.', 'es-btn--publish');
            return;
        }
        if (!$canStop) {
            echo '<p class="es-muted">Менять режим могут администратор сайта и владелец газеты.</p>';
            return;
        }
        if ($mode === Mode::CLOSED) {
            self::form($mode, $fault ? 'Проверить модули и вернуть закрытый режим' : 'Закрыть для читателей', $fault ? 'Причина ошибки устранена; проверьте загрузку модулей и включите закрытый режим.' : '', '');
            return;
        }
        self::form($mode, 'Остановить газету', '', 'es-btn--danger');
    }

    private static function form(string $next, string $label, string $confirm, string $class): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="es_runtime_action"><input type="hidden" name="next" value="' . esc_attr($next) . '">';
        wp_nonce_field('es-runtime-' . Mode::current() . '-' . $next);
        if ($confirm !== '') {
            echo '<label class="es-check"><input type="checkbox" name="confirm" value="1" required> <span>' . esc_html($confirm) . '</span></label>';
        }
        echo '<button type="submit" class="es-btn ' . esc_attr($class) . '">' . esc_html($label) . '</button></form>';
    }

    private static function upgradeBox(): void
    {
        echo '<section class="es-card es-upgrade"><h2 class="es-card__title">Завершите обновление до ' . esc_html(ES_VERSION) . '</h2>';
        echo '<p>Файлы новой версии на месте, но таблицы газеты ещё не проверены этой версией (так бывает, если ZIP загрузили поверх включённого плагина). Проверка создаёт только недостающее и ничего не удаляет и не переписывает.</p>';
        if (current_user_can('manage_options')) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="es_runtime_action"><input type="hidden" name="next" value="upgrade">';
            wp_nonce_field('es-runtime-' . Mode::current() . '-upgrade');
            echo '<label class="es-check"><input type="checkbox" name="confirm" value="1" required> <span>Резервная копия базы сделана.</span></label>';
            echo '<button type="submit" class="es-btn es-btn--primary">Завершить обновление</button></form>';
        } else {
            echo '<p class="es-muted">Нужны права администратора сайта.</p>';
        }
        echo '</section>';
    }

    private static function emergency(): void
    {
        echo '<section class="es-card es-emergency"><h2 class="es-card__title">Если админка не открывается</h2><ol>';
        echo '<li>В файловом менеджере хостинга создайте пустой файл <code>wp-content/plugins/kozlov-jazzeta/DISABLE-GAZETA</code> (без расширения). Газета остановится до загрузки своих модулей; остальной сайт продолжит работу.</li>';
        echo '<li>Если не помогло — переименуйте только папку <code>kozlov-jazzeta</code> в <code>kozlov-jazzeta.off</code>. Папку <code>plugins</code> целиком не трогайте.</li>';
        echo '<li>Данные газеты сохраняются. Вернуть работу можно только в закрытый режим и только после устранения причины.</li>';
        echo '</ol><p class="es-muted">Важно: кэш сервера или CDN может отдать страницу раньше WordPress. Для закрытого теста исключите /gazeta/* и /wp-json/ezhednevny-standard/* из кэша страниц.</p></section>';
    }
}
