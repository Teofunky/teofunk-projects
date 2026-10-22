<?php

namespace Kozlov\Gazeta\Runtime;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Newspaper availability: closed (editors only), public (approved issues for everyone),
 * paused (nobody reads, nobody writes). Stored in the 0.3.x option es_runtime.
 */
final class Mode
{
    public const CLOSED = 'closed';
    public const OPEN = 'public';
    public const PAUSED = 'paused';

    public const LABELS = [
        self::CLOSED => 'Закрытый тест',
        self::OPEN => 'Открыта читателям',
        self::PAUSED => 'Остановлена',
    ];

    public static function current(): string
    {
        if (get_option('es_runtime_fault')) {
            return self::PAUSED;
        }
        $state = get_option('es_runtime', []);
        $mode = is_array($state) ? (string) ($state['mode'] ?? self::CLOSED) : self::CLOSED;
        return in_array($mode, [self::CLOSED, self::OPEN, self::PAUSED], true) ? $mode : self::CLOSED;
    }

    /** @return array{mode:string,changed_by:int,changed_at:string} */
    public static function state(): array
    {
        $state = get_option('es_runtime', []);
        $state = is_array($state) ? $state : [];
        return [
            'mode' => self::current(),
            'changed_by' => (int) ($state['changed_by'] ?? 0),
            'changed_at' => (string) ($state['changed_at'] ?? ''),
        ];
    }

    public static function set(string $mode, int $userId): void
    {
        if (!in_array($mode, [self::CLOSED, self::OPEN, self::PAUSED], true)) {
            throw new \InvalidArgumentException('Unknown mode');
        }
        update_option('es_runtime', ['mode' => $mode, 'changed_by' => $userId, 'changed_at' => gmdate('c')], false);
    }

    public static function fault()
    {
        $fault = get_option('es_runtime_fault');
        return is_array($fault) ? $fault : null;
    }

    public static function clearFault(): void
    {
        delete_option('es_runtime_fault');
    }

    public static function publisherId(): int
    {
        $security = get_option('es_security', []);
        return is_array($security) ? (int) ($security['publisher_id'] ?? 0) : 0;
    }

    /** The single designated owner: es_edit + es_publish + selected account. */
    public static function isOwner(): bool
    {
        $user = get_current_user_id();
        return $user > 0
            && $user === self::publisherId()
            && current_user_can('es_edit')
            && current_user_can('es_publish');
    }

    public static function canRead(): bool
    {
        $mode = self::current();
        return $mode === self::OPEN || ($mode === self::CLOSED && current_user_can('es_edit'));
    }

    /** Tables were activated for this code version. */
    public static function ready(): bool
    {
        return get_option('es_db_version') === ES_VERSION;
    }

    public static function isPrivateView(): bool
    {
        return self::current() !== self::OPEN;
    }
}
