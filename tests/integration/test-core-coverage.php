<?php

use Genero\Sage\CacheTags\Actions\Core;
use Genero\Sage\CacheTags\CacheTags;

/**
 * Real exercising of the Core action's template/block/nav-menu tagging branches.
 *
 * @covers \Genero\Sage\CacheTags\Actions\Core
 */
class TestCoreCoverage extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        $this->set_permalink_structure('/%postname%/');
    }

    private function action(): Core
    {
        return new Core(CacheTags::getInstance());
    }

    private function resetTags(): CacheTags
    {
        $cacheTags = CacheTags::getInstance();
        $ref = new ReflectionProperty($cacheTags, 'cacheTags');
        $ref->setAccessible(true);
        $ref->setValue($cacheTags, []);

        return $cacheTags;
    }

    private function blockTags(string $name, array $attrs = [], array $context = []): array
    {
        $cacheTags = $this->resetTags();
        $block = ['blockName' => $name, 'attrs' => $attrs];
        $instance = new WP_Block($block);
        if ($context) {
            $instance->context = $context;
        }
        $this->action()->addBlockCacheTags('rendered', $block, $instance);

        return $cacheTags->get();
    }

    public function test_bind_registers_the_core_hooks(): void
    {
        $action = $this->action();
        $action->bind();

        $this->assertNotFalse(has_action('template_redirect', [$action, 'addTemplateCacheTags']));
        $this->assertNotFalse(has_filter('render_block', [$action, 'addBlockCacheTags']));
        $this->assertNotFalse(has_filter('wp_nav_menu_args', [$action, 'addNavMenuCacheTags']));
        $this->assertNotFalse(has_action('transition_post_status', [$action, 'onPostStatusTransition']));
        $this->assertNotFalse(has_action('saved_term', [$action, 'onTermSave']));
        $this->assertNotFalse(has_action('user_register', [$action, 'onUserCreate']));
    }

    /**
     * @dataProvider blockProvider
     */
    public function test_block_tagging(string $name, array $attrs, array $context, string $expected): void
    {
        $this->assertContains($expected, $this->blockTags($name, $attrs, $context));
    }

    public static function blockProvider(): array
    {
        return [
            'categories' => ['core/categories', [], [], 'taxonomy:category:any'],
            'archives' => ['core/archives', [], [], 'archive:post'],
            'calendar' => ['core/calendar', [], [], 'archive:post'],
            'avatar' => ['core/avatar', ['userId' => 5], [], 'user:5'],
            'site-title' => ['core/site-title', [], [], 'option:blogname'],
            'site-tagline' => ['core/site-tagline', [], [], 'option:blogdescription'],
            'site-logo' => ['core/site-logo', [], [], 'option:site_logo'],
            'tag-cloud' => ['core/tag-cloud', [], [], 'taxonomy:post_tag:any'],
            'page-list' => ['core/page-list', [], [], 'archive:page'],
            'latest-posts' => ['core/latest-posts', [], [], 'archive:post'],
            'latest-comments' => ['core/latest-comments', [], [], 'archive:comment'],
            'navigation-link' => ['core/navigation-link', ['kind' => 'post-type', 'id' => 5], [], 'post:5'],
            'query' => ['core/query', ['query' => ['postType' => 'page']], [], 'archive:page'],
            'context postId' => ['core/paragraph', [], ['postId' => 12], 'post:12'],
            'context commentId' => ['core/paragraph', [], ['commentId' => 7], 'comment:7'],
            'attr ref' => ['core/block', ['ref' => 9], [], 'post:9'],
        ];
    }

    public function test_block_tags_filter_lets_sites_tag_custom_blocks(): void
    {
        $callback = fn ($tags, $block) => $block['blockName'] === 'core/paragraph' ? [...$tags, 'custom:1'] : $tags;
        add_filter('cachetags/block-tags', $callback, 10, 2);

        $this->assertContains('custom:1', $this->blockTags('core/paragraph', []));
    }

    public function test_navigation_link_without_attributes_is_a_safe_noop(): void
    {
        // No 'kind'/'id' — must not raise an undefined-index warning (which
        // PHPUnit turns into an exception) and must add no post tag.
        $tags = $this->blockTags('core/navigation-link', []);

        $this->assertSame([], array_filter($tags, fn ($tag) => str_starts_with($tag, 'post:')));
    }

    public function test_post_author_block_uses_the_context_post_author(): void
    {
        $userId = self::factory()->user->create();
        $postId = self::factory()->post->create(['post_author' => $userId]);

        $this->assertContains("user:{$userId}", $this->blockTags('core/post-author', [], ['postId' => $postId]));
    }

    public function test_post_terms_block_tags_the_posts_terms(): void
    {
        $termId = self::factory()->category->create();
        $postId = self::factory()->post->create();
        wp_set_object_terms($postId, [$termId], 'category');

        $tags = $this->blockTags('core/post-terms', ['term' => 'category'], ['postId' => $postId]);
        $this->assertContains("term:{$termId}", $tags);
    }

    public function test_nav_menu_tags_by_theme_location_and_by_menu(): void
    {
        $primaryId = wp_create_nav_menu('Primary');
        set_theme_mod('nav_menu_locations', ['primary' => $primaryId]);
        $cacheTags = $this->resetTags();
        $this->action()->addNavMenuCacheTags(['theme_location' => 'primary']);
        $this->assertContains("menu:{$primaryId}", $cacheTags->get());

        $footerId = wp_create_nav_menu('Footer');
        $cacheTags = $this->resetTags();
        $this->action()->addNavMenuCacheTags(['menu' => 'footer']);
        $this->assertContains("menu:{$footerId}", $cacheTags->get());
    }

    public function test_template_home_tags_the_post_archive(): void
    {
        $cacheTags = $this->resetTags();
        $this->go_to(home_url('/'));
        $this->action()->addTemplateCacheTags();

        $this->assertContains('archive:post', $cacheTags->get());
    }

    public function test_template_search_tags_the_post_archive(): void
    {
        $cacheTags = $this->resetTags();
        $this->go_to(home_url('/?s=hello'));
        $this->action()->addTemplateCacheTags();

        $this->assertContains('archive:post', $cacheTags->get());
    }

    public function test_template_date_archive_tags_the_post_archive(): void
    {
        self::factory()->post->create(['post_status' => 'publish', 'post_date' => '2026-06-15 10:00:00']);
        $cacheTags = $this->resetTags();
        $this->go_to(home_url('/2026/06/'));
        $this->assertTrue(is_date());
        $this->action()->addTemplateCacheTags();

        $this->assertContains('archive:post', $cacheTags->get());
    }

    public function test_template_author_archive_tags_the_author(): void
    {
        $userId = self::factory()->user->create(['role' => 'author']);
        self::factory()->post->create(['post_author' => $userId, 'post_status' => 'publish']);
        $cacheTags = $this->resetTags();
        $this->go_to(get_author_posts_url($userId));
        $this->assertTrue(is_author());
        $this->action()->addTemplateCacheTags();

        $this->assertContains("user:{$userId}", $cacheTags->get());
    }

    public function test_template_post_type_archive_tags_the_archive(): void
    {
        register_post_type('book', ['public' => true, 'has_archive' => true]);
        flush_rewrite_rules();
        self::factory()->post->create(['post_type' => 'book', 'post_status' => 'publish']);
        $cacheTags = $this->resetTags();
        $this->go_to(get_post_type_archive_link('book'));
        $this->assertTrue(is_post_type_archive('book'));
        $this->action()->addTemplateCacheTags();
        unregister_post_type('book');

        $this->assertContains('archive:book', $cacheTags->get());
    }
}
