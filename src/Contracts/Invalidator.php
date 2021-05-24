<?php

namespace Genero\Sage\CacheTags\Contracts;

interface Invalidator
{
    public function clear(array $urls): bool;
    public function flush(): bool;
}
