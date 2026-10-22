<?php

namespace Kozlov\Gazeta\Data;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * One-shot authorisation tokens. Sensitive repository operations (publish, manual import)
 * run only while the confirmed admin form handler holds a matching permit, so no other
 * code path — REST, cron, a stray call — can reach them.
 */
final class Permit
{
    /** @var array<string,string> */
    private static $granted = [];

    public static function grant(string $operation, array $subject): void
    {
        self::$granted[$operation] = self::key($subject);
    }

    public static function revoke(string $operation): void
    {
        unset(self::$granted[$operation]);
    }

    public static function holds(string $operation, array $subject): bool
    {
        return isset(self::$granted[$operation]) && hash_equals(self::$granted[$operation], self::key($subject));
    }

    private static function key(array $subject): string
    {
        return hash('sha256', (string) wp_json_encode(array_values($subject)));
    }
}
