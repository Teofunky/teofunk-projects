<?php

namespace Kozlov\Gazeta\Admin\Screens;

use Kozlov\Gazeta\Admin\Actions;
use Kozlov\Gazeta\Admin\Ui;

if (!defined('ABSPATH')) {
    exit;
}

/** Manual copy of 0.2.0 issues into unverified drafts. Old posts stay untouched. */
final class MigrationScreen
{
    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Нет прав.', '', ['response' => 403]);
        }
        $page = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
        $query = new \WP_Query([
            'post_type' => 'jz_issue',
            'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
            'posts_per_page' => 20,
            'paged' => $page,
            'orderby' => 'ID',
            'order' => 'DESC',
        ]);
        Ui::open('Перенос выпусков 0.2.0', 'es-migrate', ['subtitle' => 'Только копирование в новые непроверенные черновики. Старые записи не меняются.']);
        echo '<section class="es-card">';
        if (!$query->posts) {
            echo '<p class="es-empty">Выпусков версии 0.2.0 на сайте нет.</p>';
        }
        foreach ($query->posts as $post) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="es-inline-form es-migrate-row">';
            Actions::fields('migrate');
            echo '<input type="hidden" name="legacy_id" value="' . (int) $post->ID . '"><strong>' . esc_html($post->post_title) . '</strong> <span class="es-muted">#' . (int) $post->ID . '</span>';
            echo '<button class="es-btn es-btn--small" type="submit">Скопировать в черновик</button></form>';
        }
        echo '</section>';
        Ui::close();
    }
}
