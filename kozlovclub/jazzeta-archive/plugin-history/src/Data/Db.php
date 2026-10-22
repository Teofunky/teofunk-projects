<?php

namespace Kozlov\Gazeta\Data;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Database access shared by the repository and installer.
 *
 * The ten table definitions of 0.3.0–0.3.5 are kept byte-for-byte, so activation over existing
 * data is a no-op for them; 0.5.0 only adds the table `meta`.
 */
final class Db
{
    /** @var bool */
    private static $inTransaction = false;

    public const TABLES = ['issues', 'articles', 'revisions', 'state', 'events', 'publications', 'article_sources', 'identities', 'relations', 'imports', 'meta'];

    public static function table(string $name): string
    {
        global $wpdb;
        return $wpdb->prefix . 'es_' . $name;
    }

    /** @return \wpdb */
    public static function wpdb()
    {
        global $wpdb;
        return $wpdb;
    }

    public static function begin(): void
    {
        self::must(self::wpdb()->query('START TRANSACTION'));
        self::$inTransaction = true;
    }

    public static function commit(): void
    {
        self::must(self::wpdb()->query('COMMIT'));
        self::$inTransaction = false;
    }

    public static function rollback(): void
    {
        if (self::$inTransaction) {
            self::wpdb()->query('ROLLBACK');
            self::$inTransaction = false;
        }
    }

    public static function inTransaction(): bool
    {
        return self::$inTransaction;
    }

    /** Throws on a failed write so the surrounding transaction is rolled back. */
    public static function must($result)
    {
        if ($result === false) {
            throw new \RuntimeException('Ошибка записи в базу данных. Операция отменена; повторите после проверки журнала сервера.', 500);
        }
        return $result;
    }

    public static function json($value): string
    {
        $json = wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Не удалось закодировать данные.', 500);
        }
        return $json;
    }

    /** Decodes stored JSON. A corrupted row must not be mistaken for an empty one. */
    public static function decode(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $value = json_decode($json, true, 64);
        if (!is_array($value)) {
            throw new \RuntimeException('Повреждённые данные газеты: JSON не читается.', 500);
        }
        return $value;
    }

    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    public static function catalogVersion(): int
    {
        $wpdb = self::wpdb();
        return (int) $wpdb->get_var('SELECT value FROM ' . self::table('state') . " WHERE name='catalog'");
    }

    /** Bumps the public catalog version; archive caches keyed by it become stale. */
    public static function invalidateCatalog(): void
    {
        self::must(self::wpdb()->query('UPDATE ' . self::table('state') . " SET value=value+1 WHERE name='catalog'"));
    }

    /**
     * The 0.3.5 schema plus the additive `meta` table. Do not reformat: dbDelta compares column definitions.
     * @return array<string,string> table suffix => column/key definitions
     */
    public static function schema(): array
    {
        return [
            'issues' => " id bigint unsigned NOT NULL AUTO_INCREMENT,
 edition_date date NOT NULL,
 number bigint unsigned DEFAULT NULL,
 state varchar(16) NOT NULL DEFAULT 'draft',
 version bigint unsigned NOT NULL DEFAULT 1,
 public_version bigint unsigned NOT NULL DEFAULT 0,
 draft_json longtext NOT NULL,
 public_json longtext DEFAULT NULL,
 import_hash char(64) NOT NULL DEFAULT '',
 human_touched tinyint NOT NULL DEFAULT 0,
 created_by bigint unsigned NOT NULL,
 created_at datetime NOT NULL,
 updated_at datetime NOT NULL,
 published_at datetime DEFAULT NULL,
 public_updated_at datetime DEFAULT NULL,
 approved_by bigint unsigned DEFAULT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY edition_date (edition_date),
 UNIQUE KEY number (number),
 KEY public_archive (state,edition_date,id)",
            'articles' => " id bigint unsigned NOT NULL AUTO_INCREMENT,
 issue_id bigint unsigned NOT NULL,
 external_id varchar(64) NOT NULL,
 slug varchar(160) NOT NULL,
 position smallint unsigned NOT NULL,
 active tinyint NOT NULL DEFAULT 1,
 story_group varchar(120) NOT NULL,
 public_story_group varchar(120) NOT NULL DEFAULT '',
 draft_json longtext NOT NULL,
 public_json longtext DEFAULT NULL,
 reviewed_hash char(64) NOT NULL DEFAULT '',
 reviewed_by bigint unsigned DEFAULT NULL,
 reviewed_at datetime DEFAULT NULL,
 review_note text NOT NULL,
 new_fact text NOT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY issue_external (issue_id,external_id),
 UNIQUE KEY slug (slug),
 KEY issue_position (issue_id,active,position),
 KEY public_story (public_story_group,issue_id)",
            'revisions' => " id bigint unsigned NOT NULL AUTO_INCREMENT,
 issue_id bigint unsigned NOT NULL,
 version bigint unsigned NOT NULL,
 actor_id bigint unsigned NOT NULL,
 action varchar(32) NOT NULL,
 created_at datetime NOT NULL,
 snapshot longtext NOT NULL,
 PRIMARY KEY  (id),
 KEY issue_history (issue_id,id)",
            'state' => " name varchar(64) NOT NULL,
 value bigint unsigned NOT NULL DEFAULT 0,
 PRIMARY KEY  (name)",
            'events' => "event_id varchar(120) NOT NULL,\n title text NOT NULL,\n metadata longtext DEFAULT NULL,\n created_at datetime NOT NULL,\n PRIMARY KEY  (event_id)",
            'publications' => "id bigint unsigned NOT NULL AUTO_INCREMENT,\n url_hash char(64) NOT NULL,\n canonical_url text NOT NULL,\n publisher varchar(500) NOT NULL,\n title text NOT NULL,\n external_key char(64) NOT NULL DEFAULT '',\n content_hash char(64) NOT NULL DEFAULT '',\n metadata longtext DEFAULT NULL,\n created_at datetime NOT NULL,\n PRIMARY KEY  (id),\n UNIQUE KEY url_hash (url_hash),\n KEY external_key (external_key),\n KEY content_hash (content_hash)",
            'article_sources' => "article_id bigint unsigned NOT NULL,\n source_id varchar(64) NOT NULL,\n publication_id bigint unsigned NOT NULL,\n external_key char(64) NOT NULL DEFAULT '',\n content_hash char(64) NOT NULL DEFAULT '',\n layer varchar(8) NOT NULL,\n PRIMARY KEY  (article_id,source_id,layer),\n KEY publication (publication_id,layer,article_id),\n KEY external_key (external_key,layer),\n KEY content_hash (content_hash,layer)",
            'identities' => "article_id bigint unsigned NOT NULL,\n layer varchar(8) NOT NULL,\n event_id varchar(120) NOT NULL,\n text_hash char(64) NOT NULL,\n title_hash char(64) NOT NULL,\n PRIMARY KEY  (article_id,layer),\n KEY event_id (event_id,layer),\n KEY text_hash (text_hash,layer),\n KEY title_hash (title_hash,layer)",
            'relations' => "article_id bigint unsigned NOT NULL,\n related_article_id bigint unsigned NOT NULL,\n layer varchar(8) NOT NULL,\n relation_type varchar(24) NOT NULL,\n reason text NOT NULL,\n method varchar(24) NOT NULL DEFAULT 'manual',\n confidence decimal(5,4) DEFAULT NULL,\n reviewed_by bigint unsigned NOT NULL DEFAULT 0,\n PRIMARY KEY  (article_id,related_article_id,layer),\n KEY related (related_article_id,layer)",
            'imports' => "id bigint unsigned NOT NULL AUTO_INCREMENT,\n issue_id bigint unsigned NOT NULL,\n actor_id bigint unsigned NOT NULL,\n request_key char(64) DEFAULT NULL,\n payload_hash char(64) NOT NULL,\n created_at datetime NOT NULL,\n PRIMARY KEY  (id),\n UNIQUE KEY request_key (request_key),\n UNIQUE KEY payload_hash (payload_hash),\n KEY issue_id (issue_id)",
            // 0.5.0, additive: cover and article blocks the JSON format does not carry (Data\Meta).
            'meta' => "object_type varchar(16) NOT NULL,\n object_id bigint unsigned NOT NULL,\n layer varchar(8) NOT NULL,\n meta_key varchar(32) NOT NULL,\n meta_value longtext NOT NULL,\n updated_at datetime NOT NULL,\n updated_by bigint unsigned NOT NULL DEFAULT 0,\n PRIMARY KEY  (object_type,object_id,layer,meta_key)",
        ];
    }
}
