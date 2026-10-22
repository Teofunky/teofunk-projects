<?php

namespace Kozlov\Gazeta\Http;

use Kozlov\Gazeta\Data\Db;
use Kozlov\Gazeta\Data\Issues;
use Kozlov\Gazeta\Domain\Validator;
use Kozlov\Gazeta\Runtime\Mode;
use Kozlov\Gazeta\Runtime\Request;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * REST contract 0.3.5: draft import for the es_import role and a read-only public snapshot.
 * There is deliberately no endpoint that publishes, withdraws or opens the newspaper.
 */
final class RestApi
{
    public const RATE_LIMIT_PER_MINUTE = 10;

    public static function init(): void
    {
        add_action('rest_api_init', [self::class, 'routes']);
    }

    public static function routes(): void
    {
        register_rest_route(Request::REST_NAMESPACE, '/import', [
            'methods' => 'POST',
            'permission_callback' => [self::class, 'canImport'],
            'callback' => [self::class, 'import'],
        ]);
        register_rest_route(Request::REST_NAMESPACE, '/latest', [
            'methods' => 'GET',
            'permission_callback' => static function () {
                return Mode::canRead() ? true : new \WP_Error('es_unavailable', 'Страница недоступна.', ['status' => 404]);
            },
            'callback' => [self::class, 'latest'],
        ]);
    }

    public static function canImport()
    {
        if (Mode::current() === Mode::PAUSED) {
            return new \WP_Error('es_unavailable', 'Газета остановлена.', ['status' => 404]);
        }
        return current_user_can('es_import') ? true : new \WP_Error('es_forbidden', 'Нет права es_import.', ['status' => 403]);
    }

    public static function latest()
    {
        $row = Issues::latestPublished();
        if (!$row) {
            return new \WP_Error('es_empty', 'Нет опубликованных выпусков.', ['status' => 404]);
        }
        return rest_ensure_response(Db::decode($row['public_json']));
    }

    /** @param \WP_REST_Request $request */
    public static function import($request)
    {
        $type = strtolower((string) $request->get_header('content-type'));
        if (strpos($type, 'application/json') !== 0) {
            return Validator::error('Content-Type должен быть application/json.', 415);
        }
        $body = (string) $request->get_body();
        if (strlen($body) > Validator::MAX_BYTES) {
            return Validator::error('Максимум 256 КиБ.', 413);
        }
        $limited = self::rateLimit(get_current_user_id());
        if ($limited instanceof \WP_Error) {
            return $limited;
        }
        $match = trim((string) $request->get_header('if-match'), '" ');
        if ($match !== '' && !ctype_digit($match)) {
            return Validator::error('If-Match должен содержать числовую версию.');
        }
        $result = Issues::import($body, $match === '' ? null : (int) $match);
        if (is_wp_error($result)) {
            return $result;
        }
        $response = new \WP_REST_Response($result, $result['unchanged'] ? 200 : 201);
        $response->header('ETag', '"' . $result['version'] . '"');
        $response->header('Cache-Control', 'no-store');
        return $response;
    }

    /**
     * Fixed-window quota stored in one es_state row per account: value = window*100 + count.
     * Atomic in MySQL, no transients and no IP-based guessing. Same encoding as 0.3.5.
     * @return true|\WP_Error
     */
    private static function rateLimit(int $userId)
    {
        $wpdb = Db::wpdb();
        $table = Db::table('state');
        $name = 'rate_' . $userId;
        $base = ((int) floor(time() / 60)) * 100;
        $ok = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table} (name,value) VALUES (%s,%d) ON DUPLICATE KEY UPDATE value=IF(value<%d,%d,LEAST(value+1,%d))",
            $name,
            $base + 1,
            $base,
            $base + 1,
            $base + 99
        ));
        if ($ok === false) {
            return Validator::error('Не удалось проверить лимит импорта.', 503);
        }
        $count = (int) $wpdb->get_var($wpdb->prepare("SELECT value FROM {$table} WHERE name=%s", $name)) - $base;
        if ($count > self::RATE_LIMIT_PER_MINUTE) {
            return Validator::error('Не более 10 запросов импорта в минуту на пользователя.', 429);
        }
        return true;
    }
}
