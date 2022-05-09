<?php

namespace Genero\Sage\CacheTags\Invalidators;

use Genero\Sage\CacheTags\Contracts\Invalidator;

class DebugCacheInvalidator implements Invalidator
{
    public function clear(array $urls): bool
    {
        collect($urls)
            ->each(fn ($url) => $this->log($url));

        return true;
    }

    public function flush(): bool
    {
        $this->log('flush');
        return true;
    }

    protected function log(string $message)
    {
        error_log(sprintf('Invalidate CacheTag URL: %s', $message));
    }
}
