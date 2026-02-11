<?php

namespace Genero\Sage\CacheTags\Invalidators;

use Genero\Sage\CacheTags\Contracts\Invalidator;

/**
 * @see https://docs.wp-rocket.me/article/1801-how-to-programmatically-clear-the-cache-and-optimizations
 */
class WpRocketCacheInvalidator implements Invalidator
{
    /**
     * @param  string[]  $urls
     * @param  string[]  $tags
     */
    public function clear(array $urls, array $tags): bool
    {
        if (! function_exists('rocket_clean_files')) {
            return false;
        }

        if (empty($urls)) {
            return true;
        }

        // note that this recursively deletes all subpaths
        rocket_clean_files($urls);

        return true;
    }

    public function flush(): bool
    {
        if (! function_exists('rocket_clean_domain')) {
            return false;
        }

        rocket_clean_domain();

        return true;
    }
}
