<?php

use Genero\Sage\CacheTags\Actions\Core;
use Genero\Sage\CacheTags\Tests\RestTestCase;

/**
 * Pages are tagged with the template and template parts they render, keyed by
 * theme + slug, and a save of one purges only those pages (Core action).
 *
 * @covers \Genero\Sage\CacheTags\Actions\Core
 * @covers \Genero\Sage\CacheTags\Tags\CoreTags::blockTemplate
 */
class TestBlockTemplates extends RestTestCase
{
    private function key(string $slug, string $type = 'template-part'): string
    {
        return "{$type}:".get_stylesheet()."//{$slug}";
    }

    private function templatePart(string $slug, string $status = 'publish'): int
    {
        $postId = self::factory()->post->create([
            'post_type' => 'wp_template_part',
            'post_status' => $status,
            'post_name' => $slug,
            'post_content' => 'Footer',
        ]);
        wp_set_object_terms($postId, get_stylesheet(), 'wp_theme');
        $this->resetCacheTags();

        return $postId;
    }

    public function test_the_template_part_block_tags_its_key(): void
    {
        $this->resetCacheTags();

        do_blocks('<!-- wp:template-part {"slug":"footer"} /-->');

        $this->assertContains($this->key('footer'), $this->cacheTags->get());
    }

    // Covers the database path too: core queries the post itself, without
    // get_block_template().
    public function test_a_customised_template_part_block_tags_the_same_key(): void
    {
        $this->templatePart('footer');

        do_blocks('<!-- wp:template-part {"slug":"footer"} /-->');

        $this->assertContains($this->key('footer'), $this->cacheTags->get());
    }

    public function test_a_template_part_of_another_theme_is_not_tagged(): void
    {
        $this->resetCacheTags();

        do_blocks('<!-- wp:template-part {"slug":"footer","theme":"not-the-active-theme"} /-->');

        $this->assertNotContains('template-part:not-the-active-theme//footer', $this->cacheTags->get());
    }

    public function test_block_template_part_in_php_tags_its_key(): void
    {
        $this->resetCacheTags();

        ob_start();
        block_template_part('header');
        ob_end_clean();

        $this->assertContains($this->key('header'), $this->cacheTags->get());
    }

    public function test_the_resolved_block_template_is_tagged(): void
    {
        global $_wp_current_template_id;
        $saved = $_wp_current_template_id;
        $_wp_current_template_id = get_stylesheet().'//single';
        $this->resetCacheTags();

        try {
            $result = (new Core($this->cacheTags))->addBlockTemplateCacheTags('/path/to/template-canvas.php');
        } finally {
            $_wp_current_template_id = $saved;
        }

        $this->assertSame('/path/to/template-canvas.php', $result);
        $this->assertContains($this->key('single', 'template'), $this->cacheTags->get());
    }

    // First customisation of a part the pages render from the theme file.
    public function test_customising_a_template_part_clears_its_key(): void
    {
        $this->resetCacheTags();

        $this->templatePartWithoutReset('footer');

        $this->assertContains($this->key('footer'), $this->queuedPurgeTags());
    }

    public function test_updating_or_trashing_a_template_part_clears_its_key(): void
    {
        $postId = $this->templatePart('footer');

        wp_update_post(['ID' => $postId, 'post_content' => 'New footer']);
        $this->assertContains($this->key('footer'), $this->queuedPurgeTags(), 'update');

        $this->resetCacheTags();
        wp_trash_post($postId);
        $this->assertContains($this->key('footer'), $this->queuedPurgeTags(), 'trash');
    }

    // "Clear customizations" force-deletes the post; pages fall back to the
    // theme file under the same key.
    public function test_resetting_a_template_part_to_the_theme_file_clears_its_key(): void
    {
        $postId = $this->templatePart('footer');

        wp_delete_post($postId, true);

        $this->assertContains($this->key('footer'), $this->queuedPurgeTags());
    }

    public function test_saving_a_template_clears_its_key(): void
    {
        $postId = self::factory()->post->create(['post_type' => 'wp_template', 'post_status' => 'publish', 'post_name' => 'single']);
        wp_set_object_terms($postId, get_stylesheet(), 'wp_theme');
        $this->resetCacheTags();

        wp_update_post(['ID' => $postId, 'post_content' => 'Edited']);

        $this->assertContains($this->key('single', 'template'), $this->queuedPurgeTags());
    }

    public function test_draft_template_parts_clear_nothing_and_others_keep_their_keys(): void
    {
        $draft = $this->templatePart('footer', 'draft');
        $this->templatePart('header');

        wp_update_post(['ID' => $draft, 'post_content' => 'Still a draft']);

        $tags = $this->queuedPurgeTags();
        $this->assertNotContains($this->key('footer'), $tags);
        $this->assertNotContains($this->key('header'), $tags, 'only the saved part');
    }

    private function templatePartWithoutReset(string $slug): int
    {
        $postId = wp_insert_post([
            'post_type' => 'wp_template_part',
            'post_status' => 'publish',
            'post_name' => $slug,
            'post_title' => ucfirst($slug),
        ]);

        return $postId;
    }
}
