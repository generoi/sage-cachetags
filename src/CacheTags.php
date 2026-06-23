<?php

namespace Genero\Sage\CacheTags;

use Genero\Sage\CacheTags\Contracts\Action;
use Genero\Sage\CacheTags\Contracts\Invalidator;
use Genero\Sage\CacheTags\Contracts\Store;
use Genero\Sage\CacheTags\Tags\CoreTags;
use WP_Term;

class CacheTags
{
    const FILTER_TAGS = 'cachetags/filter-tags';

    /**
     * Default ceiling for the combined tag header in bytes. Matches Fastly's
     * Surrogate-Key total limit (16 KB); reaching it makes Fastly drop the
     * offending key and every key after it, so we collapse before then.
     */
    const FILTER_MAX_HEADER_BYTES = 'cachetags/max-header-bytes';

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
        $tags = apply_filters(self::FILTER_TAGS, $tags);

        return $this->bound($tags);
    }

    /**
     * Keep the emitted tag set within the cache provider's header budget.
     *
     * When the combined header would exceed the limit, the high-cardinality
     * per-object tags (a post or term per listed item) are collapsed to their
     * coarse "any" form, which is purged on any change to that post type or
     * taxonomy. The result over-purges rather than silently dropping tags
     * (which a provider would do on overflow, leaving stale content).
     *
     * @param  string[]  $tags
     * @return string[]
     */
    protected function bound(array $tags): array
    {
        $limit = (int) apply_filters(self::FILTER_MAX_HEADER_BYTES, 16384);

        if ($limit <= 0 || strlen(implode(' ', $tags)) <= $limit) {
            return $tags;
        }

        $kept = [];
        $coarse = [];

        foreach ($tags as $tag) {
            if (str_starts_with($tag, 'post:')) {
                $type = get_post_type((int) substr($tag, strlen('post:')));
                if ($type) {
                    $coarse = [...$coarse, ...CoreTags::anyArchive($type)];

                    continue;
                }
            } elseif (str_starts_with($tag, 'term:') && ! str_ends_with($tag, ':full')) {
                $term = get_term((int) explode(':', $tag)[1]);
                if ($term instanceof WP_Term) {
                    $coarse = [...$coarse, ...CoreTags::anyTerm($term->taxonomy)];

                    continue;
                }
            }

            $kept[] = $tag;
        }

        return Util::normalizeTags([...$kept, ...$coarse]);
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
