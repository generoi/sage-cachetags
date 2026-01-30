<?php

namespace Genero\Sage\CacheTags\Invalidators;

use Genero\Sage\CacheTags\Contracts\Invalidator;

class SuperCacheInvalidator implements Invalidator
{
    /**
     * @param  string[]  $urls
     * @param  string[]  $tags
     */
    public function clear(array $urls, array $tags): bool
    {
        return array_reduce(
            $urls,
            fn ($result, $url) => \wpsc_delete_url_cache($url) ? $result : false,
            true
        );
    }

    public function flush(): bool
    {
        return \wpsc_delete_files(\get_supercache_dir());
    }
}
