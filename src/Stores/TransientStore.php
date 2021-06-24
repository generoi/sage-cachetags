<?php

namespace Genero\Sage\CacheTags\Stores;

use Genero\Sage\CacheTags\Contracts\Store;

class TransientStore implements Store
{
    public function save(array $tags, string $url): bool
    {
        $transient = $this->getCache();

        collect($tags)
            ->each(function ($tag) use ($url, &$transient) {
                if (!isset($transient[$tag])) {
                    $transient[$tag] = [];
                }
                if (!in_array($url, $transient[$tag])) {
                    $transient[$tag][] = $url;
                }
            });

        return $this->saveCache($transient);
    }

    public function get(array $tags): array
    {
        $urls = collect($this->getCache())
            ->filter(fn ($urls, $tag) => in_array($tag, $tags))
            ->values()
            ->flatten()
            ->all();

        return $urls;
    }

    public function clear(array $urls): bool
    {
        $transient = collect($this->getCache())
            ->map(fn ($tagUrls) => array_diff($tagUrls, $urls))
            ->filter()
            ->all();

        return $this->saveCache($transient);
    }

    public function flush(): bool
    {
        return delete_option('sage_cache_tags');
    }

    protected function getCache(): array
    {
        return get_option('sage_cache_tags', []);
    }

    protected function saveCache(array $value): bool
    {
        return update_option('sage_cache_tags', $value);
    }
}
