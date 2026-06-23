<?php

use Genero\Sage\CacheTags\Actions\AutoTag;
use Genero\Sage\CacheTags\Tests\RestTestCase;

/**
 * Opt-in zero-config tagging of queried posts and terms.
 *
 * @covers \Genero\Sage\CacheTags\Actions\AutoTag
 */
class TestAutoTag extends RestTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        (new AutoTag($this->cacheTags))->bind();
        $this->resetCacheTags();
    }

    public function test_a_collection_query_tags_each_post_and_the_archive(): void
    {
        $ids = self::factory()->post->create_many(2, ['post_status' => 'publish']);
        $this->resetCacheTags();

        new WP_Query(['post_type' => 'post', 'post_status' => 'publish']);
        $tags = $this->cacheTags->get();

        $this->assertContains('archive:post', $tags);
        foreach ($ids as $id) {
            $this->assertContains("post:{$id}", $tags);
        }
    }

    public function test_an_empty_collection_still_tags_the_archive(): void
    {
        $this->resetCacheTags();

        // A real collection query that matches nothing still tags the listing,
        // so it refreshes when a matching post is later published.
        new WP_Query(['post_type' => 'post', 'category_name' => 'does-not-exist']);

        $this->assertContains('archive:post', $this->cacheTags->get());
    }

    public function test_fetching_specific_ids_skips_the_archive_tag(): void
    {
        $id = self::factory()->post->create(['post_status' => 'publish']);
        $this->resetCacheTags();

        new WP_Query(['post_type' => 'post', 'post__in' => [$id]]);
        $tags = $this->cacheTags->get();

        $this->assertContains("post:{$id}", $tags);
        $this->assertNotContains('archive:post', $tags);
    }

    public function test_page_queries_do_not_add_a_page_archive_tag(): void
    {
        self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish']);
        $this->resetCacheTags();

        new WP_Query(['post_type' => 'page']);

        $this->assertNotContains('archive:page', $this->cacheTags->get());
    }

    public function test_fetching_terms_tags_them(): void
    {
        $postId = self::factory()->post->create(['post_status' => 'publish']);
        $termId = self::factory()->category->create();
        wp_set_object_terms($postId, [$termId], 'category');
        $this->resetCacheTags();

        get_the_terms($postId, 'category');

        $this->assertContains("term:{$termId}", $this->cacheTags->get());
    }
}
