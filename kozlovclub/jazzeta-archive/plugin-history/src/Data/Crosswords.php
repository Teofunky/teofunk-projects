<?php

namespace Kozlov\Gazeta\Data;

use Kozlov\Gazeta\Domain\Crossword;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Published crosswords (0.5.2): the public layer of wp_es_meta joined with published issues.
 * Withdrawing an issue withdraws its crossword with it.
 */
final class Crosswords
{
    /** @var array|null per-request cache */
    private static $list = null;

    /**
     * Newest first. @return array<int,array{issue_id:int,edition_date:string,number:?int,crossword:array}>
     */
    public static function published(): array
    {
        if (self::$list !== null) {
            return self::$list;
        }
        $wpdb = Db::wpdb();
        $suppress = $wpdb->suppress_errors(true);
        $rows = $wpdb->get_results(
            'SELECT i.id, i.edition_date, i.number, m.meta_value FROM ' . Db::table('meta') . ' m JOIN ' . Db::table('issues')
            . " i ON m.object_type='issue' AND i.id=m.object_id WHERE m.layer='public' AND m.meta_key='crossword' AND i.state='published'"
            . ' ORDER BY i.edition_date DESC, i.id DESC LIMIT 400',
            ARRAY_A
        );
        $wpdb->suppress_errors($suppress);
        $out = [];
        foreach ((array) $rows as $row) {
            $crossword = Crossword::clean(json_decode((string) $row['meta_value'], true));
            if ($crossword) {
                $out[] = [
                    'issue_id' => (int) $row['id'],
                    'edition_date' => (string) $row['edition_date'],
                    'number' => $row['number'] !== null ? (int) $row['number'] : null,
                    // 0.5.0/0.5.1 copy the draft layer verbatim when re-approving after a rollback: strip notes on read too.
                    'crossword' => Crossword::forPublic($crossword),
                ];
            }
        }
        return self::$list = $out;
    }

    /**
     * [this date's crossword, the closest newer one, the closest older one]. Three small queries,
     * so a crossword page never decodes the whole archive.
     * @return array{0:?array,1:?array,2:?array}
     */
    public static function around(string $date): array
    {
        return [
            self::one('i.edition_date=%s', 'i.edition_date DESC', $date),
            self::one('i.edition_date>%s', 'i.edition_date ASC', $date),
            self::one('i.edition_date<%s', 'i.edition_date DESC', $date),
        ];
    }

    private static function one(string $condition, string $order, string $date): ?array
    {
        $wpdb = Db::wpdb();
        $suppress = $wpdb->suppress_errors(true);
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT i.id, i.edition_date, i.number, m.meta_value FROM ' . Db::table('meta') . ' m JOIN ' . Db::table('issues')
            . " i ON m.object_type='issue' AND i.id=m.object_id WHERE m.layer='public' AND m.meta_key='crossword' AND i.state='published' AND "
            . $condition . ' ORDER BY ' . $order . ', i.id DESC LIMIT 1',
            $date
        ), ARRAY_A);
        $wpdb->suppress_errors($suppress);
        if (!$row) {
            return null;
        }
        $crossword = Crossword::clean(json_decode((string) $row['meta_value'], true));
        return $crossword ? [
            'issue_id' => (int) $row['id'],
            'edition_date' => (string) $row['edition_date'],
            'number' => $row['number'] !== null ? (int) $row['number'] : null,
            'crossword' => Crossword::forPublic($crossword),
        ] : null;
    }

    public static function forget(): void
    {
        self::$list = null;
    }
}
