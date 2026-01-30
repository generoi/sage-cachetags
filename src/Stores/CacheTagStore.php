<?php

namespace Genero\Sage\CacheTags\Stores;

use Genero\Sage\CacheTags\Contracts\Store;

class CacheTagStore implements Store
{
    /**
     * @param  string[]  $tags
     */
    public function save(array $tags, string $url): bool
    {
        return true;
    }

    /**
     * @param  string[]  $tags
     * @return string[]
     */
    public function get(array $tags): array
    {
        return $tags;
    }

    /**
     * @param  string[]  $urls
     * @param  string[]  $tags
     */
    public function clear(array $urls, array $tags): bool
    {
        return true;
    }

    public function flush(): bool
    {
        return true;
    }
}
