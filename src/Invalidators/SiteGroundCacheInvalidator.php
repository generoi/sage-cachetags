<?php

namespace Genero\Sage\CacheTags\Invalidators;

use Genero\Sage\CacheTags\Contracts\Invalidator;

class SiteGroundCacheInvalidator implements Invalidator
{
    public function clear(array $urls, array $tags): bool
    {
        return collect($urls)
            ->map(fn ($url) => \sg_cachepress_purge_cache($url))
            ->reduce(fn ($result, $urlResult) => $urlResult ? $result : false, true);
    }

    public function flush(): bool
    {
        return \sg_cachepress_purge_cache() ?: false;
    }
}
