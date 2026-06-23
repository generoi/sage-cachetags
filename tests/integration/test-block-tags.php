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
}
