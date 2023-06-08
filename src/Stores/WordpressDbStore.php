<?php

namespace Genero\Sage\CacheTags\Stores;

use Genero\Sage\CacheTags\Contracts\Store;

class WordpressDbStore implements Store
{
    public function save(array $tags, string $url): bool
    {
        return collect($tags)
            ->map(function ($tag) use ($url) {
                global $wpdb;
                return $wpdb->query($wpdb->prepare("
                    INSERT IGNORE INTO `{$wpdb->prefix}cache_tags`
                    SET `tag` = %s, `url` = %s
                ", $tag, $url));
            })
            // Return false if any of the queries failed.
            ->reduce(fn ($result, $insertResult) => $insertResult ? $result : false, true);
    }

    public function get(array $tags): array
    {
        global $wpdb;
        $inClause = collect($tags)
            ->map(fn ($tag) => sprintf("'%s'", \esc_sql($tag)))
            ->join(',');

        $urls = $wpdb->get_col(sprintf("
            SELECT url FROM `{$wpdb->prefix}cache_tags`
            WHERE tag IN (%s)
        ", $inClause));

        return $urls;
    }

    public function clear(array $urls, array $tags): bool
    {
        global $wpdb;

        if (empty($urls)) {
            return true;
        }

        $inClause = collect($urls)
            ->map(fn ($url) => sprintf("'%s'", \esc_sql($url)))
            ->join(',');

        $count = $wpdb->query(sprintf("
            DELETE FROM `{$wpdb->prefix}cache_tags`
            WHERE `url` IN (%s)
        ", $inClause));

        return $count ? true : false;
    }

    public function flush(): bool
    {
        global $wpdb;

        return $wpdb->query("
            TRUNCATE `{$wpdb->prefix}cache_tags`
        ");
    }
}
