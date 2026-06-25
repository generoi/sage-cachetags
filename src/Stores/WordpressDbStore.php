<?php

namespace Genero\Sage\CacheTags\Stores;

use DateTimeInterface;
use Genero\Sage\CacheTags\Contracts\InspectableStore;
use Genero\Sage\CacheTags\Contracts\PrunableStore;
use Genero\Sage\CacheTags\Contracts\Store;

class WordpressDbStore implements InspectableStore, PrunableStore, Store
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

        // Upsert, refreshing created_at so it tracks "last seen" — that's what
        // makes pruning by it safe (a still-rendered URL keeps a fresh timestamp
        // and survives GC; with INSERT IGNORE the timestamp never moved, so GC
        // would have deleted live entries the edge still holds).
        //
        // But refresh at most once a day: bumping on every render would write the
        // row on every cache miss and hammer the DB. When the row was seen within
        // the last day the UPDATE sets created_at to itself — a no-op InnoDB skips
        // (no write). GC TTLs are measured in weeks, so a day of slack is free.
        $result = $wpdb->query($wpdb->prepare("
            INSERT INTO `{$wpdb->prefix}cache_tags` (`tag`, `url`)
            VALUES {$placeholders}
            ON DUPLICATE KEY UPDATE created_at = IF(
                created_at < (NOW() - INTERVAL 1 DAY), NOW(), created_at
            )
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
     * Garbage-collect rows last stored (created_at) before $olderThan, batched so
     * a large prune doesn't hold a long lock. Safe because save() bumps created_at
     * on every re-store, so a still-rendered URL keeps a fresh timestamp.
     *
     * @return int rows removed
     */
    public function prune(DateTimeInterface $olderThan, int $batch = 1000): int
    {
        global $wpdb;

        $cutoff = $olderThan->format('Y-m-d H:i:s');
        $batch = max(1, $batch);
        $removed = 0;

        do {
            $deleted = $wpdb->query($wpdb->prepare("
                DELETE FROM `{$wpdb->prefix}cache_tags`
                WHERE created_at < %s
                LIMIT %d
            ", $cutoff, $batch));

            if ($deleted === false) {
                break;
            }

            $removed += $deleted;
        } while ($deleted === $batch);

        return $removed;
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
