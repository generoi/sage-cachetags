<?php

use Genero\Sage\CacheTags\Actions\BaseTag;
use Genero\Sage\CacheTags\Tests\RestTestCase;

/**
 * Global styles render into every page, so saving them clears the base tag;
 * templates and template parts purge only the pages rendering them (see
 * TestBlockTemplates).
 *
 * @covers \Genero\Sage\CacheTags\Actions\BaseTag
 */
class TestBaseTag extends RestTestCase
{
    public function set_up(): void
    {
        parent::set_up();

        // The suite bootstraps with the base tag disabled; bind it here alone.
        (new BaseTag($this->cacheTags, 'page'))->bind();
    }

    private function sitePost(string $postType, string $slug, string $status = 'publish', string $content = '{"version": 3, "isGlobalStylesUserThemeJSON": true, "styles": {"color": {"text": "red"}}}'): int
    {
        $postId = self::factory()->post->create([
            'post_type' => $postType,
            'post_status' => $status,
            'post_name' => $slug,
            'post_content' => $content,
        ]);
        wp_set_object_terms($postId, get_stylesheet(), 'wp_theme');
        $this->resetCacheTags();

        return $postId;
    }

    public function test_saving_global_styles_clears_the_base_tag(): void
    {
        $postId = $this->sitePost('wp_global_styles', 'wp-global-styles-theme');

        wp_update_post(['ID' => $postId, 'post_content' => '{"version": 3, "isGlobalStylesUserThemeJSON": true, "styles": {"color": {"text": "blue"}}}']);

        $this->assertContains('page', $this->queuedPurgeTags());
    }

    public function test_trashing_or_deleting_global_styles_clears_the_base_tag(): void
    {
        $postId = $this->sitePost('wp_global_styles', 'wp-global-styles-theme');

        wp_trash_post($postId);
        $this->assertContains('page', $this->queuedPurgeTags(), 'trash');

        $postId = $this->sitePost('wp_global_styles', 'wp-global-styles-other');
        wp_delete_post($postId, true);
        $this->assertContains('page', $this->queuedPurgeTags(), 'delete');
    }

    // Opening the block editor inserts this stub when the theme has no user
    // styles; it must not purge the whole site.
    public function test_the_empty_global_styles_stub_core_creates_clears_nothing(): void
    {
        $this->resetCacheTags();

        wp_insert_post([
            'post_type' => 'wp_global_styles',
            'post_status' => 'publish',
            'post_name' => 'wp-global-styles-stub',
            'post_title' => 'Custom Styles',
            'post_content' => '{"version": 3, "isGlobalStylesUserThemeJSON": true }',
        ]);

        $this->assertNotContains('page', $this->queuedPurgeTags());
    }

    public function test_a_first_save_of_real_global_styles_clears_the_base_tag(): void
    {
        $this->resetCacheTags();

        self::factory()->post->create([
            'post_type' => 'wp_global_styles',
            'post_status' => 'publish',
            'post_content' => '{"version": 3, "isGlobalStylesUserThemeJSON": true, "styles": {"color": {"text": "red"}}}',
        ]);

        $this->assertContains('page', $this->queuedPurgeTags());
    }

    // Template parts and templates purge by their own key instead.
    public function test_saving_a_template_part_does_not_clear_the_base_tag(): void
    {
        $postId = $this->sitePost('wp_template_part', 'footer', 'publish', 'Footer');

        wp_update_post(['ID' => $postId, 'post_content' => 'New footer']);
        wp_delete_post($postId, true);

        $this->assertNotContains('page', $this->queuedPurgeTags());
    }

    // A template no theme file backs changes which template pages resolve to,
    // and those pages carry another template's key.
    public function test_adding_or_removing_a_template_without_a_theme_file_clears_the_base_tag(): void
    {
        $this->resetCacheTags();

        $postId = wp_insert_post([
            'post_type' => 'wp_template',
            'post_status' => 'publish',
            'post_name' => 'single-cachetags-custom',
            'post_title' => 'Custom single',
            'tax_input' => ['wp_theme' => [get_stylesheet()]],
        ]);
        $this->assertContains('page', $this->queuedPurgeTags(), 'added');

        $this->resetCacheTags();
        wp_update_post(['ID' => $postId, 'post_content' => 'Edited']);
        $this->assertNotContains('page', $this->queuedPurgeTags(), 'an edit only purges its key');

        wp_delete_post($postId, true);
        $this->assertContains('page', $this->queuedPurgeTags(), 'removed');
    }

    public function test_other_post_types_do_not_clear_the_base_tag(): void
    {
        $this->resetCacheTags();

        self::factory()->post->create(['post_status' => 'publish']);

        $this->assertNotContains('page', $this->queuedPurgeTags());
    }
}
