<?php

use Genero\Sage\CacheTags\Actions\Feed;
use Genero\Sage\CacheTags\Tests\RestTestCase;

/**
 * @covers \Genero\Sage\CacheTags\Actions\Feed
 */
class TestFeed extends RestTestCase
{
    /** Tags the Feed action adds for the given URL. */
    private function feedTags(string $url): array
    {
        $this->resetCacheTags();
        $this->go_to($url);
        // add() resets get()'s memo, so a request adding nothing reads [marker].
        $this->cacheTags->add('marker');
        (new Feed($this->cacheTags))->addFeedCacheTags();

        return array_values(array_diff($this->cacheTags->get(), ['marker']));
    }

    /** is_home() is never set on a feed, so the main feed needs its own listing tag. */
    public function test_main_feed_is_tagged_as_the_post_listing(): void
    {
        $tags = $this->feedTags(home_url('/?feed=rss2'));

        $this->assertContains('archive:post', $tags);
        $this->assertContains('option:blogname', $tags);
    }

    public function test_feed_is_tagged_with_its_items_authors_and_terms(): void
    {
        $authorId = self::factory()->user->create(['role' => 'author']);
        $categoryId = self::factory()->category->create();
        $tagId = self::factory()->tag->create();
        $postId = self::factory()->post->create(['post_status' => 'publish', 'post_author' => $authorId]);
        wp_set_post_categories($postId, [$categoryId]);
        wp_set_post_tags($postId, [$tagId]);

        $tags = $this->feedTags(home_url('/?feed=rss2'));

        $this->assertContains("post:{$postId}", $tags);
        $this->assertContains("user:{$authorId}", $tags);
        $this->assertContains("term:{$categoryId}", $tags);
        $this->assertContains("term:{$tagId}", $tags);
    }

    public function test_category_feed_is_tagged_like_its_term_page(): void
    {
        $categoryId = self::factory()->category->create();
        self::factory()->post->create(['post_status' => 'publish', 'post_category' => [$categoryId]]);

        $tags = $this->feedTags(home_url('/?feed=rss2&cat='.$categoryId));

        $this->assertContains("term:{$categoryId}", $tags);
        $this->assertContains('taxonomy:category', $tags);
        $this->assertNotContains('archive:post', $tags);
    }

    /** A new post doesn't clear user:{id}, so the author feed needs the listing tag. */
    public function test_author_feed_is_tagged_with_the_author_and_the_post_listing(): void
    {
        $authorId = self::factory()->user->create(['role' => 'author']);
        self::factory()->post->create(['post_status' => 'publish', 'post_author' => $authorId]);

        $tags = $this->feedTags(home_url('/?feed=rss2&author='.$authorId));

        $this->assertContains("user:{$authorId}", $tags);
        $this->assertContains('archive:post', $tags);
    }

    public function test_post_comment_feed_is_tagged_with_its_post(): void
    {
        $postId = self::factory()->post->create(['post_status' => 'publish']);

        $this->assertContains("post:{$postId}", $this->feedTags(home_url('/?feed=rss2&p='.$postId)));
    }

    public function test_site_comments_feed_is_tagged_with_any_comment(): void
    {
        $tags = $this->feedTags(home_url('/?feed=rss2&withcomments=1'));

        $this->assertContains('comment:any', $tags);
        $this->assertNotContains('archive:post', $tags);
    }

    /** WP never 404s a feed, so these keep their singular/term flags with no queried object. */
    public function test_feeds_of_missing_objects_are_left_untagged(): void
    {
        $draftId = self::factory()->post->create(['post_status' => 'draft']);

        foreach ([
            '/?feed=rss2&p=999999',
            '/?feed=rss2&p='.$draftId,
            '/?feed=rss2&pagename=no-such-page',
            '/?feed=rss2&category_name=no-such-term',
            '/?feed=rss2&tag=no-such-term',
        ] as $path) {
            $this->assertSame([], $this->feedTags(home_url($path)), $path);
        }
    }

    public function test_a_page_gets_no_feed_tags(): void
    {
        $this->assertSame([], $this->feedTags(home_url('/')));
    }

    public function test_comment_changes_clear_the_site_comments_feed(): void
    {
        $postId = self::factory()->post->create(['post_status' => 'publish']);
        $commentId = self::factory()->comment->create(['comment_post_ID' => $postId, 'comment_approved' => '0']);
        $feed = new Feed($this->cacheTags);
        $feed->bind();

        foreach ([
            'approve' => fn () => wp_set_comment_status($commentId, 'approve'),
            'edit' => fn () => wp_update_comment(['comment_ID' => $commentId, 'comment_content' => 'Edited']),
            'delete' => fn () => wp_delete_comment($commentId, true),
        ] as $event => $change) {
            $this->resetCacheTags();
            $change();
            $this->assertContains('comment:any', $this->queuedPurgeTags(), $event);
        }
    }

    public function test_an_unapproved_new_comment_leaves_the_comments_feed(): void
    {
        $this->resetCacheTags();

        (new Feed($this->cacheTags))->onCommentPost(1, 0);

        $this->assertNotContains('comment:any', $this->queuedPurgeTags());
    }
}
