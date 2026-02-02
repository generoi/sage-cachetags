<?php

namespace Genero\Sage\CacheTags;

use Genero\Sage\CacheTags\Contracts\Action;
use Genero\Sage\CacheTags\Contracts\Invalidator;
use Genero\Sage\CacheTags\Contracts\Store;

class CacheTags
{
    const FILTER_TAGS = 'cachetags/filter-tags';

    protected static ?CacheTags $instance = null;

    /**
     * @var string[]
     */
    protected array $cacheTags = [];

    /**
     * @var string[]
     */
    protected array $purgeTags = [];

    /**
     * @var Action[]
     */
    protected array $actions = [];

    protected function __construct(
        public readonly Store $store,
        public readonly bool $debug = false,
        public readonly ?string $httpHeader = 'Cache-Tag',
        /** @var Invalidator[] */
        public readonly array $invalidators = [],
    ) {}

    public static function getInstance(): ?CacheTags
    {
        return self::$instance;
    }

    /**
     * Create or get the singleton instance.
     */
    public static function make(
        Store $store,
        bool $debug = false,
        ?string $httpHeader = 'Cache-Tag',
        /** @var Invalidator[] */
        array $invalidators = [],
    ): CacheTags {
        if (self::$instance === null) {
            self::$instance = new self($store, $debug, $httpHeader, $invalidators);
        }

        return self::$instance;
    }

    /**
     * Add a set of cache tags to this page load.
     *
     * @param  string[]  $tags
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
     *
     * @return string[]
     */
    public function get(): array
    {
        $tags = Util::normalizeTags($this->cacheTags);

        return apply_filters(self::FILTER_TAGS, $tags);
    }

    /**
     * Save current accumulated tags for current page url.
     */
    public function save(string $url): void
    {
        if (is_admin()) {
            return;
        }

        // Avoid cluttering if there's admin-like urls
        $adminUrl = admin_url();
        if ($adminUrl && str_starts_with($url, $adminUrl)) {
            return;
        }

        $this->store->save($this->get(), $url);
    }

    /**
     * Queue tags to be cleared from cache.
     *
     * @param  string[]  $tags
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
        $tags = Util::normalizeTags($this->purgeTags);
        $tags = apply_filters(self::FILTER_TAGS, $tags);

        if (empty($tags)) {
            return true;
        }

        $urls = $this->store->get($tags);

        if (empty($urls)) {
            return true;
        }

        // Run all invalidators but keep track if something failed.
        $result = array_reduce(
            $this->invalidators,
            fn ($result, $invalidator) => $invalidator->clear($urls, $tags) ? $result : false,
            true
        );

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
        $result = array_reduce(
            $this->invalidators,
            fn ($result, $invalidator) => $invalidator->flush() ? $result : false,
            true
        );

        if ($result) {
            // Flush the tag caches only if invalidators succeeded.
            $result = $this->store->flush();
        }

        return $result;
    }

    /**
     * Bind and track an action instance.
     */
    public function bindAction(Action $action): void
    {
        $action->bind();
        $this->actions[] = $action;
    }

    /**
     * Check if an action is registered (by class name or instance).
     */
    public function hasAction(string|Action $action): bool
    {
        $actionClass = is_string($action) ? $action : get_class($action);

        foreach ($this->actions as $registeredAction) {
            if (get_class($registeredAction) === $actionClass) {
                return true;
            }
        }

        return false;
    }
}
