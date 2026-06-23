<?php

namespace Genero\Sage\CacheTags\Concerns;

trait CreatesDatabaseTable
{
    /**
     * Schema version. Bump whenever the table definition changes so existing
     * installs run dbDelta again on upgrade.
     *
     * 1: initial table.
     * 2: added KEY url for purge-by-url lookups.
     */
    protected static int $databaseVersion = 2;

    const DB_VERSION_OPTION = 'cachetags_db_version';

    /**
     * Create or update the cache tags database table.
     *
     * dbDelta diffs against the existing table, so this both creates the table
     * and adds missing columns/indexes on later schema versions.
     */
    protected function createTable(): void
    {
        require_once ABSPATH.'wp-admin/includes/upgrade.php';

        global $wpdb;
        $charsetCollate = $wpdb->get_charset_collate();

        \dbDelta("CREATE TABLE {$wpdb->prefix}cache_tags (
            tag varchar(191) NOT NULL,
            url varchar(191) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (tag, url),
            KEY url (url)
        ) $charsetCollate");

        \update_option(self::DB_VERSION_OPTION, self::$databaseVersion);
    }

    /**
     * Run the table migration for the current site if its schema is outdated.
     *
     * Activation hooks don't re-run on plugin updates, so existing installs
     * pick up schema changes here instead. Cheap when up to date (the version
     * option is autoloaded).
     */
    public function upgradeTable(): void
    {
        if ((int) \get_option(self::DB_VERSION_OPTION) === self::$databaseVersion) {
            return;
        }

        $this->createTable();
    }
}
