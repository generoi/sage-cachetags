<?php

use Genero\Sage\CacheTags\Actions\BaseTag;
use Genero\Sage\CacheTags\Tests\RestTestCase;

/**
 * Site Editor content renders into every page, so saving it clears the base tag.
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

    private function sitePost(string $postType, string $status = 'publish'): int
    {
        $postId = self::factory()->post->create([
            'post_type' => $postType,
            'post_status' => $status,
            'post_name' => 'footer',
        ]);
        $this->resetCacheTags();

        return $postId;
    }

    public static function siteWidePostTypes(): array
    {
        return array_map(fn ($type) => [$type], BaseTag::SITE_WIDE_POST_TYPES);
    }

    /**
     * @dataProvider siteWidePostTypes
     */
    public function test_publishing_site_editor_content_clears_the_base_tag(string $postType): void
    {
        $this->resetCacheTags();

        self::factory()->post->create(['post_type' => $postType, 'post_status' => 'publish']);

        $this->assertContains('page', $this->queuedPurgeTags());
    }

    public function test_updating_a_template_part_clears_the_base_tag(): void
    {
        $postId = $this->sitePost('wp_template_part');

        wp_update_post(['ID' => $postId, 'post_content' => '<!-- wp:paragraph --><p>New footer</p><!-- /wp:paragraph -->']);

        $this->assertContains('page', $this->queuedPurgeTags());
    }

    public function test_trashing_a_template_part_clears_the_base_tag(): void
    {
        $postId = $this->sitePost('wp_template_part');

        wp_trash_post($postId);

        $this->assertContains('page', $this->queuedPurgeTags());
    }

    // "Clear customizations" force-deletes the post, skipping the status hook.
    public function test_resetting_a_template_part_to_the_theme_file_clears_the_base_tag(): void
    {
        $postId = $this->sitePost('wp_template_part');

        wp_delete_post($postId, true);

        $this->assertContains('page', $this->queuedPurgeTags());
    }

    public function test_draft_site_editor_content_clears_nothing(): void
    {
        $postId = $this->sitePost('wp_template_part', 'draft');

        wp_update_post(['ID' => $postId, 'post_content' => 'Still a draft']);
        wp_delete_post($postId, true);

        $this->assertNotContains('page', $this->queuedPurgeTags());
    }

    public function test_other_post_types_do_not_clear_the_base_tag(): void
    {
        $this->resetCacheTags();

        self::factory()->post->create(['post_status' => 'publish']);

        $this->assertNotContains('page', $this->queuedPurgeTags());
    }
}
