<?php

use Genero\Sage\CacheTags\Actions\RestApi;
use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Tests\RestTestCase;

/**
 * End-to-end REST tagging: real endpoints, real responses, real store rows.
 *
 * @covers \Genero\Sage\CacheTags\Actions\RestApi
 * @covers \Genero\Sage\CacheTags\Bootstrap::saveCacheTagsRest
 * @covers \Genero\Sage\CacheTags\Bootstrap::restUrl
 */
class TestRestContentTagging extends RestTestCase
{
    private int $authorId;

    private int $categoryId;

    private int $postId;

    public function set_up(): void
    {
        parent::set_up();

        $this->set_permalink_structure('/%postname%/');

        $this->authorId = self::factory()->user->create(['role' => 'author']);
        $this->categoryId = self::factory()->category->create();
        $this->postId = self::factory()->post->create([
            'post_status' => 'publish',
            'post_author' => $this->authorId,
            'post_category' => [$this->categoryId],
        ]);
    }

    /**
     * Dispatch a request and run it through rest_post_dispatch, mirroring what
     * WP_REST_Server::serve_request() does for a real HTTP request (dispatch()
     * alone does not fire that filter, where save + header live).
     */
    private function dispatch(WP_REST_Request $request): WP_REST_Response
    {
        $server = rest_get_server();
        $response = rest_ensure_response($server->dispatch($request));

        return apply_filters('rest_post_dispatch', $response, $server, $request);
    }

    public function test_single_post_is_tagged_with_post_terms_and_author(): void
    {
        $response = $this->dispatch(new WP_REST_Request('GET', "/wp/v2/posts/{$this->postId}"));
        $tags = $this->cacheTagHeader($response);

        $this->assertContains("post:{$this->postId}", $tags);
        $this->assertContains("term:{$this->categoryId}", $tags);
        $this->assertContains("user:{$this->authorId}", $tags);
        $this->assertNotContains('archive:post', $tags, 'A single post is not an archive listing');

        $this->assertContains(
            $this->storedUrl('/wp/v2/posts/'.$this->postId),
            $this->storedUrls("post:{$this->postId}")
        );
    }

    public function test_collection_is_tagged_with_archive_and_each_post(): void
    {
        $response = $this->dispatch(new WP_REST_Request('GET', '/wp/v2/posts'));
        $tags = $this->cacheTagHeader($response);

        $this->assertContains('archive:post', $tags);
        $this->assertContains("post:{$this->postId}", $tags);
    }

    public function test_term_endpoint_is_tagged(): void
    {
        $response = $this->dispatch(new WP_REST_Request('GET', "/wp/v2/categories/{$this->categoryId}"));
        $tags = $this->cacheTagHeader($response);

        $this->assertContains("term:{$this->categoryId}", $tags);
        $this->assertContains("term:{$this->categoryId}:full", $tags);
    }

    public function test_comment_endpoint_is_tagged_with_comment_and_post(): void
    {
        $commentId = self::factory()->comment->create([
            'comment_post_ID' => $this->postId,
            'comment_approved' => '1',
        ]);

        $response = $this->dispatch(new WP_REST_Request('GET', "/wp/v2/comments/{$commentId}"));
        $tags = $this->cacheTagHeader($response);

        $this->assertContains("comment:{$commentId}", $tags);
        $this->assertContains("post:{$this->postId}", $tags);
    }

    public function test_rendered_block_content_contributes_tags_via_core(): void
    {
        $postId = self::factory()->post->create([
            'post_status' => 'publish',
            'post_author' => $this->authorId,
            'post_content' => '<!-- wp:latest-posts /-->',
        ]);

        $response = $this->dispatch(new WP_REST_Request('GET', "/wp/v2/posts/{$postId}"));

        // The core/latest-posts block resolves to archive:post via Core's
        // render_block hook — which only fires here because content.rendered
        // is generated during the REST request.
        $this->assertContains('archive:post', $this->cacheTagHeader($response));
    }

    public function test_embedded_resources_do_not_duplicate_tags(): void
    {
        $request = new WP_REST_Request('GET', "/wp/v2/posts/{$this->postId}");
        $request->set_query_params(['_embed' => '1']);

        $tags = $this->cacheTagHeader($this->dispatch($request));

        $this->assertSame(array_values(array_unique($tags)), $tags, 'Tags are de-duplicated');
        $this->assertContains("user:{$this->authorId}", $tags);
        $this->assertContains("term:{$this->categoryId}", $tags);
    }

    public function test_featured_media_is_tagged_as_a_dependency(): void
    {
        $thumbnailId = self::factory()->attachment->create_upload_object(DIR_TESTDATA.'/images/canola.jpg', $this->postId);
        set_post_thumbnail($this->postId, $thumbnailId);

        $tags = $this->cacheTagHeader($this->dispatch(new WP_REST_Request('GET', "/wp/v2/posts/{$this->postId}")));

        $this->assertContains("post:{$thumbnailId}", $tags);
    }

    public function test_edit_context_is_not_tagged(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $request = new WP_REST_Request('GET', "/wp/v2/posts/{$this->postId}");
        $request->set_query_params(['context' => 'edit']);

        $this->assertNotContains("post:{$this->postId}", $this->cacheTagHeader($this->dispatch($request)));
    }

    public function test_authenticated_request_is_not_tagged(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $tags = $this->cacheTagHeader($this->dispatch(new WP_REST_Request('GET', "/wp/v2/posts/{$this->postId}")));

        $this->assertEmpty($tags, 'Authenticated responses must not be tagged or emit a Cache-Tag header');
    }

    public function test_unregistered_query_params_are_excluded_from_the_store_url(): void
    {
        $request = new WP_REST_Request('GET', '/wp/v2/posts');
        $request->set_query_params(['cache_buster' => 'xyz']);

        $this->dispatch($request);
        $urls = $this->storedUrls('archive:post');

        $this->assertContains($this->storedUrl('/wp/v2/posts'), $urls);
        $this->assertEmpty(
            array_filter($urls, fn ($url) => str_contains($url, 'cache_buster')),
            'Arbitrary client params must not fork the store key'
        );
    }

    public function test_filtered_collection_variants_get_distinct_store_urls(): void
    {
        self::factory()->post->create_many(2, ['post_status' => 'publish']);

        $this->dispatch($this->collection(['per_page' => '1']));
        $this->dispatch($this->collection(['per_page' => '2']));

        $urls = $this->storedUrls('archive:post');

        $this->assertContains($this->storedUrl('/wp/v2/posts', ['per_page' => '1']), $urls);
        $this->assertContains($this->storedUrl('/wp/v2/posts', ['per_page' => '2']), $urls);
    }

    public function test_custom_route_tags_via_filter(): void
    {
        $callback = fn (array $tags, WP_REST_Request $request) => [...$tags, 'custom:1'];
        add_filter(RestApi::FILTER_CUSTOM_ROUTE_TAGS, $callback, 10, 2);

        try {
            $tags = $this->cacheTagHeader($this->dispatch(new WP_REST_Request('GET', '/wp/v2/types')));
            $this->assertContains('custom:1', $tags);
        } finally {
            remove_filter(RestApi::FILTER_CUSTOM_ROUTE_TAGS, $callback, 10);
        }
    }

    public function test_editing_a_post_purges_its_stored_rest_url(): void
    {
        $url = $this->storedUrl('/wp/v2/posts/'.$this->postId);

        $this->dispatch(new WP_REST_Request('GET', "/wp/v2/posts/{$this->postId}"));
        $this->assertContains($url, $this->storedUrls("post:{$this->postId}"));

        wp_update_post(['ID' => $this->postId, 'post_title' => 'Updated']);
        $this->cacheTags->purgeQueued();

        $this->assertNotContains($url, $this->storedUrls("post:{$this->postId}"));
    }

    public function test_empty_collection_is_still_tagged_with_its_listing(): void
    {
        $emptyCategory = self::factory()->category->create();

        $request = new WP_REST_Request('GET', '/wp/v2/posts');
        $request->set_query_params(['categories' => (string) $emptyCategory]);
        $response = $this->dispatch($request);

        $this->assertSame([], $response->get_data(), 'Collection returns no items');
        $this->assertContains('archive:post', $this->cacheTagHeader($response));
    }

    public function test_show_in_rest_only_post_type_is_tagged(): void
    {
        register_post_type('headless_thing', [
            'public' => false,
            'show_in_rest' => true,
        ]);

        try {
            $id = self::factory()->post->create(['post_type' => 'headless_thing', 'post_status' => 'publish']);
            $tags = $this->cacheTagHeader($this->dispatch(new WP_REST_Request('GET', "/wp/v2/headless_thing/{$id}")));

            $this->assertContains("post:{$id}", $tags);
        } finally {
            unregister_post_type('headless_thing');
        }
    }

    public function test_search_results_are_tagged(): void
    {
        $postId = self::factory()->post->create([
            'post_status' => 'publish',
            'post_title' => 'Zorblax unique needle',
        ]);

        $request = new WP_REST_Request('GET', '/wp/v2/search');
        $request->set_query_params(['search' => 'Zorblax']);
        $tags = $this->cacheTagHeader($this->dispatch($request));

        $this->assertContains("post:{$postId}", $tags, 'Matched post is tagged for content edits');
        $this->assertContains('archive:post', $tags, 'Listing tag so newly published matches refresh results');
    }

    public function test_oversized_tag_sets_collapse_to_coarse_any_tags(): void
    {
        add_filter(CacheTags::FILTER_MAX_HEADER_BYTES, fn () => 30);
        self::factory()->post->create_many(3, ['post_status' => 'publish']);

        $tags = $this->cacheTagHeader($this->dispatch(new WP_REST_Request('GET', '/wp/v2/posts')));

        $this->assertContains('archive:post:any', $tags);
        $this->assertNotContains("post:{$this->postId}", $tags, 'Individual post tags are collapsed when over budget');
    }

    public function test_collapsed_collection_is_purged_on_any_post_change(): void
    {
        add_filter(CacheTags::FILTER_MAX_HEADER_BYTES, fn () => 30);

        $url = $this->storedUrl('/wp/v2/posts');
        $this->dispatch(new WP_REST_Request('GET', '/wp/v2/posts'));
        $this->assertContains($url, $this->storedUrls('archive:post:any'));

        // Editing any post of the type clears the coarse tag the collapsed
        // collection was stored under.
        $this->resetCacheTags();
        wp_update_post(['ID' => $this->postId, 'post_title' => 'Updated']);
        $this->cacheTags->purgeQueued();

        $this->assertNotContains($url, $this->storedUrls('archive:post:any'));
    }

    private function collection(array $queryParams): WP_REST_Request
    {
        $request = new WP_REST_Request('GET', '/wp/v2/posts');
        $request->set_query_params($queryParams);

        return $request;
    }

    public function test_user_endpoint_is_tagged_with_the_user(): void
    {
        // The author is exposed at /users because they have a published post.
        $response = $this->dispatch(new WP_REST_Request('GET', "/wp/v2/users/{$this->authorId}"));

        $this->assertContains("user:{$this->authorId}", $this->cacheTagHeader($response));
    }

    public function test_hierarchical_post_is_tagged_with_its_parent(): void
    {
        $parentId = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish']);
        $childId = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_parent' => $parentId,
        ]);

        $response = $this->dispatch(new WP_REST_Request('GET', "/wp/v2/pages/{$childId}"));
        $tags = $this->cacheTagHeader($response);

        $this->assertContains("post:{$childId}", $tags);
        $this->assertContains("post:{$parentId}", $tags, 'breadcrumbs/ancestry depend on the parent');
    }
}
