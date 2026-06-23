<?php

use Genero\Sage\CacheTags\Tests\RestTestCase;

/**
 * Tags are only useful if the data change that invalidates them clears them.
 *
 * @covers \Genero\Sage\CacheTags\Actions\Core
 */
class TestPurgeSymmetry extends RestTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        $this->set_permalink_structure('/%postname%/');
    }

    public function test_assigning_a_term_purges_the_post(): void
    {
        $postId = self::factory()->post->create(['post_status' => 'publish']);
        $this->dispatch("/wp/v2/posts/{$postId}");
        $url = $this->storedUrl("/wp/v2/posts/{$postId}");
        $this->assertContains($url, $this->storedUrls("post:{$postId}"));

        $this->resetCacheTags();
        wp_set_object_terms($postId, [self::factory()->category->create()], 'category');
        $this->cacheTags->purgeQueued();

        $this->assertNotContains($url, $this->storedUrls("post:{$postId}"));
    }

    public function test_editing_an_attachment_purges_posts_that_feature_it(): void
    {
        $postId = self::factory()->post->create(['post_status' => 'publish']);
        $thumbnailId = self::factory()->attachment->create_upload_object(DIR_TESTDATA.'/images/canola.jpg', $postId);
        set_post_thumbnail($postId, $thumbnailId);

        $this->dispatch("/wp/v2/posts/{$postId}");
        $url = $this->storedUrl("/wp/v2/posts/{$postId}");
        $this->assertContains($url, $this->storedUrls("post:{$thumbnailId}"));

        $this->resetCacheTags();
        wp_update_post(['ID' => $thumbnailId, 'post_excerpt' => 'New caption']);
        $this->cacheTags->purgeQueued();

        $this->assertNotContains($url, $this->storedUrls("post:{$thumbnailId}"));
    }

    public function test_child_page_is_tagged_with_its_parent(): void
    {
        $parentId = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish']);
        $childId = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_parent' => $parentId,
        ]);

        $tags = $this->cacheTagHeader($this->dispatch("/wp/v2/pages/{$childId}"));

        $this->assertContains("post:{$parentId}", $tags);
    }

    private function dispatch(string $route): WP_REST_Response
    {
        $request = new WP_REST_Request('GET', $route);
        $server = rest_get_server();
        $response = rest_ensure_response($server->dispatch($request));

        return apply_filters('rest_post_dispatch', $response, $server, $request);
    }
}
