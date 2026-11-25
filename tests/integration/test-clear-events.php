<?php

use Genero\Sage\CacheTags\Actions\Core;
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

    public function test_taking_a_post_down_purges_hard(): void
    {
        foreach (['draft', 'private', 'trash'] as $status) {
            $postId = self::factory()->post->create(['post_status' => 'publish']);
            $this->resetCacheTags();

            wp_update_post(['ID' => $postId, 'post_status' => $status]);

            $this->assertTrue($this->hardPurgeRequired(), $status);
            $this->assertContains("post:{$postId}", $this->queuedPurgeTags(), $status);
        }
    }

    public function test_giving_a_published_post_a_password_purges_hard(): void
    {
        $postId = self::factory()->post->create(['post_status' => 'publish']);
        $this->resetCacheTags();

        wp_update_post(['ID' => $postId, 'post_password' => 'secret']);

        $this->assertTrue($this->hardPurgeRequired());
    }

    public function test_an_ordinary_edit_or_publish_purges_soft(): void
    {
        $postId = self::factory()->post->create(['post_status' => 'draft']);
        $this->resetCacheTags();

        wp_update_post(['ID' => $postId, 'post_status' => 'publish']);
        $this->assertFalse($this->hardPurgeRequired(), 'publishing');

        wp_update_post(['ID' => $postId, 'post_title' => 'Edited']);
        $this->assertFalse($this->hardPurgeRequired(), 'editing');
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

    // WP's daily wp_scheduled_auto_draft_delete and wp_scheduled_delete remove
    // posts no listing ever showed; they must not purge every listing page.
    public function test_deleting_an_auto_draft_or_trashed_post_only_clears_the_post(): void
    {
        $autoDraft = self::factory()->post->create(['post_status' => 'auto-draft']);
        $trashed = self::factory()->post->create(['post_status' => 'publish']);
        wp_trash_post($trashed);

        foreach (['auto-draft' => $autoDraft, 'trash' => $trashed] as $status => $postId) {
            $this->resetCacheTags();

            wp_delete_post($postId, true);

            $tags = $this->queuedPurgeTags();
            $this->assertContains("post:{$postId}", $tags, $status);
            $this->assertNotContains('archive:post', $tags, $status);
            $this->assertNotContains('archive:post:any', $tags, $status);
            $this->assertNotContains('taxonomy:category', $tags, $status);
        }
    }

    public function test_creating_or_saving_a_draft_does_not_clear_listings(): void
    {
        $this->resetCacheTags();
        $postId = self::factory()->post->create(['post_status' => 'auto-draft']);
        $this->assertNotContains('archive:post:any', $this->queuedPurgeTags(), 'auto-draft');

        $this->resetCacheTags();
        wp_update_post(['ID' => $postId, 'post_status' => 'draft', 'post_title' => 'Draft']);
        wp_update_post(['ID' => $postId, 'post_title' => 'Edited draft']);

        $tags = $this->queuedPurgeTags();
        $this->assertContains("post:{$postId}", $tags);
        $this->assertNotContains('archive:post:any', $tags);
        $this->assertNotContains('archive:post', $tags);
    }

    public function test_editing_or_unpublishing_a_published_post_still_clears_listings(): void
    {
        $postId = self::factory()->post->create(['post_status' => 'publish']);

        $this->resetCacheTags();
        wp_update_post(['ID' => $postId, 'post_title' => 'Edited']);
        $this->assertContains('archive:post:any', $this->queuedPurgeTags(), 'edit');

        $this->resetCacheTags();
        wp_update_post(['ID' => $postId, 'post_status' => 'draft']);
        $tags = $this->queuedPurgeTags();
        $this->assertContains('archive:post:any', $tags, 'unpublish');
        $this->assertContains('archive:post', $tags, 'unpublish');
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

    public function test_updating_a_tagged_option_clears_it(): void
    {
        $this->resetCacheTags();

        update_option('blogname', 'A different site name');

        $this->assertContains('option:blogname', $this->queuedPurgeTags());
    }

    public function test_updating_an_untracked_option_clears_nothing(): void
    {
        update_option('cachetags_untracked', 'initial');
        $this->resetCacheTags();

        update_option('cachetags_untracked', 'changed');

        $this->assertNotContains('option:cachetags_untracked', $this->queuedPurgeTags());
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

    /**
     * Core's "Add to Menu" button saves the submitted items through
     * `wp_save_nav_menu_items(0, ...)`, so `wp_update_nav_menu_item` fires with a
     * menu id of 0 for an item that is in no menu yet. Nothing renders it yet,
     * so that has to be a no-op.
     */
    public function test_adding_a_menu_item_with_no_menu_yet_is_a_noop(): void
    {
        $this->resetCacheTags();

        $itemId = wp_update_nav_menu_item(0, 0, [
            'menu-item-title' => 'Home',
            'menu-item-url' => home_url('/'),
            'menu-item-status' => 'publish',
        ]);

        $this->assertIsInt($itemId);
        $this->assertNotWPError($itemId);
        $this->assertSame([], $this->queuedPurgeTags());
    }

    /**
     * The guard is on the menu id being absent, not on menu ids in general — a
     * real menu still purges through the same hook.
     */
    public function test_adding_a_menu_item_to_a_real_menu_still_clears_it(): void
    {
        $menuId = wp_create_nav_menu('Guarded');
        $this->resetCacheTags();

        wp_update_nav_menu_item($menuId, 0, [
            'menu-item-title' => 'Home',
            'menu-item-url' => home_url('/'),
            'menu-item-status' => 'publish',
        ]);

        $this->assertContains("menu:{$menuId}", $this->queuedPurgeTags());
    }

    public function test_publishing_a_post_clears_it_its_archive_and_taxonomies(): void
    {
        $postId = self::factory()->post->create(['post_status' => 'draft']);
        $this->resetCacheTags();

        wp_update_post(['ID' => $postId, 'post_status' => 'publish']);

        $tags = $this->queuedPurgeTags();
        $this->assertContains("post:{$postId}", $tags);
        $this->assertContains('archive:post', $tags);
        $this->assertContains('archive:post:any', $tags);
        $this->assertContains('taxonomy:category', $tags);
    }

    public function test_a_new_approved_comment_clears_its_post(): void
    {
        $postId = self::factory()->post->create(['post_status' => 'publish']);
        $commentId = self::factory()->comment->create([
            'comment_post_ID' => $postId,
            'comment_approved' => '1',
        ]);
        $this->resetCacheTags();

        // comment_post fires on a new comment with its approval status (1 = approved).
        do_action('comment_post', $commentId, 1, ['comment_post_ID' => $postId]);

        $this->assertContains("post:{$postId}", $this->queuedPurgeTags());
    }

    public function test_approving_a_comment_clears_it_and_its_post(): void
    {
        $postId = self::factory()->post->create(['post_status' => 'publish']);
        $commentId = self::factory()->comment->create([
            'comment_post_ID' => $postId,
            'comment_approved' => '0',
        ]);
        $this->resetCacheTags();

        wp_set_comment_status($commentId, 'approve');

        $tags = $this->queuedPurgeTags();
        $this->assertContains("comment:{$commentId}", $tags);
        $this->assertContains("post:{$postId}", $tags);
    }

    public function test_editing_an_attachment_clears_it(): void
    {
        $attachmentId = self::factory()->post->create(['post_type' => 'attachment']);
        $this->resetCacheTags();

        do_action('edit_attachment', $attachmentId);

        $this->assertContains("post:{$attachmentId}", $this->queuedPurgeTags());
    }

    public function test_setting_terms_on_a_post_clears_their_pages_and_the_post(): void
    {
        $postId = self::factory()->post->create(['post_status' => 'publish']);
        $termId = self::factory()->category->create();
        $this->resetCacheTags();

        wp_set_object_terms($postId, [$termId], 'category');

        $tags = $this->queuedPurgeTags();
        $this->assertContains("term:{$termId}:full", $tags);
        $this->assertContains("post:{$postId}", $tags);
    }

    public function test_creating_a_user_clears_the_user_and_role(): void
    {
        $this->resetCacheTags();

        $userId = self::factory()->user->create(['role' => 'author']);

        $tags = $this->queuedPurgeTags();
        $this->assertContains("user:{$userId}", $tags);
        $this->assertContains('role:author', $tags);
    }

    public function test_updating_a_user_clears_the_user_and_role(): void
    {
        $userId = self::factory()->user->create(['role' => 'author']);
        $this->resetCacheTags();

        wp_update_user(['ID' => $userId, 'display_name' => 'Renamed']);

        $tags = $this->queuedPurgeTags();
        $this->assertContains("user:{$userId}", $tags);
        $this->assertContains('role:author', $tags);
    }

    public function test_deleting_a_user_clears_the_user_and_role(): void
    {
        require_once ABSPATH.'wp-admin/includes/user.php';
        $userId = self::factory()->user->create(['role' => 'author']);
        $this->resetCacheTags();

        wp_delete_user($userId);

        $tags = $this->queuedPurgeTags();
        $this->assertContains("user:{$userId}", $tags);
        $this->assertContains('role:author', $tags);
    }

    public function test_creating_a_term_clears_its_tags_and_the_taxonomy_listing(): void
    {
        $this->resetCacheTags();

        $termId = self::factory()->category->create();

        $tags = $this->queuedPurgeTags();
        $this->assertContains("term:{$termId}", $tags);
        $this->assertContains('taxonomy:category:any', $tags);
        $this->assertContains('taxonomy:category', $tags, 'a new term clears the listing');
    }

    public function test_editing_a_term_clears_its_tags_but_not_the_listing(): void
    {
        $termId = self::factory()->category->create();
        $this->resetCacheTags();

        wp_update_term($termId, 'category', ['name' => 'Renamed']);

        $tags = $this->queuedPurgeTags();
        $this->assertContains("term:{$termId}", $tags);
        $this->assertContains('taxonomy:category:any', $tags);
        $this->assertNotContains('taxonomy:category', $tags, 'an edit is not a new term');
    }

    // H5: reusable blocks / block-theme menus are tagged post:{id} on the pages
    // that embed them, but aren't a cacheable post type — they must still purge.
    public function test_saving_a_reusable_block_purges_its_reference(): void
    {
        $this->resetCacheTags();

        $blockId = self::factory()->post->create(['post_type' => 'wp_block', 'post_status' => 'publish']);

        $this->assertContains("post:{$blockId}", $this->queuedPurgeTags());
    }

    // onTermSet fix: reassigning a post to a new term must purge the OLD term's
    // archive too (and resolve via term_taxonomy_id, not the raw slug/id input).
    public function test_reassigning_a_post_purges_the_old_terms_archive(): void
    {
        $postId = self::factory()->post->create(['post_status' => 'publish']);
        $oldTerm = self::factory()->category->create();
        $newTerm = self::factory()->category->create();
        wp_set_object_terms($postId, [$oldTerm], 'category');
        $this->resetCacheTags();

        wp_set_object_terms($postId, [$newTerm], 'category');

        $tags = $this->queuedPurgeTags();
        $this->assertContains("term:{$newTerm}:full", $tags, 'new term archive');
        $this->assertContains("term:{$oldTerm}:full", $tags, 'old term archive purged on reassignment');
    }

    // An importer re-setting the same terms on every listing each run must not
    // purge the listing and every one of its term archives each time.
    public function test_setting_the_same_terms_again_purges_nothing(): void
    {
        $postId = self::factory()->post->create(['post_status' => 'publish']);
        $first = self::factory()->category->create();
        $second = self::factory()->category->create();
        wp_set_object_terms($postId, [$first, $second], 'category');
        $this->resetCacheTags();

        // Same set, different order.
        wp_set_object_terms($postId, [$second, $first], 'category');
        wp_set_post_terms($postId, [$first], 'category', true);

        $this->assertSame([], $this->queuedPurgeTags());
    }

    public function test_setting_terms_purges_only_the_terms_that_changed(): void
    {
        $postId = self::factory()->post->create(['post_status' => 'publish']);
        $kept = self::factory()->category->create();
        $removed = self::factory()->category->create();
        $added = self::factory()->category->create();
        wp_set_object_terms($postId, [$kept, $removed], 'category');
        $this->resetCacheTags();

        wp_set_object_terms($postId, [$kept, $added], 'category');

        $tags = $this->queuedPurgeTags();
        $this->assertContains("post:{$postId}", $tags);
        $this->assertContains("term:{$added}:full", $tags, 'added term archive');
        $this->assertContains("term:{$removed}:full", $tags, 'removed term archive');
        $this->assertNotContains("term:{$kept}:full", $tags, 'unchanged membership');
    }

    // A long import sets terms on thousands of objects; nothing may be left
    // behind per object once its set_object_terms has run.
    public function test_term_set_leaves_no_bookkeeping_behind(): void
    {
        $core = new Core($this->cacheTags);
        $termId = self::factory()->category->create();
        $taxonomyId = get_term($termId)->term_taxonomy_id;

        $core->onTermRelationshipAdded(5, $taxonomyId, 'category');
        $core->onTermRelationshipAdded(5, 99, 'post_tag');
        $core->onTermSet(5, [$termId], [$taxonomyId], 'category', false, []);
        $core->onTermSet(5, [], [99], 'post_tag', false, []);

        $prop = new ReflectionProperty($core, 'addedTermRelationships');
        $prop->setAccessible(true);
        $this->assertSame([], $prop->getValue($core));
    }

    public function test_appending_a_new_term_purges_only_that_term(): void
    {
        $postId = self::factory()->post->create(['post_status' => 'publish']);
        $existing = self::factory()->category->create();
        $added = self::factory()->category->create();
        wp_set_object_terms($postId, [$existing], 'category');
        $this->resetCacheTags();

        wp_set_object_terms($postId, [$added], 'category', true);

        $tags = $this->queuedPurgeTags();
        $this->assertContains("post:{$postId}", $tags);
        $this->assertContains("term:{$added}:full", $tags);
        $this->assertNotContains("term:{$existing}:full", $tags, 'appending removes nothing');
    }
}
