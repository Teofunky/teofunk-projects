<?php

namespace Kozlov\Gazeta\Admin\Screens;

use Kozlov\Gazeta\Admin\Ui;
use Kozlov\Gazeta\Runtime\Mode;

if (!defined('ABSPATH')) {
    exit;
}

/** Who may publish (one owner), who edits, who imports. */
final class AccessScreen
{
    public static function sanitize($input): array
    {
        $old = get_option('es_security', []);
        $old = is_array($old) ? $old : [];
        if (!current_user_can('manage_options') || !is_array($input) || !is_scalar($input['publisher_id'] ?? null)) {
            return $old;
        }
        $id = absint($input['publisher_id']);
        if (!$id || !user_can($id, 'es_publish') || !user_can($id, 'es_edit')) {
            add_settings_error('es_security', 'publisher', 'Выберите пользователя с правами редакции и публикации газеты.');
            return $old;
        }
        return ['publisher_id' => $id];
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Нет прав.', '', ['response' => 403]);
        }
        $owner = Mode::publisherId();
        $candidates = get_users(['capability' => 'es_publish', 'orderby' => 'display_name', 'number' => 200]);
        $editors = get_users(['role__in' => ['es_editor'], 'orderby' => 'display_name', 'number' => 200]);
        $importers = get_users(['role__in' => ['es_importer'], 'orderby' => 'display_name', 'number' => 200]);
        Ui::open('Доступ', 'es-access', ['subtitle' => 'Публикует один владелец. Редакторы готовят номер, импортёр только присылает черновики.']);
        settings_errors('es_security');
        echo '<div class="es-layout es-layout--issue"><div class="es-main-col">';
        echo '<form method="post" action="' . esc_url(admin_url('options.php')) . '" class="es-card">';
        settings_fields('es_security_group');
        echo '<h2 class="es-card__title">Владелец газеты</h2><p class="es-muted">Только он публикует и снимает выпуски, открывает газету читателям и заменяет черновики из JSON.</p>';
        echo '<div class="es-field"><label for="es-owner">Учётная запись</label><select id="es-owner" name="es_security[publisher_id]">';
        foreach ($candidates as $user) {
            if (!user_can($user, 'es_edit')) {
                continue;
            }
            echo '<option value="' . (int) $user->ID . '"' . selected($owner, (int) $user->ID, false) . '>' . esc_html($user->display_name . ' — ' . $user->user_login) . '</option>';
        }
        echo '</select></div><div class="es-actions"><button class="es-btn es-btn--primary" type="submit">Сохранить владельца</button></div></form>';
        echo '</div><aside class="es-side-col">';
        self::people('Газета — редактор', $editors, 'Правят материалы, отмечают проверку, собирают номер. Не публикуют.');
        self::people('Газета — импортёр', $importers, 'Только REST-импорт черновиков с паролем приложения. Больше ничего на сайте не может.');
        echo '</aside></div>';
        Ui::close();
    }

    private static function people(string $title, array $users, string $text): void
    {
        echo '<section class="es-card"><h2 class="es-card__title">' . esc_html($title) . '</h2><p class="es-muted">' . esc_html($text) . '</p>';
        if (!$users) {
            echo '<p class="es-empty">Никого. Роль назначается в «Пользователях».</p>';
        } else {
            echo '<ul class="es-people">';
            foreach ($users as $user) {
                echo '<li><strong>' . esc_html($user->display_name) . '</strong> <span class="es-muted">' . esc_html($user->user_login) . '</span></li>';
            }
            echo '</ul>';
        }
        echo '</section>';
    }
}
