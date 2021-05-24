<?php

namespace Genero\Sage\CacheTags\Contracts;

interface Store
{
    /**
     * Save cache tags for url.
     */
    public function save(array $tags, string $url): bool;

    /**
     * Return URLs of pages using cache tag.
     *
     * @return string[]
     */
    public function get(array $tags): array;

    /**
     * Clear internal cache tag entries.
     */
    public function clear(array $urls): bool;

    /**
     * Flush internal cache tag entries.
     */
    public function flush(): bool;
}
