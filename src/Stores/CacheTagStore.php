<?php

namespace Genero\Sage\CacheTags\Stores;

use Genero\Sage\CacheTags\Contracts\Store;

class CacheTagStore implements Store
{
    public function save(array $tags, string $url): bool
    {
        return true;
    }

    public function get(array $tags): array
    {
        return $tags;
    }

    public function clear(array $urls, array $tags): bool
    {
        return true;
    }

    public function flush(): bool
    {
        return true;
    }
}
