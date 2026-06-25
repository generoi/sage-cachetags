<?php

namespace Genero\Sage\CacheTags\Concerns;

trait CreatesDatabaseTable
{
    /**
     * Schema version.
     *
     * 1: initial table.
     * 2: added KEY url for purge-by-url lookups.
     * 3: surrogate `id` AUTO_INCREMENT primary key, (tag, url) demoted to a
     *    UNIQUE key — so InnoDB clusters on a monotonic key (append-only inserts)
     *    instead of the wide varchar (tag, url) string, avoiding page splits on
     *    large stores. Built with raw SQL: dbDelta can't create an AUTO_INCREMENT
     *    primary key.
     */
    protected static int $databaseVersion = 3;

    const DB_VERSION_OPTION = 'cachetags_db_version';

    /**
     * The current table definition.
     *
     * `created_at` is "last seen": WordpressDbStore::save() bumps it on every
     * re-store, so store garbage collection can prune by it (rows untouched for
     * longer than the edge TTL) without deleting still-live entries.
     */
    protected function tableSchema(): string
    {
        global $wpdb;
        $charsetCollate = $wpdb->get_charset_collate();

        return "CREATE TABLE `{$wpdb->prefix}cache_tags` (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            tag varchar(191) NOT NULL,
            url varchar(191) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY tag_url (tag, url),
            KEY url (url)
        ) {$charsetCollate}";
    }

    /**
     * Create the store table if it doesn't exist yet.
     *
     * Idempotent: an existing table is left untouched (including a pre-v3 table,
     * which keeps working). Migrating an existing table to a new schema is an
     * explicit, operator-run rebuild — see rebuildTable().
     */
    protected function createTable(): void
    {
        if ($this->tableExists()) {
            return;
        }

        global $wpdb;
        $wpdb->query($this->tableSchema());

        \update_option(self::DB_VERSION_OPTION, self::$databaseVersion);
    }

    /**
     * Drop and recreate the table at the current schema.
     *
     * The store is a rebuildable cache (it refills as pages render), so this is
     * the cheap way to migrate a large table to a new schema — no multi-minute
     * locking ALTER. Callers should flush the edge cache afterwards so nothing is
     * left stale that the now-empty store can't resolve to a URL.
     */
    public function rebuildTable(): void
    {
        global $wpdb;
        $wpdb->query("DROP TABLE IF EXISTS `{$wpdb->prefix}cache_tags`");

        $this->createTable();
    }

    /**
     * Ensure the table exists for the current site (a new subsite, or a site the
     * activation hook missed). An existing pre-v3 table keeps working as-is; run
     * `wp cachetags database --rebuild` to migrate it to the surrogate-key schema.
     */
    public function upgradeTable(): void
    {
        $this->createTable();
    }

    protected function tableExists(): bool
    {
        global $wpdb;
        $table = "{$wpdb->prefix}cache_tags";

        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }
}
