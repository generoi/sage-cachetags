<?php

use Genero\Sage\CacheTags\Tests\RestTestCase;

/**
 * Menu items render their label and URL live from the post or term they link
 * to, so a change to those fields clears the menus linking to it (Core action).
 *
 * @covers \Genero\Sage\CacheTags\Actions\Core
 */
class TestMenuLinks extends RestTestCase
{
    private int $menuId;

    public function set_up(): void
    {
        parent::set_up();
        $this->menuId = wp_create_nav_menu('Linked '.wp_generate_password(6, false));
    }

    private function linkPost(int $postId): void
    {
        wp_update_nav_menu_item($this->menuId, 0, [
            'menu-item-type' => 'post_type',
            'menu-item-object' => get_post_type($postId),
            'menu-item-object-id' => $postId,
            'menu-item-status' => 'publish',
        ]);
        $this->resetCacheTags();
    }

    private function linkTerm(int $termId, string $taxonomy = 'category'): void
    {
        wp_update_nav_menu_item($this->menuId, 0, [
            'menu-item-type' => 'taxonomy',
            'menu-item-object' => $taxonomy,
            'menu-item-object-id' => $termId,
            'menu-item-status' => 'publish',
        ]);
        $this->resetCacheTags();
    }

    public function test_renaming_a_linked_post_clears_the_menu(): void
    {
        $postId = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish']);
        $this->linkPost($postId);

        wp_update_post(['ID' => $postId, 'post_title' => 'Renamed']);

        $this->assertContains("menu:{$this->menuId}", $this->queuedPurgeTags());
    }

    public function test_changing_a_linked_posts_slug_or_parent_clears_the_menu(): void
    {
        $parentId = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish']);
        $postId = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish']);
        $this->linkPost($postId);

        wp_update_post(['ID' => $postId, 'post_name' => 'new-slug']);
        $this->assertContains("menu:{$this->menuId}", $this->queuedPurgeTags(), 'slug');

        $this->resetCacheTags();
        wp_update_post(['ID' => $postId, 'post_parent' => $parentId]);
        $this->assertContains("menu:{$this->menuId}", $this->queuedPurgeTags(), 'parent');
    }

    public function test_trashing_or_deleting_a_linked_post_clears_the_menu(): void
    {
        $postId = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish']);
        $this->linkPost($postId);

        wp_trash_post($postId);
        $this->assertContains("menu:{$this->menuId}", $this->queuedPurgeTags(), 'trash');

        $this->resetCacheTags();
        wp_delete_post($postId, true);
        $this->assertContains("menu:{$this->menuId}", $this->queuedPurgeTags(), 'delete');
    }

    // An ordinary content save must not purge every page showing the menu.
    public function test_editing_a_linked_posts_content_leaves_the_menu(): void
    {
        $postId = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish']);
        $this->linkPost($postId);

        wp_update_post(['ID' => $postId, 'post_content' => 'New body']);

        $tags = $this->queuedPurgeTags();
        $this->assertContains("post:{$postId}", $tags);
        $this->assertNotContains("menu:{$this->menuId}", $tags);
    }

    public function test_renaming_an_unlinked_post_clears_no_menu(): void
    {
        $postId = self::factory()->post->create(['post_status' => 'publish']);
        $this->resetCacheTags();

        wp_update_post(['ID' => $postId, 'post_title' => 'Renamed']);

        $this->assertNotContains("menu:{$this->menuId}", $this->queuedPurgeTags());
    }

    public function test_renaming_a_linked_term_clears_the_menu(): void
    {
        $termId = self::factory()->category->create();
        $this->linkTerm($termId);

        wp_update_term($termId, 'category', ['name' => 'Renamed']);
        $this->assertContains("menu:{$this->menuId}", $this->queuedPurgeTags(), 'name');

        $this->resetCacheTags();
        wp_update_term($termId, 'category', ['slug' => 'renamed-slug']);
        $this->assertContains("menu:{$this->menuId}", $this->queuedPurgeTags(), 'slug');
    }

    public function test_editing_a_linked_terms_description_leaves_the_menu(): void
    {
        $termId = self::factory()->category->create();
        $this->linkTerm($termId);

        wp_update_term($termId, 'category', ['description' => 'Only the description']);

        $tags = $this->queuedPurgeTags();
        $this->assertContains("term:{$termId}", $tags);
        $this->assertNotContains("menu:{$this->menuId}", $tags);
    }

    public function test_deleting_a_linked_term_clears_the_menu(): void
    {
        $termId = self::factory()->category->create();
        $this->linkTerm($termId);

        wp_delete_term($termId, 'category');

        $this->assertContains("menu:{$this->menuId}", $this->queuedPurgeTags());
    }

    public function test_reassigning_a_menu_location_clears_the_previous_menu(): void
    {
        register_nav_menu('primary', 'Primary');
        $otherId = wp_create_nav_menu('Other '.wp_generate_password(6, false));
        set_theme_mod('nav_menu_locations', ['primary' => $this->menuId]);
        $this->resetCacheTags();

        set_theme_mod('nav_menu_locations', ['primary' => $otherId]);

        $this->assertContains("menu:{$this->menuId}", $this->queuedPurgeTags());
    }

    public function test_unrelated_theme_mod_changes_clear_no_menu(): void
    {
        register_nav_menu('primary', 'Primary');
        set_theme_mod('nav_menu_locations', ['primary' => $this->menuId]);
        $this->resetCacheTags();

        set_theme_mod('header_textcolor', 'ff0000');

        $this->assertNotContains("menu:{$this->menuId}", $this->queuedPurgeTags());
    }
}
