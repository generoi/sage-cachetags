<?php

namespace Genero\Sage\CacheTags\Invalidators;

use Genero\Sage\CacheTags\Contracts\Invalidator;

class DebugCacheInvalidator implements Invalidator
{
    /**
     * @param  string[]  $urls
     * @param  string[]  $tags
     */
    public function clear(array $urls, array $tags): bool
    {
        foreach ($urls as $url) {
            $this->log($url);
        }

        return true;
    }

    public function flush(): bool
    {
        $this->log('flush');

        return true;
    }

    protected function log(string $message): void
    {
        error_log(sprintf('Invalidate CacheTag URL: %s', $message));
    }
}
