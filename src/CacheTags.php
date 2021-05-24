<?php

namespace Genero\Sage\CacheTags;

use Genero\Sage\CacheTags\Contracts\Invalidator;
use Genero\Sage\CacheTags\Contracts\Store;
use WP_Term;
use Exception;
use Illuminate\Support\Facades\Log;
use WP_Query;

class CacheTags
{
    /**
     * @var string[]
     */
    protected $cacheTags;

    /**
     * @var Store $store
     */
    protected $store;

    /**
     * @var Invalidator[] $invalidators
     */
    protected $invalidators = [];

    public function __construct(Store $store, Invalidator ...$invalidators)
    {
        $this->store = $store;
        $this->invalidators = $invalidators;
        $this->cacheTags = [];
    }

    /**
     * Return cache tags for a WP_Query.
     *
     * @param WP_Query $query
     */
    public static function getQueryCacheTags(WP_Query $query): array
    {
        return collect($query->get_posts())
            ->pluck('ID')
            ->map(function ($postId) {
                return self::getPostCacheTags($postId);
            })
            ->flatten()
            ->all();
    }

    /**
     * Return cache tags for one or many post types.
     *
     * @param string|string[] $postTypes
     */
    public static function getPostTypeCacheTags($postTypes): array
    {
        if (is_string($postTypes) && $postTypes === 'any') {
            $postTypes = self::getCacheablePostTypes();
        } elseif (is_string($postTypes)) {
            $postTypes = [$postTypes];
        }
        return $postTypes;
    }

    /**
     * Return cache tags for one or many post type archives.
     *
     * @param string|string[] $postTypes
     */
    public static function getArchiveCacheTags($postTypes): array
    {
        return collect(self::getPostTypeCacheTags($postTypes))
            ->map(function ($postType) {
                return sprintf('archive:%s', $postType);
            })
            ->all();
    }

    /**
     * Return cache tags for a post.
     *
     * @param int $postId
     */
    public static function getPostCacheTags(int $postId = null): array
    {
        if (!$postId) {
            $postId = \get_the_ID();
        }
        return ["post:$postId"];
    }

    /**
     * Return cache tags for multiple posts.
     *
     * @param int[] $postIds
     */
    public static function getMultiplePostCacheTags(array $postIds): array
    {
        return collect($postIds)
            ->map(function ($postId) {
                return CacheTags::getPostCacheTags($postId);
            })
            ->flatten()
            ->all();
    }

    /**
     * Return cache tags for one term.
     *
     * @param int|null $termId
     */
    public static function getTermCacheTags(int $termId = null): array
    {
        if (!$termId) {
            $term = \get_queried_object();
            if (!($term instanceof WP_Term)) {
                throw new Exception();
            }
            $termId = $term->term_id;
        }

        return ["term:$termId"];
    }

    /**
     * Return cache tags for multiple terms.
     *
     * @param int[] $termIds
     */
    public static function getMultipleTermCacheTags(array $termIds): array
    {
        return collect($termIds)
            ->map(function ($termId) {
                return CacheTags::getTermCacheTags($termId);
            })
            ->flatten()
            ->all();
    }

    /**
     * Return all cacheable post types.
     */
    protected static function getCacheablePostTypes(): array
    {
        return \get_post_types(['exclude_from_search' => false]);
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
        return collect($this->cacheTags)
            ->filter()
            ->unique()
            ->all();
    }

    /**
     * Save current accumulated tags for current page url.
     */
    public function save(string $url): void
    {
        $this->store->save($this->get(), $url);
    }

    /**
     * Clear caches for tags.
     */
    public function clear(array $tags): bool
    {
        $urls = $this->store->get($tags);
        // Clear tag caches
        $result = $this->store->clear($urls);

        return collect($this->invalidators)
            ->map(function ($invalidator) use ($urls) {
                return $invalidator->clear($urls);
            })
            ->reduce(function ($result, $invalidatorResult) {
                return $invalidatorResult ? $result : false;
            }, $result);
    }

    /**
     * Flush all caches.
     */
    public function flush(): bool
    {
        $result = $this->store->flush();

        return collect($this->invalidators)
            ->map(function ($invalidator) {
                return $invalidator->flush();
            })
            ->reduce(function ($result, $invalidatorResult) {
                return $invalidatorResult ? $result : false;
            }, $result);
    }
}
