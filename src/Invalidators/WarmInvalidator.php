<?php

namespace Genero\Sage\CacheTags\Invalidators;

use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Contracts\Invalidator;
use Genero\Sage\CacheTags\Contracts\ListableStore;
use Genero\Sage\CacheTags\Warmer;

/**
 * Queues purged pages for `wp cachetags warm`. List it after the invalidator
 * that actually purges (Kinsta, Fastly, Super Cache, …): it purges nothing
 * itself. A flush queues every URL the store holds, read before the store is
 * emptied.
 */
class WarmInvalidator implements Invalidator
{
    /**
     * @param  string[]  $urls
     * @param  string[]  $tags
     */
    public function clear(array $urls, array $tags): bool
    {
        Warmer::queue($urls);

        return true;
    }

    public function flush(): bool
    {
        $store = CacheTags::getInstance()?->store;
        if ($store instanceof ListableStore) {
            Warmer::queue($store->urls(Warmer::MAX_QUEUE));
        }

        return true;
    }
}
