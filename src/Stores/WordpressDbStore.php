<?php

namespace Genero\Sage\CacheTags\Stores;

use Genero\Sage\CacheTags\Contracts\InspectableStore;
use Genero\Sage\CacheTags\Contracts\Store;

class WordpressDbStore implements InspectableStore, Store
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

    /**
     * @return array{rows: int, tags: int, urls: int}
     */
    public function stats(): array
    {
        global $wpdb;
        $table = "{$wpdb->prefix}cache_tags";

        return [
            'rows' => (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`"),
            'tags' => (int) $wpdb->get_var("SELECT COUNT(DISTINCT tag) FROM `{$table}`"),
            'urls' => (int) $wpdb->get_var("SELECT COUNT(DISTINCT url) FROM `{$table}`"),
        ];
    }

    /**
     * @return array<array{tag: string, urls: int}>
     */
    public function topTags(int $limit): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare("
            SELECT tag, COUNT(DISTINCT url) AS urls
            FROM `{$wpdb->prefix}cache_tags`
            GROUP BY tag
            ORDER BY urls DESC
            LIMIT %d
        ", $limit), ARRAY_A);

        return array_map(fn ($row) => ['tag' => $row['tag'], 'urls' => (int) $row['urls']], $rows);
    }

    /**
     * @return string[]
     */
    public function tagsForUrl(string $url): array
    {
        global $wpdb;

        return $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT tag FROM `{$wpdb->prefix}cache_tags` WHERE url = %s
        ", $url));
    }
}
