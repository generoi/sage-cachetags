<?php

namespace Genero\Sage\CacheTags\Contracts;

interface Invalidator
{
    /**
     * @param  string[]  $urls
     * @param  string[]  $tags
     */
    public function clear(array $urls, array $tags): bool;

    public function flush(): bool;
}
