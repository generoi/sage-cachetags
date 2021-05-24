<?php

namespace Genero\Sage\CacheTags\Invalidators;

use Genero\Sage\CacheTags\Contracts\Invalidator;

class SuperCacheInvalidator implements Invalidator
{
    public function clear(array $urls): bool
    {
        $result = true;
        foreach ($urls as $url) {
            if (! \wpsc_delete_url_cache($url)) {
                $result = false;
            }
        }

        return $result;
    }

    public function flush(): bool
    {
        return \wpsc_delete_files(\get_supercache_dir());
    }
}
