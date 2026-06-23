<?php

use Genero\Sage\CacheTags\Tests\RestTestCase;

/**
 * Data-change events queue the right tags for clearing (Core action).
 *
 * @covers \Genero\Sage\CacheTags\Actions\Core
 */
class TestClearEvents extends RestTestCase
{
    public function test_deleting_a_term_clears_its_tags(): void
    {
        $termId = self::factory()->category->create();
        $this->resetCacheTags();

        wp_delete_term($termId, 'category');

        $tags = $this->queuedPurgeTags();
        $this->assertContains("term:{$termId}", $tags);
        $this->assertContains("term:{$termId}:full", $tags);
        $this->assertContains('taxonomy:category', $tags);
        $this->assertContains('taxonomy:category:any', $tags);
    }

    public function test_editing_a_comment_clears_it_and_its_post(): void
    {
        $postId = self::factory()->post->create(['post_status' => 'publish']);
        $commentId = self::factory()->comment->create(['comment_post_ID' => $postId]);
        $this->resetCacheTags();

        wp_update_comment(['comment_ID' => $commentId, 'comment_content' => 'Edited']);

        $tags = $this->queuedPurgeTags();
        $this->assertContains("comment:{$commentId}", $tags);
        $this->assertContains("post:{$postId}", $tags);
    }

    public function test_deleting_a_comment_clears_it_and_its_post(): void
    {
        $postId = self::factory()->post->create(['post_status' => 'publish']);
        $commentId = self::factory()->comment->create(['comment_post_ID' => $postId]);
        $this->resetCacheTags();

        wp_delete_comment($commentId, true);

        $tags = $this->queuedPurgeTags();
        $this->assertContains("comment:{$commentId}", $tags);
        $this->assertContains("post:{$postId}", $tags);
    }

    public function test_permanently_deleting_a_post_clears_it_and_its_listings(): void
    {
        $postId = self::factory()->post->create(['post_status' => 'publish']);
        $this->resetCacheTags();

        wp_delete_post($postId, true);

        $tags = $this->queuedPurgeTags();
        $this->assertContains("post:{$postId}", $tags);
        $this->assertContains('archive:post', $tags);
        $this->assertContains('archive:post:any', $tags);
    }

    public function test_changing_a_user_role_clears_user_and_both_roles(): void
    {
        $userId = self::factory()->user->create(['role' => 'editor']);
        $this->resetCacheTags();

        (new WP_User($userId))->set_role('author');

        $tags = $this->queuedPurgeTags();
        $this->assertContains("user:{$userId}", $tags);
        $this->assertContains('role:author', $tags, 'new role cleared');
        $this->assertContains('role:editor', $tags, 'old role cleared');
    }

    public function test_adding_and_deleting_post_meta_clears_the_post(): void
    {
        $postId = self::factory()->post->create(['post_status' => 'publish']);

        $this->resetCacheTags();
        add_post_meta($postId, 'subtitle', 'Hello');
        $this->assertContains("post:{$postId}", $this->queuedPurgeTags(), 'added_post_meta');

        $this->resetCacheTags();
        delete_post_meta($postId, 'subtitle');
        $this->assertContains("post:{$postId}", $this->queuedPurgeTags(), 'deleted_post_meta');
    }

    public function test_updating_term_meta_clears_the_term(): void
    {
        $termId = self::factory()->category->create();
        $this->resetCacheTags();

        update_term_meta($termId, 'colour', 'red');

        $tags = $this->queuedPurgeTags();
        $this->assertContains("term:{$termId}", $tags);
        $this->assertContains("term:{$termId}:full", $tags);
    }

    public function test_updating_a_nav_menu_item_clears_the_menu(): void
    {
        $menuId = wp_create_nav_menu('Primary');
        $this->resetCacheTags();

        wp_update_nav_menu_item($menuId, 0, [
            'menu-item-title' => 'Home',
            'menu-item-url' => home_url('/'),
            'menu-item-status' => 'publish',
        ]);

        $this->assertContains("menu:{$menuId}", $this->queuedPurgeTags());
    }
}
