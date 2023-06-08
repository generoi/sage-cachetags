<?php

namespace Genero\Sage\CacheTags\Contracts;

interface Invalidator
{
    public function clear(array $urls, array $tags): bool;
    public function flush(): bool;
}
