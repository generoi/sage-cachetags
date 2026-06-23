<?php

namespace Genero\Sage\CacheTags\Stores;

use Genero\Sage\CacheTags\Concerns\CreatesDatabaseTable;
use Genero\Sage\CacheTags\Contracts\Store;

class WordpressDbStore implements Store
{
    use CreatesDatabaseTable;

    /**
     * Cache of whether the cache tags table exists, keyed by table name so it
     * stays correct across multisite blog switches within a request.
     *
     * @var array<string, bool>
     */
    protected array $tableExists = [];

    /**
     * @param  string[]  $tags
     */
    public function save(array $tags, string $url): bool
    {
        if (empty($tags)) {
            return true;
        }

        if (! $this->ensureTable()) {
            return false;
        }

        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($tags), '(%s, %s)'));
        $values = [];
        foreach ($tags as $tag) {
            $values[] = $tag;
            $values[] = $url;
        }

        $result = $wpdb->query($wpdb->prepare("
            INSERT IGNORE INTO `{$wpdb->prefix}cache_tags` (`tag`, `url`)
            VALUES {$placeholders}
        ", ...$values));

        return $result !== false;
    }

    /**
     * @param  string[]  $tags
     * @return string[]
     */
    public function get(array $tags): array
    {
        if (empty($tags)) {
            return [];
        }

        if (! $this->ensureTable()) {
            return [];
        }

        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($tags), '%s'));

        $urls = $wpdb->get_col($wpdb->prepare("
            SELECT url FROM `{$wpdb->prefix}cache_tags`
            WHERE tag IN ({$placeholders})
        ", ...$tags));

        return $urls;
    }

    /**
     * @param  string[]  $urls
     * @param  string[]  $tags
     */
    public function clear(array $urls, array $tags): bool
    {
        if (empty($urls)) {
            return true;
        }

        if (! $this->ensureTable()) {
            return false;
        }

        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($urls), '%s'));

        $count = $wpdb->query($wpdb->prepare("
            DELETE FROM `{$wpdb->prefix}cache_tags`
            WHERE `url` IN ({$placeholders})
        ", ...$urls));

        return $count ? true : false;
    }

    public function flush(): bool
    {
        if (! $this->ensureTable()) {
            return false;
        }

        global $wpdb;

        return $wpdb->query("
            TRUNCATE `{$wpdb->prefix}cache_tags`
        ");
    }

    /**
     * Ensure the cache tags table exists for the current blog before querying
     * it. Without this guard a missing table (eg a multisite blog where the
     * table was never installed) floods the log with "table doesn't exist"
     * query errors on every request. When missing, attempt to (re)create it
     * once, then fail gracefully if it still cannot be created.
     */
    protected function ensureTable(): bool
    {
        global $wpdb;
        $table = "{$wpdb->prefix}cache_tags";

        if (isset($this->tableExists[$table])) {
            return $this->tableExists[$table];
        }

        if ($this->tableExistsInDb($table)) {
            return $this->tableExists[$table] = true;
        }

        $this->createTable();

        return $this->tableExists[$table] = $this->tableExistsInDb($table);
    }

    /**
     * Check whether a table exists without producing a query error if it does
     * not (SHOW TABLES LIKE returns an empty result for missing tables).
     */
    protected function tableExistsInDb(string $table): bool
    {
        global $wpdb;

        return $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))
        ) === $table;
    }
}
