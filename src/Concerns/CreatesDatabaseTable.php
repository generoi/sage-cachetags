<?php

namespace Genero\Sage\CacheTags\Concerns;

trait CreatesDatabaseTable
{
    /**
     * Create the cache tags database table.
     */
    protected function createTable(): void
    {
        require_once ABSPATH.'wp-admin/includes/upgrade.php';

        global $wpdb;
        $charsetCollate = $wpdb->get_charset_collate();

        \dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cache_tags (
            tag varchar(191) NOT NULL,
            url varchar(191) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (tag, url)
        ) $charsetCollate");
    }
}
