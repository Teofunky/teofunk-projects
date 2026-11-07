<?php

namespace Kozlov\Gazeta\Admin\Screens;

use Kozlov\Gazeta\Admin\Actions;
use Kozlov\Gazeta\Admin\Ui;
use Kozlov\Gazeta\Data\Calendar;
use Kozlov\Gazeta\Domain\Labels;
use Kozlov\Gazeta\Runtime\Mode;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 0.5.11: «Этот день в джазе». The owner uploads the file made by jazz_calendar.py export;
 * the block appears under every issue whose date the file covers, the archive included.
 */
final class CalendarScreen
{
    /** Remind about a new file when fewer days than this remain. */
    private const WARN_DAYS = 30;

    public static function render(): void
    {
        if (!current_user_can('es_publish')) {
            wp_die('Нет прав.', '', ['response' => 403]);
        }
        $stored = Calendar::stored();
        $owner = Mode::isOwner();
        Ui::open('Календарь «Этот день в джазе»', 'es-calendar', [
            'subtitle' => 'Памятные даты под номером и отдельная карточка для соцсетей. Файл готовит <strong>jazz_calendar.py export</strong>; тексты строк собираются из года и возраста, газета их не пишет.',
        ]);
        echo '<div class="es-layout es-layout--issue"><div class="es-main-col">';

        echo '<section class="es-card"><h2 class="es-card__title">Сейчас на сайте</h2>';
        if (!$stored) {
            echo '<p>Календарь не загружен: блок под номером и карточка не показываются.</p>';
        } else {
            $days = count($stored['days']);
            $today = self::today();
            $left = (int) floor((strtotime((string) $stored['to']) - strtotime($today)) / DAY_IN_SECONDS);
            echo '<p><strong>' . esc_html(Labels::date((string) $stored['from']) . ' — ' . Labels::date((string) $stored['to'])) . '</strong> · ' . esc_html(Labels::count($days, 'день', 'дня', 'дней')) . ' с датами.</p>';
            echo '<p class="es-muted">Файл подготовлен: ' . esc_html((string) ($stored['generated_at'] ?: 'не указано')) . '. Загружен: ' . esc_html(Ui::when((string) ($stored['uploaded_at'] ?? ''))) . ', ' . esc_html(Ui::userName((int) ($stored['uploaded_by'] ?? 0))) . '.</p>';
            if ($left < 0) {
                echo '<div class="es-alert es-alert--warn" role="status">Календарь закончился ' . esc_html(Labels::date((string) $stored['to'])) . ': у новых номеров блока нет. Загрузите новый файл.</div>';
            } elseif ($left < self::WARN_DAYS) {
                echo '<div class="es-alert es-alert--warn" role="status">Календарь заканчивается через ' . esc_html(Labels::count($left, 'день', 'дня', 'дней')) . '. Подготовьте и загрузите новый файл.</div>';
            }
        }
        echo '</section>';

        if ($stored) {
            echo '<section class="es-card"><h2 class="es-card__title">Ближайшие дни</h2><p class="es-muted">Так строки выйдут под номерами этих дат.</p><dl class="es-calendar-preview">';
            $start = strtotime(self::today());
            for ($i = 0; $i < 7; $i++) {
                $date = gmdate('Y-m-d', $start + $i * DAY_IN_SECONDS);
                $block = \Kozlov\Gazeta\Front\Presenter::calendar(Calendar::forDate($date), $date);
                echo '<dt>' . esc_html(Labels::date($date)) . '</dt>';
                if (!$block) {
                    echo '<dd class="es-muted">нет дат — блок не выводится</dd>';
                    continue;
                }
                foreach ($block['items'] as $item) {
                    echo '<dd>' . esc_html($item['line']) . '</dd>';
                }
            }
            echo '</dl></section>';
        }

        echo '</div><aside class="es-side-col">';
        if (!$owner) {
            echo '<section class="es-card"><h2 class="es-card__title">Загрузка</h2><p class="es-muted">Загружает и убирает календарь только выбранный владелец газеты: календарь сразу виден читателям.</p></section>';
        } else {
            echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '" class="es-card">';
            Actions::fields('calendar');
            echo '<input type="hidden" name="calendar_op" value="upload">';
            echo '<h2 class="es-card__title">Загрузить файл</h2>';
            echo '<p class="es-muted">calendar.json в формате ' . esc_html(Calendar::FORMAT) . '. Файл заменяет прежний целиком. Если в нём есть ошибка, ничего не меняется и показывается список ошибок.</p>';
            echo '<p><input type="file" name="calendar_file" accept="application/json,.json" required></p>';
            echo '<label class="es-check"><input type="checkbox" name="confirm" value="1" required> <span>Календарь сразу появится под номерами этих дат, в подшивке тоже.</span></label>';
            echo '<div class="es-actions"><button type="submit" class="es-btn es-btn--primary">Загрузить</button></div>';
            echo '</form>';
            if ($stored) {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="es-card">';
                Actions::fields('calendar');
                echo '<input type="hidden" name="calendar_op" value="remove">';
                echo '<h2 class="es-card__title">Убрать календарь</h2>';
                echo '<p class="es-muted">Блок и карточка пропадут со всех номеров. Номера и материалы не меняются.</p>';
                echo '<label class="es-check"><input type="checkbox" name="confirm" value="1" required> <span>Убрать календарь с сайта</span></label>';
                echo '<div class="es-actions"><button type="submit" class="es-btn">Убрать</button></div>';
                echo '</form>';
            }
        }
        echo '</aside></div>';
        Ui::close();
    }

    /** Today in Moscow: the newspaper's dates are Moscow dates. */
    private static function today(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Moscow')))->format('Y-m-d');
    }
}
