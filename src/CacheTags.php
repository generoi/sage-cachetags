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
     * Memoized result of get() — the normalize + filter + bound pipeline is run
     * once per request, not on each of the save() and header consumers. Reset
     * whenever tags change.
     *
     * @var string[]|null
     */
    protected ?array $boundedTags = null;

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

        $this->boundedTags = null;
    }

    /**
     * Return all cache tags for this page load.
     *
     * @return string[]
     */
    public function get(): array
    {
        if ($this->boundedTags !== null) {
            return $this->boundedTags;
        }

        $tags = Util::normalizeTags($this->cacheTags);
        $tags = apply_filters(self::FILTER_TAGS, $tags);

        // Re-validate after the filter so a custom tag (e.g. one containing a
        // newline) can't reach the Cache-Tag header.
        $tags = Util::normalizeTags($tags);

        return $this->boundedTags = $this->bound($tags);
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
            $collapsed = $this->collapse($tag);

            if ($collapsed === null) {
                $kept[] = $tag;
            } else {
                $coarse = [...$coarse, ...$collapsed];
            }
        }

        // Coarse collapse tags first so they survive the final trim — they keep
        // the page purgeable on any change to that post type / taxonomy.
        $bounded = Util::normalizeTags([...$coarse, ...$kept]);

        // Tags that can't be collapsed (user:, comment:, option:, …) can still
        // exceed the budget. Rather than let the provider silently drop the
        // overflow (and every key after it), trim deterministically to fit.
        if (strlen(implode(' ', $bounded)) > $limit) {
            $bounded = $this->fitToBudget($bounded, $limit);
        }

        return $bounded;
    }

    /**
     * Collapse a high-cardinality post:/term: tag to its coarse "any" form, or
     * return null when it can't be collapsed (so the caller keeps it as-is). A
     * multisite "site:N:" prefix is preserved so the coarse tag still matches
     * what was stored.
     *
     * @return string[]|null
     */
    protected function collapse(string $tag): ?array
    {
        [$prefix, $inner] = $this->splitSitePrefix($tag);

        if (str_starts_with($inner, 'post:')) {
            $type = get_post_type((int) substr($inner, strlen('post:')));

            return $type ? $this->prefixed($prefix, CoreTags::anyArchive($type)) : null;
        }

        if (str_starts_with($inner, 'term:')) {
            $term = get_term((int) explode(':', $inner)[1]);

            return $term instanceof WP_Term ? $this->prefixed($prefix, CoreTags::anyTerm($term->taxonomy)) : null;
        }

        return null;
    }

    /**
     * Split an optional multisite "site:N:" prefix off the front of a tag.
     *
     * @return array{0: string, 1: string} [prefix, remainder]
     */
    protected function splitSitePrefix(string $tag): array
    {
        if (preg_match('/^(site:\d+:)(.*)$/', $tag, $matches)) {
            return [$matches[1], $matches[2]];
        }

        return ['', $tag];
    }

    /**
     * Trim a tag list so the space-joined header stays within $limit bytes,
     * preserving order (callers place the must-keep coarse tags first).
     *
     * @param  string[]  $tags
     * @return string[]
     */
    protected function fitToBudget(array $tags, int $limit): array
    {
        $fitted = [];
        $length = 0;

        foreach ($tags as $tag) {
            $length += ($fitted ? 1 : 0) + strlen($tag); // +1 for the separator
            if ($length > $limit) {
                break;
            }
            $fitted[] = $tag;
        }

        return $fitted;
    }

    /**
     * Re-apply a "site:N:" prefix to a set of (coarse) tags.
     *
     * @param  string[]  $tags
     * @return string[]
     */
    protected function prefixed(string $prefix, array $tags): array
    {
        if ($prefix === '') {
            return $tags;
        }

        return array_map(fn ($tag) => $prefix.$tag, $tags);
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
        $tags = Util::normalizeTags($tags);

        if (empty($tags)) {
            return true;
        }

        $urls = $this->store->get($tags);

        // Note: we do NOT bail when $urls is empty. Tag-based invalidators
        // (Fastly purges by Surrogate-Key) must still run — the edge can hold an
        // object whose URL the store no longer has (store flushed, query-string
        // storage disabled, a deferred clear ran). URL-based invalidators given
        // an empty URL list simply no-op.

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

        // Let sites attach logging/metrics, and surface a failure that would
        // otherwise be swallowed (leaving content stale at the edge).
        do_action('cachetags/purged', $tags, $urls, $result);

        if (! $result) {
            $this->logFailure(sprintf('purge failed for %d tag(s): %s', count($tags), implode(' ', array_slice($tags, 0, 20))));
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

        do_action('cachetags/flushed', $result);

        if (! $result) {
            $this->logFailure('flush failed');
        }

        return $result;
    }

    protected function logFailure(string $message): void
    {
        $message = "[cachetags] {$message}";

        // Prefer a framework logger (Acorn/Laravel) so failures land in the
        // site's normal log pipeline; surface to the operator under WP-CLI;
        // otherwise fall back to the PHP error log.
        if (function_exists('logger')) {
            logger()->error($message);
        } elseif (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            \WP_CLI::warning($message);
        } else {
            error_log($message);
        }
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
