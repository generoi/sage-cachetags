<?php

namespace Genero\Sage\CacheTags\Stores;

use Genero\Sage\CacheTags\Contracts\Store;
use Genero\Sage\CacheTags\Util;

class TransientStore implements Store
{
    /**
     * @param  string[]  $tags
     */
    public function save(array $tags, string $url): bool
    {
        $transient = $this->getCache();

        foreach ($tags as $tag) {
            if (! isset($transient[$tag])) {
                $transient[$tag] = [];
            }
            if (! in_array($url, $transient[$tag])) {
                $transient[$tag][] = $url;
            }
        }

        return $this->saveCache($transient);
    }

    /**
     * @param  string[]  $tags
     * @return string[]
     */
    public function get(array $tags): array
    {
        $urls = array_filter(
            $this->getCache(),
            fn ($urls, $tag) => in_array($tag, $tags),
            ARRAY_FILTER_USE_BOTH
        );
        $urls = array_values($urls);

        return Util::flatten($urls);
    }

    /**
     * @param  string[]  $urls
     * @param  string[]  $tags
     */
    public function clear(array $urls, array $tags): bool
    {
        $transient = array_map(
            fn ($tagUrls) => array_diff($tagUrls, $urls),
            $this->getCache()
        );
        $transient = array_filter(
            $transient,
            fn ($tagUrls) => ! empty($tagUrls)
        );

        return $this->saveCache($transient);
    }

    public function flush(): bool
    {
        return delete_option('sage_cache_tags');
    }

    /**
     * @return array<string, string[]>
     */
    protected function getCache(): array
    {
        return get_option('sage_cache_tags', []);
    }

    /**
     * @param  array<string, string[]>  $value
     */
    protected function saveCache(array $value): bool
    {
        return update_option('sage_cache_tags', $value);
    }
}
