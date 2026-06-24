<?php

namespace Genero\Sage\CacheTags\Actions;

use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Contracts\Action;
use Genero\Sage\CacheTags\Tags\CoreTags;
use WP_Post;
use WP_Query;
use WP_Term;

/**
 * Zero-config tagging: tag every post returned by a WP_Query and every term
 * fetched via get_the_terms, so a theme doesn't have to wire tags per template
 * or block.
 *
 * Opt-in and complementary to Core — enable both; the header budget collapse in
 * CacheTags keeps the broader tag set bounded.
 *
 * Hooks posts_pre_query rather than the_posts because get_posts() (and
 * get_children()/get_pages()) force suppress_filters=true, which skips the_posts
 * entirely — so a `foreach (get_posts(...) as $post)` loop would go untagged.
 * posts_pre_query fires for every WP_Query regardless of suppress_filters. We
 * run the query once ourselves (guarded against re-entry) and return its rows to
 * short-circuit, so the query still executes exactly once.
 */
class AutoTag implements Action
{
    const FILTER_EXCLUDED_ARCHIVE_TYPES = 'cachetags/autotag-excluded-archive-types';

    /** Guards against re-entry while we run the query inside its own pre-query filter. */
    protected bool $running = false;

    public function __construct(protected CacheTags $cacheTags) {}

    public function bind(): void
    {
        \add_filter('posts_pre_query', [$this, 'tagQueriedPosts'], PHP_INT_MAX, 2);
        \add_filter('get_the_terms', [$this, 'tagQueriedTerms'], PHP_INT_MAX);
    }

    /**
     * @param  WP_Post[]|int[]|null  $posts  Short-circuit value (null unless another filter set it).
     * @return WP_Post[]|int[]|null
     */
    public function tagQueriedPosts($posts, WP_Query $query)
    {
        // Bail if re-entrant (our own get_posts() call below), if another filter
        // already short-circuited, or in a non-AJAX admin request.
        if ($this->running || $posts !== null || (is_admin() && ! wp_doing_ajax())) {
            return $posts;
        }

        // Run the query once; the guard turns the re-entrant pre-query call into
        // a no-op so WordPress executes it normally and fills $query->posts.
        $this->running = true;
        $query->get_posts();
        $this->running = false;

        $tags = [];

        // Archive listing tags for the queried types, unless this is a single
        // resource or a fetch of specific ids.
        if (! $query->get('post__in') && ! $query->is_singular()) {
            foreach ($this->archiveTypes($query) as $type) {
                $tags[] = CoreTags::archive($type);
            }
        }

        foreach ($query->posts as $post) {
            $tags[] = $this->postTag($post);
        }

        $this->cacheTags->add($tags);

        // Return the rows so the outer query short-circuits instead of running
        // a second time. $query->posts is already in the requested `fields`
        // shape (WP_Post[], ids, or id=>parent rows).
        return $query->posts;
    }

    /**
     * @param  mixed  $terms
     * @return mixed
     */
    public function tagQueriedTerms($terms)
    {
        if (! is_array($terms) || (is_admin() && ! wp_doing_ajax())) {
            return $terms;
        }

        $tags = [];
        foreach ($terms as $term) {
            if ($term instanceof WP_Term && CoreTags::isCacheableTaxonomy($term->taxonomy)) {
                $tags[] = CoreTags::terms($term->term_id);
            }
        }

        $this->cacheTags->add($tags);

        return $terms;
    }

    /**
     * Tag for a single queried post row in whatever shape `fields` produced.
     *
     * @param  mixed  $post
     * @return string[]
     */
    protected function postTag($post): array
    {
        if ($post instanceof WP_Post) {
            return CoreTags::isCacheablePostType($post->post_type)
                ? CoreTags::posts($post->ID)
                : [];
        }

        if (is_numeric($post)) {
            return CoreTags::posts((int) $post);
        }

        return isset($post->ID) ? CoreTags::posts((int) $post->ID) : [];
    }

    /**
     * Cacheable post types a collection query lists, minus excluded ones.
     *
     * @return string[]
     */
    protected function archiveTypes(WP_Query $query): array
    {
        $postTypes = $query->get('post_type') ?: 'post';

        if ($postTypes === 'any') {
            $postTypes = CoreTags::getCacheablePostTypes();
        }

        $postTypes = array_filter(
            (array) $postTypes,
            fn ($type) => CoreTags::isCacheablePostType($type)
        );

        // Pages have no archive listing by default; editing one shouldn't purge
        // every page-querying view.
        $excluded = \apply_filters(self::FILTER_EXCLUDED_ARCHIVE_TYPES, ['page']);

        return array_values(array_diff($postTypes, $excluded));
    }
}
