<?php

namespace Genero\Sage\CacheTags;

use Genero\Sage\CacheTags\Contracts\Action;
use Genero\Sage\CacheTags\Contracts\Invalidator;
use Genero\Sage\CacheTags\Contracts\Store;
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
     * @var Tag[]
     */
    protected array $cacheTags = [];

    /**
     * @var Tag[]
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
     * Accepts Tag objects and/or plain strings (a site's custom tags, anything
     * built elsewhere); strings are parsed to Tags so the rest of the pipeline
     * works with structure.
     *
     * @param  array<string|Tag|array>  $tags
     */
    public function add(array $tags): void
    {
        foreach (Tag::fromMany($tags) as $tag) {
            $this->cacheTags[] = $tag;
        }

        $this->boundedTags = null;
    }

    /**
     * Return the cache tags for this page load, as strings ready for the header
     * and store.
     *
     * @return string[]
     */
    public function get(): array
    {
        if ($this->boundedTags !== null) {
            return $this->boundedTags;
        }

        $strings = $this->resolveTags($this->cacheTags);

        return $this->boundedTags = Tag::toStrings($this->bound(Tag::fromMany($strings)));
    }

    /**
     * Reduce accumulated Tags to the validated, filtered string set the store,
     * header and invalidators consume: serialize, validate + dedupe, run the
     * (legacy string) FILTER_TAGS filter, then re-validate its output so a custom
     * tag can't smuggle a newline into the header.
     *
     * @param  Tag[]  $tags
     * @return string[]
     */
    protected function resolveTags(array $tags): array
    {
        $strings = Util::normalizeTags(Tag::toStrings($tags));
        $strings = apply_filters(self::FILTER_TAGS, $strings);

        return Util::normalizeTags($strings);
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
     * @param  Tag[]  $tags
     * @return Tag[]
     */
    protected function bound(array $tags): array
    {
        $limit = (int) apply_filters(self::FILTER_MAX_HEADER_BYTES, 16384);

        if ($limit <= 0 || $this->headerLength($tags) <= $limit) {
            return $tags;
        }

        $kept = [];
        $coarse = [];

        foreach ($tags as $tag) {
            $collapsed = $this->collapse($tag);

            if ($collapsed === null) {
                $kept[] = $tag;
            } else {
                $coarse[] = $collapsed;
            }
        }

        // Coarse collapse tags first so they survive the final trim — they keep
        // the page purgeable on any change to that post type / taxonomy.
        $bounded = $this->dedupe([...$coarse, ...$kept]);

        // Tags that can't be collapsed (user:, comment:, option:, …) can still
        // exceed the budget. Rather than let the provider silently drop the
        // overflow (and every key after it), trim deterministically to fit.
        if ($this->headerLength($bounded) > $limit) {
            $bounded = $this->fitToBudget($bounded, $limit);
        }

        return $bounded;
    }

    /**
     * Collapse a high-cardinality post/term tag to its coarse "any" form, or
     * return null when it can't be collapsed (so the caller keeps it as-is).
     *
     * Works on the structured Tag and carries its scopes onto the coarse tag, so
     * there's no string parsing or prefix juggling here.
     */
    protected function collapse(Tag $tag): ?Tag
    {
        $coarse = null;

        if ($tag->type === 'post' && is_int($tag->id)) {
            $type = get_post_type($tag->id);
            $coarse = $type ? Tag::archive($type)->any() : null;
        } elseif ($tag->type === 'term' && is_int($tag->id)) {
            $term = get_term($tag->id);
            $coarse = $term instanceof WP_Term ? Tag::taxonomy($term->taxonomy)->any() : null;
        }

        if ($coarse === null) {
            return null;
        }

        foreach ($tag->scopes as [$dimension, $value]) {
            $coarse = $coarse->scope($dimension, $value);
        }

        return $coarse;
    }

    /**
     * Byte length of the space-joined header for a set of tags.
     *
     * @param  Tag[]  $tags
     */
    protected function headerLength(array $tags): int
    {
        return strlen(implode(' ', Tag::toStrings($tags)));
    }

    /**
     * Remove duplicate tags (compared by their string form), preserving order.
     *
     * @param  Tag[]  $tags
     * @return Tag[]
     */
    protected function dedupe(array $tags): array
    {
        $seen = [];
        $unique = [];

        foreach ($tags as $tag) {
            $string = (string) $tag;
            if (! isset($seen[$string])) {
                $seen[$string] = true;
                $unique[] = $tag;
            }
        }

        return $unique;
    }

    /**
     * Trim a tag list so the space-joined header stays within $limit bytes,
     * preserving order (callers place the must-keep coarse tags first).
     *
     * @param  Tag[]  $tags
     * @return Tag[]
     */
    protected function fitToBudget(array $tags, int $limit): array
    {
        $fitted = [];
        $length = 0;

        foreach ($tags as $tag) {
            $length += ($fitted ? 1 : 0) + strlen((string) $tag); // +1 for the separator
            if ($length > $limit) {
                break;
            }
            $fitted[] = $tag;
        }

        return $fitted;
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
     * Queue tags to be cleared from cache. Accepts strings and/or Tag objects.
     *
     * @param  array<string|Tag|array>  $tags
     */
    public function clear(array $tags): void
    {
        foreach (Tag::fromMany($tags) as $tag) {
            $this->purgeTags[] = $tag;
        }
    }

    public function purgeQueued(): bool
    {
        $tags = $this->resolveTags($this->purgeTags);

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
