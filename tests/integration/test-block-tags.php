<?php

use Genero\Sage\CacheTags\Actions\Core;
use Genero\Sage\CacheTags\Tests\RestTestCase;

/**
 * Front-end block tagging (Core::addBlockCacheTags / addNavMenuCacheTags).
 *
 * @covers \Genero\Sage\CacheTags\Actions\Core
 */
class TestBlockTags extends RestTestCase
{
    /** Tags accumulated while rendering block markup. */
    private function renderedTags(string $markup): array
    {
        $this->resetCacheTags();
        do_blocks($markup);

        return $this->cacheTags->get();
    }

    public function test_archives_and_calendar_blocks_tag_the_post_archive(): void
    {
        $this->assertContains('archive:post', $this->renderedTags('<!-- wp:archives /-->'));
        $this->assertContains('archive:post', $this->renderedTags('<!-- wp:calendar /-->'));
    }

    public function test_avatar_block_tags_its_user(): void
    {
        $userId = self::factory()->user->create();

        $this->assertContains("user:{$userId}", $this->renderedTags('<!-- wp:avatar {"userId":'.$userId.'} /-->'));
    }

    public function test_site_identity_blocks_tag_their_options(): void
    {
        $this->assertContains('option:blogname', $this->renderedTags('<!-- wp:site-title /-->'));
        $this->assertContains('option:blogdescription', $this->renderedTags('<!-- wp:site-tagline /-->'));
        $this->assertContains('option:site_logo', $this->renderedTags('<!-- wp:site-logo /-->'));
    }

    public function test_comment_template_blocks_tag_the_comment_via_context(): void
    {
        $postId = self::factory()->post->create(['post_status' => 'publish']);
        $commentId = self::factory()->comment->create(['comment_post_ID' => $postId]);

        $this->resetCacheTags();
        $block = new WP_Block(['blockName' => 'core/comment-content'], ['commentId' => $commentId]);
        (new Core($this->cacheTags))->addBlockCacheTags('', ['blockName' => 'core/comment-content'], $block);

        $this->assertContains("comment:{$commentId}", $this->cacheTags->get());
    }

    public function test_classic_nav_menu_render_tags_the_menu(): void
    {
        $menuId = wp_create_nav_menu('Primary');
        register_nav_menu('primary', 'Primary');
        set_theme_mod('nav_menu_locations', ['primary' => $menuId]);
        wp_update_nav_menu_item($menuId, 0, [
            'menu-item-title' => 'Home',
            'menu-item-url' => home_url('/'),
            'menu-item-status' => 'publish',
        ]);

        $this->resetCacheTags();
        wp_nav_menu(['theme_location' => 'primary', 'echo' => false]);

        $this->assertContains("menu:{$menuId}", $this->cacheTags->get());
    }

    // Without a theme location, wp_nav_menu() renders the first menu that has
    // items for a `menu` it can't resolve; that menu must be tagged.
    public function test_classic_nav_menu_render_of_an_unknown_menu_tags_the_menu_core_falls_back_to(): void
    {
        // Sorts first by name, which is the order wp_get_nav_menus() returns.
        $menuId = wp_create_nav_menu('000 cachetags fallback');
        wp_update_nav_menu_item($menuId, 0, [
            'menu-item-title' => 'Fallback item',
            'menu-item-url' => home_url('/fallback/'),
            'menu-item-status' => 'publish',
        ]);
        $this->resetCacheTags();

        $html = wp_nav_menu(['menu' => 'no-such-menu', 'echo' => false, 'fallback_cb' => false]);

        $this->assertStringContainsString('Fallback item', (string) $html, 'core rendered this menu');
        $this->assertContains("menu:{$menuId}", $this->cacheTags->get());
    }

    // A location still pointing at a deleted menu renders the fallback_cb, not
    // a menu; tagging it must not throw (a 500 on every page).
    public function test_classic_nav_menu_render_of_a_location_with_a_deleted_menu_does_not_throw(): void
    {
        register_nav_menu('primary', 'Primary');
        set_theme_mod('nav_menu_locations', ['primary' => 999999]);
        $this->resetCacheTags();

        wp_nav_menu(['theme_location' => 'primary', 'echo' => false, 'fallback_cb' => false]);

        $this->assertSame([], array_values(array_filter($this->cacheTags->get(), fn ($tag) => str_starts_with($tag, 'menu:'))));
    }
}
