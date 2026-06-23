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
 * Uses the_posts (fired with the final posts, after the query, for every
 * WP_Query incl. short-circuited ones) rather than short-circuiting
 * posts_pre_query, which avoids the recursion/format pitfalls of running the
 * query from within its own pre-query filter.
 */
class AutoTag implements Action
{
    const FILTER_EXCLUDED_ARCHIVE_TYPES = 'cachetags/autotag-excluded-archive-types';

    public function __construct(protected CacheTags $cacheTags) {}

    public function bind(): void
    {
        \add_filter('the_posts', [$this, 'tagQueriedPosts'], PHP_INT_MAX, 2);
        \add_filter('get_the_terms', [$this, 'tagQueriedTerms'], PHP_INT_MAX);
    }

    /**
     * @param  mixed  $posts  Array of WP_Post, ids, or id=>parent rows.
     * @return mixed
     */
    public function tagQueriedPosts($posts, WP_Query $query)
    {
        if (! is_array($posts) || (is_admin() && ! wp_doing_ajax())) {
            return $posts;
        }

        $tags = [];

        // Archive listing tags for the queried types, unless this is a single
        // resource or a fetch of specific ids.
        if (! $query->get('post__in') && ! $query->is_singular()) {
            foreach ($this->archiveTypes($query) as $type) {
                $tags[] = CoreTags::archive($type);
            }
        }

        foreach ($posts as $post) {
            $tags[] = $this->postTag($post);
        }

        $this->cacheTags->add($tags);

        return $posts;
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
