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
class RestApi extends AbstractAction
{
    const FILTER_RELATED_TAGS = 'cachetags/rest-related-tags';

    const FILTER_CUSTOM_ROUTE_TAGS = 'cachetags/rest-tags';

    const SEARCH_ROUTE = '/wp/v2/search';

    /**
     * Memoized cacheable taxonomies per post type, for the current request.
     *
     * @var array<string, string[]>
     */
    protected array $taxonomies = [];

    /**
     * Map of collection route → listing tag, rebuilt each request.
     *
     * @var array<string, string[]>
     */
    protected array $listingRoutes = [];

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

        $this->listingRoutes = $this->mapListingRoutes();

        // Listing, search and custom-route tags need the dispatched response and
        // must run before the save/header hooks (priority 10).
        \add_filter('rest_post_dispatch', [$this, 'tagResponse'], 9, 3);
    }

    public function tagPost(WP_REST_Response $response, WP_Post $post, WP_REST_Request $request): WP_REST_Response
    {
        if (! Util::isCacheableRestRequest($request)) {
            return $response;
        }

        $this->cacheTags->add([
            ...CoreTags::posts($post),
            ...$this->relatedTags($post, $request),
        ]);

        return $response;
    }

    public function tagTerm(WP_REST_Response $response, WP_Term $term, WP_REST_Request $request): WP_REST_Response
    {
        if (! Util::isCacheableRestRequest($request)) {
            return $response;
        }

        $this->cacheTags->add([
            ...CoreTags::terms($term),
            ...CoreTags::termPages($term),
        ]);

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
     * Dispatch-time tagging for things the per-object filters don't cover:
     * the collection listing tag (added even when zero items are returned),
     * search results (which resolve to posts/terms but fire no prepare filter),
     * and bespoke routes via the cachetags/rest-tags filter.
     */
    public function tagResponse($response, $server, WP_REST_Request $request)
    {
        if (! $response instanceof WP_REST_Response || ! Util::isCacheableRestRequest($request)) {
            return $response;
        }

        $route = $request->get_route();

        if ($listing = $this->listingRoutes[$route] ?? []) {
            $this->cacheTags->add($listing);
        }

        if ($route === self::SEARCH_ROUTE) {
            $this->tagSearchResults($response);
        }

        if ($custom = \apply_filters(self::FILTER_CUSTOM_ROUTE_TAGS, [], $request)) {
            $this->cacheTags->add($custom);
        }

        return $response;
    }

    /**
     * Tag a search response.
     *
     * Each current result is tagged so its content edits purge the page, plus
     * the archive listing tags so publishing/unpublishing any post (which can
     * add or remove a match — including for an empty result set) purges it too.
     */
    protected function tagSearchResults(WP_REST_Response $response): void
    {
        $this->cacheTags->add(CoreTags::archive('any'));

        foreach ((array) $response->get_data() as $result) {
            if (! is_array($result) || empty($result['id'])) {
                continue;
            }

            $this->cacheTags->add(
                ($result['type'] ?? '') === 'term'
                    ? CoreTags::terms((int) $result['id'])
                    : CoreTags::posts((int) $result['id'])
            );
        }
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

        // Hierarchical responses expose their parent (breadcrumbs, ancestry).
        if ($post->post_parent) {
            $tags = [...$tags, ...CoreTags::posts((int) $post->post_parent)];
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
     * Map each REST collection route to the listing tag it should carry.
     *
     * @return array<string, string[]>
     */
    protected function mapListingRoutes(): array
    {
        $routes = [];

        foreach (\get_post_types(['show_in_rest' => true], 'objects') as $postType) {
            $routes[$this->restRoute($postType)] = CoreTags::archive($postType->name);
        }

        foreach (\get_taxonomies(['show_in_rest' => true], 'objects') as $taxonomy) {
            $routes[$this->restRoute($taxonomy)] = CoreTags::taxonomy($taxonomy->name);
        }

        return $routes;
    }

    /**
     * The collection route for a post type or taxonomy object.
     *
     * @param  \WP_Post_Type|\WP_Taxonomy  $object
     */
    protected function restRoute($object): string
    {
        $namespace = $object->rest_namespace ?: 'wp/v2';
        $base = $object->rest_base ?: $object->name;

        return "/{$namespace}/{$base}";
    }
}
