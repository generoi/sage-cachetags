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
        global $wpdb;

        if (empty($urls)) {
            return true;
        }

        $placeholders = implode(',', array_fill(0, count($urls), '%s'));

        $count = $wpdb->query($wpdb->prepare("
            DELETE FROM `{$wpdb->prefix}cache_tags`
            WHERE `url` IN ({$placeholders})
        ", ...$urls));

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
