<?php

use Genero\Sage\CacheTags\Tags\CoreTags;

/**
 * Pure formatting behaviour of the tag helpers.
 *
 * @covers \Genero\Sage\CacheTags\Tags\CoreTags
 */
class TestCoreTags extends WP_UnitTestCase
{
    public function test_posts_formats_ids_and_objects(): void
    {
        $post = self::factory()->post->create_and_get();

        $this->assertSame(['post:1'], CoreTags::posts(1));
        $this->assertSame(['post:1', 'post:2'], CoreTags::posts([1, 2]));
        $this->assertSame(["post:{$post->ID}"], CoreTags::posts($post));
        $this->assertSame([], CoreTags::posts(null));
    }

    public function test_terms_and_term_pages(): void
    {
        $this->assertSame(['term:5'], CoreTags::terms(5));
        $this->assertSame(['term:5', 'term:6'], CoreTags::terms([5, 6]));
        $this->assertSame(['term:5:full'], CoreTags::termPages(5));
    }

    public function test_users(): void
    {
        $this->assertSame(['user:2'], CoreTags::users(2));
        $this->assertSame(['user:2', 'user:3'], CoreTags::users([2, 3]));
    }

    public function test_comments(): void
    {
        $this->assertSame(['comment:9'], CoreTags::comments(9));
    }

    public function test_archive_accepts_string_and_array(): void
    {
        $this->assertSame(['archive:post'], CoreTags::archive('post'));
        $this->assertSame(['archive:post', 'archive:page'], CoreTags::archive(['post', 'page']));
    }

    public function test_taxonomy_and_any_term(): void
    {
        $this->assertSame(['taxonomy:category'], CoreTags::taxonomy('category'));
        $this->assertSame(['taxonomy:category:any'], CoreTags::anyTerm('category'));
    }

    public function test_any_archive(): void
    {
        $this->assertSame(['archive:post:any'], CoreTags::anyArchive('post'));
        $this->assertSame(['archive:post:any', 'archive:page:any'], CoreTags::anyArchive(['post', 'page']));
    }

    public function test_cacheable_user_roles_are_slugs(): void
    {
        $roles = CoreTags::getCacheableUserRoles();

        $this->assertContains('administrator', $roles, 'Roles must be slugs, not display names');
    }

    public function test_queried_object_maps_each_object_kind(): void
    {
        $post = self::factory()->post->create_and_get();
        $termId = self::factory()->category->create();

        $this->assertSame(["post:{$post->ID}"], CoreTags::queriedObject($post));
        $this->assertSame(
            ["term:{$termId}", "term:{$termId}:full"],
            CoreTags::queriedObject(get_term($termId))
        );
        $this->assertSame(['archive:post'], CoreTags::queriedObject(get_post_type_object('post')));
    }

    public function test_query_tags_each_post_in_a_wp_query(): void
    {
        $ids = self::factory()->post->create_many(3, ['post_status' => 'publish']);
        $query = new WP_Query(['post__in' => $ids, 'orderby' => 'post__in']);

        $this->assertSame(
            array_map(fn ($id) => "post:{$id}", $ids),
            CoreTags::query($query)
        );
    }

    public function test_any_user(): void
    {
        $this->assertSame(['role:editor'], CoreTags::anyUser('editor'));
        $this->assertSame(['role:editor', 'role:author'], CoreTags::anyUser(['editor', 'author']));
    }

    public function test_menu_and_navigation(): void
    {
        $menuId = wp_create_nav_menu('Footer');

        $this->assertSame(["menu:{$menuId}"], CoreTags::menu($menuId));

        register_nav_menu('footer', 'Footer location');
        set_theme_mod('nav_menu_locations', ['footer' => $menuId]);

        $this->assertSame(["menu:{$menuId}"], CoreTags::navigation('footer'));
        $this->assertSame([], CoreTags::navigation('missing-location'));
    }

    public function test_is_cacheable_predicates(): void
    {
        $this->assertTrue(CoreTags::isCacheablePostType('post'));
        $this->assertFalse(CoreTags::isCacheablePostType('revision'));
        $this->assertTrue(CoreTags::isCacheableTaxonomy('category'));
        $this->assertFalse(CoreTags::isCacheableTaxonomy('nav_menu'));
    }

    public function test_is_cacheable_post_meta_excludes_protected_keys(): void
    {
        $postId = self::factory()->post->create();

        $this->assertTrue(CoreTags::isCacheablePostMeta('subtitle', $postId));
        $this->assertFalse(CoreTags::isCacheablePostMeta('_thumbnail_id', $postId));
    }
}
