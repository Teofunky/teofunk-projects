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

    /**
     * 0.5.9: the crossword that hangs on the issues of its week. It is the newest published one dated
     * from six days before $date up to $date, so one crossword stays on seven issues and the next one
     * replaces it on its own date (answers of the old one open then, see Crossword::answersOpen()).
     * @return array|null the same shape as one item of published()
     */
    public static function activeFor(string $date): ?array
    {
        $wpdb = Db::wpdb();
        $suppress = $wpdb->suppress_errors(true);
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT i.id, i.edition_date, i.number, m.meta_value FROM ' . Db::table('meta') . ' m JOIN ' . Db::table('issues')
            . " i ON m.object_type='issue' AND i.id=m.object_id WHERE m.layer='public' AND m.meta_key='crossword' AND i.state='published'"
            . ' AND i.edition_date BETWEEN DATE_SUB(%s, INTERVAL 6 DAY) AND %s ORDER BY i.edition_date DESC, i.id DESC LIMIT 1',
            $date,
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

    /** Edition dates of published issues that may show a crossword dated $date (its week and the next six days). */
    public static function weekDates(string $date): array
    {
        $wpdb = Db::wpdb();
        $suppress = $wpdb->suppress_errors(true);
        $dates = $wpdb->get_col($wpdb->prepare(
            'SELECT edition_date FROM ' . Db::table('issues') . " WHERE state='published' AND edition_date BETWEEN DATE_SUB(%s, INTERVAL 6 DAY) AND DATE_ADD(%s, INTERVAL 6 DAY)",
            $date,
            $date
        ));
        $wpdb->suppress_errors($suppress);
        return array_map('strval', (array) $dates);
    }

    public static function forget(): void
    {
        self::$list = null;
    }
}
