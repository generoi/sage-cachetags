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

    public function test_get_posts_is_tagged_despite_suppressing_filters(): void
    {
        // get_posts() forces suppress_filters=true, which skips the_posts — the
        // exact case the_posts misses and posts_pre_query catches.
        $id = self::factory()->post->create(['post_status' => 'publish']);
        $this->resetCacheTags();

        $posts = get_posts(['include' => [$id]]);

        $this->assertCount(1, $posts, 'get_posts still returns its result');
        $this->assertInstanceOf(WP_Post::class, $posts[0]);
        $this->assertSame($id, $posts[0]->ID);
        $this->assertContains("post:{$id}", $this->cacheTags->get());
    }

    public function test_short_circuit_preserves_the_requested_fields_shape(): void
    {
        $ids = self::factory()->post->create_many(2, ['post_status' => 'publish']);
        $this->resetCacheTags();

        // fields=ids must come back as ints, not WP_Post objects — i.e. running
        // the query inside posts_pre_query must not corrupt the return value.
        $result = get_posts(['include' => $ids, 'fields' => 'ids']);

        sort($result);
        sort($ids);
        $this->assertSame($ids, array_map('intval', $result));
        $this->assertContainsOnly('integer', $result);
        foreach ($ids as $id) {
            $this->assertContains("post:{$id}", $this->cacheTags->get());
        }
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
