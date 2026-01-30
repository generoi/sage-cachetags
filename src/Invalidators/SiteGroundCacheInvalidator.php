<?php

namespace Genero\Sage\CacheTags\Invalidators;

use Genero\Sage\CacheTags\Contracts\Invalidator;

class SiteGroundCacheInvalidator implements Invalidator
{
    /**
     * @param  string[]  $urls
     * @param  string[]  $tags
     */
    public function clear(array $urls, array $tags): bool
    {
        return array_reduce(
            $urls,
            fn ($result, $url) => \sg_cachepress_purge_cache($url) ? $result : false,
            true
        );
    }

    public function flush(): bool
    {
        return \sg_cachepress_purge_cache() ?: false;
    }
}
