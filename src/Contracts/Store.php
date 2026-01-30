<?php

namespace Genero\Sage\CacheTags\Contracts;

interface Store
{
    /**
     * Save cache tags for url.
     *
     * @param  string[]  $tags
     */
    public function save(array $tags, string $url): bool;

    /**
     * Return URLs of pages using cache tag.
     *
     * @param  string[]  $tags
     * @return string[]
     */
    public function get(array $tags): array;

    /**
     * Clear internal cache tag entries.
     *
     * @param  string[]  $urls
     * @param  string[]  $tags
     */
    public function clear(array $urls, array $tags): bool;

    /**
     * Flush internal cache tag entries.
     */
    public function flush(): bool;
}
