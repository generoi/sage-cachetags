<?php

namespace Genero\Sage\CacheTags\Stores;

use Genero\Sage\CacheTags\Concerns\CreatesDatabaseTable;
use Genero\Sage\CacheTags\Contracts\Store;

class WordpressDbStore implements Store
{
    use CreatesDatabaseTable;

    /**
     * @param  string[]  $tags
     */
    public function save(array $tags, string $url): bool
    {
        if (empty($tags)) {
            return true;
        }

        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($tags), '(%s, %s)'));
        $values = [];
        foreach ($tags as $tag) {
            $values[] = $tag;
            $values[] = $url;
        }

        $sql = $wpdb->prepare("
            INSERT IGNORE INTO `{$wpdb->prefix}cache_tags` (`tag`, `url`)
            VALUES {$placeholders}
        ", ...$values);

        $result = $wpdb->query($sql);

        // Self-provision a missing table (e.g. a multisite subsite the activation
        // hook never reached) on first write, then retry — so a provisioning gap
        // self-heals on the next render instead of silently never storing tags.
        if ($result === false && $this->tableIsMissing()) {
            $this->createTable();
            $result = $wpdb->query($sql);
        }

        return $result !== false;
    }

    protected function tableIsMissing(): bool
    {
        global $wpdb;
        $table = "{$wpdb->prefix}cache_tags";

        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table;
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

        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($tags), '%s'));

        $urls = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT url FROM `{$wpdb->prefix}cache_tags`
            WHERE tag IN ({$placeholders})
        ", ...$tags));

        return $urls;
    }

    /**
     * Remove only the (url, tag) mappings being purged — not every tag on those
     * URLs. Deleting by URL alone drops sibling-tag rows (e.g. clearing `post:5`
     * would also forget `/article/` is tagged `post:6`); if a concurrent request
     * re-renders and re-caches the page between the edge purge and this delete, a
     * later purge of the sibling tag would no longer find it.
     *
     * @param  string[]  $urls
     * @param  string[]  $tags
     */
    public function clear(array $urls, array $tags): bool
    {
        global $wpdb;

        if (empty($urls) || empty($tags)) {
            return true;
        }

        $urlPlaceholders = implode(',', array_fill(0, count($urls), '%s'));
        $tagPlaceholders = implode(',', array_fill(0, count($tags), '%s'));

        $result = $wpdb->query($wpdb->prepare("
            DELETE FROM `{$wpdb->prefix}cache_tags`
            WHERE `url` IN ({$urlPlaceholders}) AND `tag` IN ({$tagPlaceholders})
        ", ...$urls, ...$tags));

        // A 0-row delete (already cleared) is success, not failure.
        return $result !== false;
    }

    public function flush(): bool
    {
        global $wpdb;

        // TRUNCATE reports 0 affected rows on success, so test for an explicit
        // failure rather than truthiness.
        return $wpdb->query("
            TRUNCATE `{$wpdb->prefix}cache_tags`
        ") !== false;
    }
}
