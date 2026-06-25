<?php

use Genero\Sage\CacheTags\Tag;
use Genero\Sage\CacheTags\Tags\CoreTags;

/**
 * Pure formatting behaviour of the tag helpers — the builders return Tag objects,
 * asserted here in their string form.
 *
 * @covers \Genero\Sage\CacheTags\Tags\CoreTags
 */
class TestCoreTags extends WP_UnitTestCase
{
    /**
     * @param  Tag[]  $tags
     * @return string[]
     */
    private function strings(array $tags): array
    {
        return Tag::toStrings($tags);
    }

    public function test_posts_formats_ids_and_objects(): void
    {
        $post = self::factory()->post->create_and_get();

        $this->assertSame(['post:1'], $this->strings(CoreTags::posts(1)));
        $this->assertSame(['post:1', 'post:2'], $this->strings(CoreTags::posts([1, 2])));
        $this->assertSame(["post:{$post->ID}"], $this->strings(CoreTags::posts($post)));
        $this->assertSame([], $this->strings(CoreTags::posts(null)));
    }

    public function test_terms_and_term_pages(): void
    {
        $this->assertSame(['term:5'], $this->strings(CoreTags::terms(5)));
        $this->assertSame(['term:5', 'term:6'], $this->strings(CoreTags::terms([5, 6])));
        $this->assertSame(['term:5:full'], $this->strings(CoreTags::termPages(5)));
    }

    public function test_users(): void
    {
        $this->assertSame(['user:2'], $this->strings(CoreTags::users(2)));
        $this->assertSame(['user:2', 'user:3'], $this->strings(CoreTags::users([2, 3])));
    }

    public function test_comments(): void
    {
        $this->assertSame(['comment:9'], $this->strings(CoreTags::comments(9)));
    }

    public function test_archive_accepts_string_and_array(): void
    {
        $this->assertSame(['archive:post'], $this->strings(CoreTags::archive('post')));
        $this->assertSame(['archive:post', 'archive:page'], $this->strings(CoreTags::archive(['post', 'page'])));
    }

    public function test_taxonomy_and_any_term(): void
    {
        $this->assertSame(['taxonomy:category'], $this->strings(CoreTags::taxonomy('category')));
        $this->assertSame(['taxonomy:category:any'], $this->strings(CoreTags::anyTerm('category')));
    }

    public function test_any_archive(): void
    {
        $this->assertSame(['archive:post:any'], $this->strings(CoreTags::anyArchive('post')));
        $this->assertSame(['archive:post:any', 'archive:page:any'], $this->strings(CoreTags::anyArchive(['post', 'page'])));
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

        $this->assertSame(["post:{$post->ID}"], $this->strings(CoreTags::queriedObject($post)));
        $this->assertSame(
            ["term:{$termId}", "term:{$termId}:full"],
            $this->strings(CoreTags::queriedObject(get_term($termId)))
        );
        $this->assertSame(['archive:post'], $this->strings(CoreTags::queriedObject(get_post_type_object('post'))));
    }

    public function test_query_tags_each_post_in_a_wp_query(): void
    {
        $ids = self::factory()->post->create_many(3, ['post_status' => 'publish']);
        $query = new WP_Query(['post__in' => $ids, 'orderby' => 'post__in']);

        $this->assertSame(
            array_map(fn ($id) => "post:{$id}", $ids),
            $this->strings(CoreTags::query($query))
        );
    }

    public function test_any_user(): void
    {
        $this->assertSame(['role:editor'], $this->strings(CoreTags::anyUser('editor')));
        $this->assertSame(['role:editor', 'role:author'], $this->strings(CoreTags::anyUser(['editor', 'author'])));
    }

    public function test_menu_and_navigation(): void
    {
        $menuId = wp_create_nav_menu('Footer');

        $this->assertSame(["menu:{$menuId}"], $this->strings(CoreTags::menu($menuId)));

        register_nav_menu('footer', 'Footer location');
        set_theme_mod('nav_menu_locations', ['footer' => $menuId]);

        $this->assertSame(["menu:{$menuId}"], $this->strings(CoreTags::navigation('footer')));
        $this->assertSame([], $this->strings(CoreTags::navigation('missing-location')));
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

    public function test_option_formats_option_tags(): void
    {
        $this->assertSame(['option:blogname'], $this->strings(CoreTags::option('blogname')));
        $this->assertSame(['option:blogname', 'option:foo'], $this->strings(CoreTags::option(['blogname', 'foo'])));
    }

    public function test_cacheable_options_default_and_filter(): void
    {
        $this->assertContains('blogname', CoreTags::getCacheableOptions());

        add_filter('cachetags/options', fn ($options) => [...$options, 'my_option']);

        $this->assertContains('my_option', CoreTags::getCacheableOptions());
    }

    public function test_any_keyword_expands_to_the_cacheable_sets(): void
    {
        $this->assertContains('archive:post', $this->strings(CoreTags::archive('any')));
        $this->assertContains('taxonomy:category', $this->strings(CoreTags::taxonomy('any')));
        $this->assertContains('taxonomy:category:any', $this->strings(CoreTags::anyTerm('any')));
        $this->assertContains('archive:post:any', $this->strings(CoreTags::anyArchive('any')));
        $this->assertContains('role:administrator', $this->strings(CoreTags::anyUser('any')));
    }

    public function test_set_builders_accept_wp_objects(): void
    {
        $this->assertSame(['archive:post'], $this->strings(CoreTags::archive(get_post_type_object('post'))));
        $this->assertSame(['archive:post:any'], $this->strings(CoreTags::anyArchive(get_post_type_object('post'))));
        $this->assertSame(['taxonomy:category'], $this->strings(CoreTags::taxonomy(get_taxonomy('category'))));
        $this->assertSame(['taxonomy:category:any'], $this->strings(CoreTags::anyTerm(get_taxonomy('category'))));
        $this->assertSame(['role:administrator'], $this->strings(CoreTags::anyUser(get_role('administrator'))));
    }

    public function test_builders_return_empty_for_unusable_input(): void
    {
        $this->assertSame([], CoreTags::terms(null));
        $this->assertSame([], CoreTags::users(null));
        $this->assertSame([], CoreTags::comments(null));
        $this->assertSame([], CoreTags::archive(123));
        $this->assertSame([], CoreTags::taxonomy(123));
        $this->assertSame([], CoreTags::anyTerm(123));
        $this->assertSame([], CoreTags::anyArchive(123));
        $this->assertSame([], CoreTags::anyUser(123));
        $this->assertSame([], CoreTags::menu(null));
    }

    public function test_menu_resolves_by_id_slug_and_name(): void
    {
        $menuId = wp_create_nav_menu('Primary');

        // wp_nav_menu()'s `menu` arg can be an id, slug, name, or object.
        $this->assertSame(["menu:{$menuId}"], $this->strings(CoreTags::menu($menuId)));
        $this->assertSame(["menu:{$menuId}"], $this->strings(CoreTags::menu('primary')));
        $this->assertSame(["menu:{$menuId}"], $this->strings(CoreTags::menu('Primary')));
    }

    public function test_menu_throws_for_an_unknown_menu(): void
    {
        $this->expectException(Exception::class);
        CoreTags::menu('no-such-menu');
    }

    public function test_queried_object_throws_for_an_unsupported_object(): void
    {
        $this->expectException(Exception::class);
        CoreTags::queriedObject(self::factory()->user->create_and_get());
    }

    public function test_is_cacheable_predicates_accept_objects_ids_and_terms(): void
    {
        $postId = self::factory()->post->create();
        $termId = self::factory()->category->create();

        $this->assertTrue(CoreTags::isCacheablePostType(get_post_type_object('post')));
        $this->assertTrue(CoreTags::isCacheablePostType($postId));
        $this->assertTrue(CoreTags::isCacheablePostType(get_post($postId)));

        $this->assertTrue(CoreTags::isCacheableTaxonomy(get_taxonomy('category')));
        $this->assertTrue(CoreTags::isCacheableTaxonomy($termId));
        $this->assertTrue(CoreTags::isCacheableTaxonomy(get_term($termId)));
    }
}
