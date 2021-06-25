<?php

namespace Genero\Sage\CacheTags\Invalidators;

use Genero\Sage\CacheTags\Contracts\Invalidator;

class SuperCacheInvalidator implements Invalidator
{
    public function clear(array $urls): bool
    {
        return collect($urls)
            ->map(fn ($url) => \wpsc_delete_url_cache($url))
            ->reduce(fn ($result, $urlResult) => $urlResult ? $result : false, true);
    }

    public function flush(): bool
    {
        return \wpsc_delete_files(\get_supercache_dir());
    }
}
