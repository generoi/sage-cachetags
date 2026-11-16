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

        // Core renders nothing for another theme's part, so no footer key —
        // not even the active theme's, which every render key is rewritten to.
        $this->assertSame([], array_values(array_filter(
            $this->cacheTags->get(),
            fn ($tag) => str_starts_with($tag, 'template-part:')
        )));
    }

    public function test_block_template_part_in_php_tags_its_key(): void
    {
        $this->resetCacheTags();

        ob_start();
        block_template_part('header');
        ob_end_clean();

        $this->assertContains($this->key('header'), $this->cacheTags->get());
    }

    // get_block_template() returns a customised (database) part before its
    // get_block_template filter runs, so a theme rendering parts in PHP lost
    // the tag after the first customisation.
    public function test_block_template_part_in_php_tags_a_customised_part(): void
    {
        $this->templatePart('header');

        ob_start();
        block_template_part('header');
        $html = ob_get_clean();

        $this->assertStringContainsString('Footer', $html, 'rendered from the database post');
        $this->assertContains($this->key('header'), $this->cacheTags->get());
    }

    // Resolved through core: the template hierarchy filter runs
    // locate_block_template(), then template_include tags the result.
    // A third party passing null must not TypeError the page.
    public function test_get_block_template_with_a_null_id_does_not_throw(): void
    {
        $this->resetCacheTags();

        $this->assertNull((new Core($this->cacheTags))->addTemplatePartCacheTags(null, null, 'wp_template_part'));
        $this->assertNull(apply_filters('pre_get_block_template', null, null, null));
    }

    public function test_the_resolved_block_template_is_tagged(): void
    {
        if (! wp_is_block_theme()) {
            $this->markTestSkipped('The active theme is not a block theme.');
        }
        global $_wp_current_template_id;
        $saved = $_wp_current_template_id;
        $postId = self::factory()->post->create(['post_status' => 'publish']);
        $this->go_to(get_permalink($postId));
        $this->resetCacheTags();

        try {
            $template = apply_filters('template_include', get_single_template());
            $resolved = $_wp_current_template_id;
        } finally {
            $_wp_current_template_id = $saved;
        }

        $this->assertNotEmpty($resolved, 'core resolved a block template');
        $slug = explode('//', $resolved, 2)[1];
        $this->assertContains($this->key($slug, 'template'), $this->cacheTags->get());
        $this->assertNotEmpty($template);
    }

    // WooCommerce's own templates resolve as woocommerce/woocommerce//{slug},
    // while their customisation is saved under the active theme.
    public function test_a_plugin_provided_template_is_keyed_by_the_active_theme(): void
    {
        global $_wp_current_template_id;
        $saved = $_wp_current_template_id;
        $_wp_current_template_id = 'woocommerce/woocommerce//single-product';
        $this->resetCacheTags();

        try {
            (new Core($this->cacheTags))->addBlockTemplateCacheTags('template-canvas.php');
        } finally {
            $_wp_current_template_id = $saved;
        }

        $this->assertContains($this->key('single-product', 'template'), $this->cacheTags->get());

        // And a customisation, saved under the active theme, clears that key.
        $postId = self::factory()->post->create(['post_type' => 'wp_template', 'post_status' => 'publish', 'post_name' => 'single-product']);
        wp_set_object_terms($postId, get_stylesheet(), 'wp_theme');
        $this->resetCacheTags();
        wp_update_post(['ID' => $postId, 'post_content' => 'Customised']);
        $this->assertContains($this->key('single-product', 'template'), $this->queuedPurgeTags());
    }

    // Older WooCommerce versions saved customisations of its own templates under
    // a `woocommerce/woocommerce` or `woocommerce` term; they render for the
    // active theme, so they purge its key.
    public function test_a_legacy_woocommerce_customisation_clears_the_active_themes_key(): void
    {
        foreach (['woocommerce/woocommerce', 'woocommerce'] as $term) {
            $postId = self::factory()->post->create(['post_type' => 'wp_template', 'post_status' => 'publish', 'post_name' => 'single-product']);
            wp_set_object_terms($postId, $term, 'wp_theme');
            $this->resetCacheTags();

            wp_update_post(['ID' => $postId, 'post_content' => 'Customised']);

            $tags = $this->queuedPurgeTags();
            $this->assertContains($this->key('single-product', 'template'), $tags, $term);
            $this->assertNotContains("template:{$term}//single-product", $tags, $term);
        }
    }

    // A customisation saved for another installed theme keeps that theme's key.
    public function test_another_installed_themes_customisation_keeps_its_own_key(): void
    {
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
        $postId = self::factory()->post->create(['post_type' => 'wp_template_part', 'post_status' => 'publish', 'post_name' => 'footer']);
        wp_set_object_terms($postId, $other, 'wp_theme');
        $this->resetCacheTags();

        wp_update_post(['ID' => $postId, 'post_content' => 'Other theme footer']);

        $tags = $this->queuedPurgeTags();
        $this->assertContains("template-part:{$other}//footer", $tags);
        $this->assertNotContains($this->key('footer'), $tags);
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

    // block_template_part() renders draft parts too (get_block_template()
    // queries drafts and auto-drafts), so saving one purges its pages.
    public function test_saving_a_draft_template_part_clears_its_key(): void
    {
        $draft = $this->templatePart('footer', 'draft');
        $this->templatePart('header');

        ob_start();
        block_template_part('footer');
        $html = ob_get_clean();
        $this->assertStringContainsString('Footer', $html, 'a draft part renders publicly');

        $this->resetCacheTags();
        wp_update_post(['ID' => $draft, 'post_content' => 'Still a draft']);

        $tags = $this->queuedPurgeTags();
        $this->assertContains($this->key('footer'), $tags);
        $this->assertNotContains($this->key('header'), $tags, 'only the saved part');
    }

    public function test_a_template_part_in_a_status_that_does_not_render_clears_nothing(): void
    {
        $pending = $this->templatePart('footer', 'pending');

        wp_update_post(['ID' => $pending, 'post_content' => 'Pending']);

        $this->assertNotContains($this->key('footer'), $this->queuedPurgeTags());
    }

    /**
     * Insert a part the way the Site Editor does: with its wp_theme term set
     * before the status transition, so the purge reads the term rather than
     * falling back to the active theme. tax_input needs a user who may assign
     * wp_theme terms.
     */
    private function templatePartWithoutReset(string $slug): int
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $postId = wp_insert_post([
            'post_type' => 'wp_template_part',
            'post_status' => 'publish',
            'post_name' => $slug,
            'post_title' => ucfirst($slug),
            'tax_input' => ['wp_theme' => [get_stylesheet()]],
        ]);
        $this->assertSame([get_stylesheet()], wp_get_post_terms($postId, 'wp_theme', ['fields' => 'names']), 'the term is set');

        return $postId;
    }
}
