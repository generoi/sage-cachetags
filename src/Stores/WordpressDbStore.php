<?php

namespace Genero\Sage\CacheTags\Stores;

use Genero\Sage\CacheTags\Contracts\Store;

class WordpressDbStore implements Store
{
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

        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($tags), '%s'));

        $urls = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT url FROM `{$wpdb->prefix}cache_tags`
            WHERE tag IN ({$placeholders})
        ", ...$tags));

        return $urls;
    }

    /**
     * Forget the URLs that were just purged. A purge evicts whole pages from the
     * edge, so every tag mapping for those URLs is now stale; a re-render
     * re-stores the page's current tags. Deleting by URL (not by tag) keeps the
     * store a clean mirror of what's actually cached — $tags is unused here, it's
     * the edge that's purged by tag (FastlyCacheInvalidator).
     *
     * @param  string[]  $urls
     * @param  string[]  $tags
     */
    public function clear(array $urls, array $tags): bool
    {
        global $wpdb;

        if (empty($urls)) {
            return true;
        }

        $placeholders = implode(',', array_fill(0, count($urls), '%s'));

        $result = $wpdb->query($wpdb->prepare("
            DELETE FROM `{$wpdb->prefix}cache_tags`
            WHERE `url` IN ({$placeholders})
        ", ...$urls));

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
