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

    // Another theme's styles (a Site Editor or Customizer preview) change no page.
    public function test_saving_another_themes_styles_or_css_does_not_clear_the_base_tag(): void
    {
        $this->resetCacheTags();
        wp_update_custom_css_post('body { color: red; }', ['stylesheet' => 'not-the-active-theme']);
        $this->assertNotContains('page', $this->queuedPurgeTags(), 'Additional CSS');

        // wp_get_themes() also lists wp-phpunit's test-data themes, which
        // wp_get_theme() doesn't resolve on its own.
        $other = null;
        foreach (array_keys(wp_get_themes()) as $stylesheet) {
            if ($stylesheet !== get_stylesheet() && wp_get_theme($stylesheet)->exists()) {
                $other = $stylesheet;
                break;
            }
        }
        if ($other === null) {
            $this->markTestSkipped('Only one theme is installed.');
        }
        $postId = self::factory()->post->create(['post_type' => 'wp_global_styles', 'post_status' => 'draft']);
        wp_set_object_terms($postId, $other, 'wp_theme');
        $this->resetCacheTags();
        wp_update_post(['ID' => $postId, 'post_status' => 'publish', 'post_content' => '{"version": 3, "isGlobalStylesUserThemeJSON": true, "styles": {"color": {"text": "red"}}}']);
        $this->assertNotContains('page', $this->queuedPurgeTags(), 'global styles');
    }

    // A plugin's template already renders under its own key, so customising it
    // changes no template resolution.
    public function test_customising_a_plugin_template_does_not_clear_the_base_tag(): void
    {
        if (! function_exists('register_block_template')) {
            $this->markTestSkipped('register_block_template() needs WordPress 6.7.');
        }
        register_block_template('cachetags//single-cachetags-registered', ['title' => 'Registered', 'content' => '<!-- wp:paragraph --><p>Plugin</p><!-- /wp:paragraph -->']);
        $this->resetCacheTags();

        try {
            $this->sitePostWithoutReset('wp_template', 'single-cachetags-registered');
        } finally {
            unregister_block_template('cachetags//single-cachetags-registered');
        }

        $this->assertNotContains('page', $this->queuedPurgeTags());
    }

    // WooCommerce doesn't register its category, tag and brand templates:
    // those pages resolve to archive-product, so a first customisation (or a
    // per-term taxonomy-product_cat-shoes) changes resolution.
    public function test_customising_a_woocommerce_taxonomy_template_clears_the_base_tag(): void
    {
        if (! class_exists('WooCommerce')) {
            $this->markTestSkipped('WooCommerce is not installed in this environment.');
        }

        foreach (['taxonomy-product_cat', 'taxonomy-product_cat-shoes'] as $slug) {
            $this->resetCacheTags();

            $this->sitePostWithoutReset('wp_template', $slug);

            $this->assertContains('page', $this->queuedPurgeTags(), $slug);
        }
    }

    private function sitePostWithoutReset(string $postType, string $slug): int
    {
        return self::factory()->post->create(['post_type' => $postType, 'post_status' => 'publish', 'post_name' => $slug]);
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

    // Customizer → Additional CSS is inlined into every page.
    public function test_saving_additional_css_clears_the_base_tag(): void
    {
        $this->resetCacheTags();

        wp_update_custom_css_post('body { color: red; }');
        $this->assertContains('page', $this->queuedPurgeTags(), 'created');

        $this->resetCacheTags();
        wp_update_custom_css_post('body { color: blue; }');
        $this->assertContains('page', $this->queuedPurgeTags(), 'updated');
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

    // Trashing renames the post to {slug}__trashed first; a customised template
    // that has a theme file falls back to it, which changes no resolution.
    public function test_trashing_a_customised_template_with_a_theme_file_does_not_clear_the_base_tag(): void
    {
        if (! get_block_file_template(get_stylesheet().'//index', 'wp_template')) {
            $this->markTestSkipped('The active theme has no index.html block template.');
        }
        $postId = $this->sitePost('wp_template', 'index', 'publish', 'Customised index');

        wp_trash_post($postId);

        $this->assertNotContains('page', $this->queuedPurgeTags());
    }

    // Nothing resolves to a custom template until it is assigned to a post, and
    // saving that assignment purges the post.
    public function test_creating_a_custom_template_does_not_clear_the_base_tag(): void
    {
        $this->resetCacheTags();

        $postId = self::factory()->post->create([
            'post_type' => 'wp_template',
            'post_status' => 'publish',
            'post_name' => 'wp-custom-template-landing',
        ]);
        $this->assertNotContains('page', $this->queuedPurgeTags(), 'created');

        wp_delete_post($postId, true);
        $this->assertNotContains('page', $this->queuedPurgeTags(), 'deleted');
    }

    public function test_other_post_types_do_not_clear_the_base_tag(): void
    {
        $this->resetCacheTags();

        self::factory()->post->create(['post_status' => 'publish']);

        $this->assertNotContains('page', $this->queuedPurgeTags());
    }
}
