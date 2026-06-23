<?php

namespace Genero\Sage\CacheTags\Actions;

use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Contracts\Action;
use Genero\Sage\CacheTags\Tags\CoreTags;
use Genero\Sage\CacheTags\Util;
use WP_Comment;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_Term;
use WP_User;

/**
 * Collect cache tags for REST API read responses.
 *
 * The front-end collectors in Core run on template_redirect/wp_footer, neither
 * of which fire during a REST request. This action mirrors that behaviour using
 * the per-object rest_prepare_* filters, which run for every item in single and
 * collection responses.
 *
 * A post's related dependencies (terms, author, featured media) are tagged
 * eagerly rather than relying on _embed, since embedded sub-requests run after
 * rest_post_dispatch (where the tags are saved and the header emitted).
 *
 * It is complementary to Core, not a replacement: block-derived tags are still
 * collected via Core's render_block hook when content.rendered is generated.
 */
class RestApi implements Action
{
    const FILTER_RELATED_TAGS = 'cachetags/rest-related-tags';

    const FILTER_CUSTOM_ROUTE_TAGS = 'cachetags/rest-tags';

    public function __construct(protected CacheTags $cacheTags) {}

    /**
     * Memoized cacheable taxonomies per post type, for the current request.
     *
     * @var array<string, string[]>
     */
    protected array $taxonomies = [];

    public function bind(): void
    {
        \add_action('rest_api_init', [$this, 'registerFilters']);
    }

    /**
     * Register per-object tag collectors for every cacheable resource.
     */
    public function registerFilters(): void
    {
        foreach (CoreTags::getCacheablePostTypes() as $postType) {
            \add_filter("rest_prepare_{$postType}", [$this, 'tagPost'], 10, 3);
        }

        foreach (CoreTags::getCacheableTaxonomies() as $taxonomy) {
            \add_filter("rest_prepare_{$taxonomy}", [$this, 'tagTerm'], 10, 3);
        }

        \add_filter('rest_prepare_user', [$this, 'tagUser'], 10, 3);
        \add_filter('rest_prepare_comment', [$this, 'tagComment'], 10, 3);

        // Extension point for bespoke controllers that don't map to a core
        // object. Runs before the save/header hooks (priority 10) so the tags
        // it adds are persisted and emitted like any other.
        \add_filter('rest_post_dispatch', [$this, 'tagCustomRoutes'], 9, 3);
    }

    public function tagPost(WP_REST_Response $response, WP_Post $post, WP_REST_Request $request): WP_REST_Response
    {
        if (! Util::isCacheableRestRequest($request)) {
            return $response;
        }

        $tags = [
            ...CoreTags::posts($post),
            ...$this->relatedTags($post, $request),
        ];

        if ($this->isCollection($request)) {
            $tags = [...$tags, ...CoreTags::archive($post->post_type)];
        }

        $this->cacheTags->add($tags);

        return $response;
    }

    public function tagTerm(WP_REST_Response $response, WP_Term $term, WP_REST_Request $request): WP_REST_Response
    {
        if (! Util::isCacheableRestRequest($request)) {
            return $response;
        }

        $tags = [
            ...CoreTags::terms($term),
            ...CoreTags::termPages($term),
        ];

        if ($this->isCollection($request)) {
            $tags = [...$tags, ...CoreTags::taxonomy($term->taxonomy)];
        }

        $this->cacheTags->add($tags);

        return $response;
    }

    public function tagUser(WP_REST_Response $response, WP_User $user, WP_REST_Request $request): WP_REST_Response
    {
        if (! Util::isCacheableRestRequest($request)) {
            return $response;
        }

        $this->cacheTags->add(CoreTags::users($user));

        return $response;
    }

    public function tagComment(WP_REST_Response $response, WP_Comment $comment, WP_REST_Request $request): WP_REST_Response
    {
        if (! Util::isCacheableRestRequest($request)) {
            return $response;
        }

        $this->cacheTags->add([
            ...CoreTags::comments($comment),
            ...CoreTags::posts((int) $comment->comment_post_ID),
        ]);

        return $response;
    }

    /**
     * Allow tagging of bespoke routes that don't resolve to a core object.
     */
    public function tagCustomRoutes($response, $server, WP_REST_Request $request)
    {
        if (! $response instanceof WP_REST_Response || ! Util::isCacheableRestRequest($request)) {
            return $response;
        }

        $tags = \apply_filters(self::FILTER_CUSTOM_ROUTE_TAGS, [], $request);

        if (! empty($tags)) {
            $this->cacheTags->add($tags);
        }

        return $response;
    }

    /**
     * Cache tags for the objects a post response references (terms, author,
     * featured media), which are exposed as fields regardless of _embed.
     *
     * @return string[]
     */
    protected function relatedTags(WP_Post $post, WP_REST_Request $request): array
    {
        $tags = [];

        foreach ($this->taxonomiesFor($post->post_type) as $taxonomy) {
            $terms = \get_the_terms($post, $taxonomy);
            if (is_array($terms)) {
                $tags = [...$tags, ...CoreTags::terms($terms)];
            }
        }

        if ($post->post_author) {
            $tags = [...$tags, ...CoreTags::users((int) $post->post_author)];
        }

        if ($thumbnailId = \get_post_thumbnail_id($post)) {
            $tags = [...$tags, ...CoreTags::posts((int) $thumbnailId)];
        }

        return \apply_filters(self::FILTER_RELATED_TAGS, $tags, $post, $request);
    }

    /**
     * Cacheable taxonomies registered for a post type, memoized per request.
     *
     * @return string[]
     */
    protected function taxonomiesFor(string $postType): array
    {
        return $this->taxonomies[$postType] ??= array_intersect(
            CoreTags::getCacheableTaxonomies(),
            \get_object_taxonomies($postType)
        );
    }

    /**
     * A request is a collection (archive listing) when it targets no single id.
     */
    protected function isCollection(WP_REST_Request $request): bool
    {
        return empty($request['id']);
    }
}
