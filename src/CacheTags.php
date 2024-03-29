<?php

namespace Genero\Sage\CacheTags;

use Genero\Sage\CacheTags\Contracts\Invalidator;
use Genero\Sage\CacheTags\Contracts\Store;

class CacheTags
{
    const FILTER_TAGS = 'cachetags/filter-tags';

    /**
     * @var string[]
     */
    protected array $cacheTags;

    /**
     * @var string[]
     */
    protected array $purgeTags;

    /**
     * @var Store $store
     */
    protected Store $store;

    /**
     * @var Invalidator[] $invalidators
     */
    protected array $invalidators = [];

    public function __construct(Store $store, Invalidator ...$invalidators)
    {
        $this->store = $store;
        $this->invalidators = $invalidators;
        $this->cacheTags = [];
        $this->purgeTags = [];
    }

    /**
     * Add a set of cache tags to this page load.
     *
     * @param string[] $tags
     */
    public function add(array $tags): void
    {
        $this->cacheTags = [
            ...$this->cacheTags,
            ...$tags,
        ];
    }

    /**
     * Return all cache tags for this page load.
     */
    public function get(): array
    {
        $tags = collect($this->cacheTags)
            ->flatten()
            ->filter()
            ->unique()
            ->all();

        return apply_filters(self::FILTER_TAGS, $tags);
    }

    /**
     * Save current accumulated tags for current page url.
     */
    public function save(string $url): void
    {
        $this->store->save($this->get(), $url);
    }

    /**
     * Queue tags to be cleared from cache.
     */
    public function clear(array $tags): void
    {
        $this->purgeTags = [
            ...$this->purgeTags,
            ...$tags,
        ];
    }

    public function purgeQueued(): bool
    {
        $tags = collect($this->purgeTags)
            ->flatten()
            ->filter()
            ->unique()
            ->all();

        $tags = apply_filters(self::FILTER_TAGS, $tags);

        if (empty($tags)) {
            return true;
        }

        $urls = $this->store->get($tags);

        if (empty($urls)) {
            return true;
        }

        // Run all invalidators but keep track if something failed.
        $result = collect($this->invalidators)
            ->map(fn ($invalidator) => $invalidator->clear($urls, $tags))
            // Return false if any of the invalidators did
            ->reduce(fn ($result, $invalidatorResult) => $invalidatorResult ? $result : false, true);

        if ($result) {
            // Clear tag caches only if the invalidators succeeded.
            $result = $this->store->clear($urls, $tags);
        }

        return $result;
    }

    /**
     * Flush all caches.
     */
    public function flush(): bool
    {
        // Run all invalidators but keep track if something failed.
        $result = collect($this->invalidators)
            ->map(fn ($invalidator) => $invalidator->flush())
            // Return false if any of the invalidators did
            ->reduce(fn ($result, $invalidatorResult) => $invalidatorResult ? $result : false, true);

        if ($result) {
            // Flush the tag caches only if invalidators succeeded.
            $result = $this->store->flush();
        }

        return $result;
    }
}
