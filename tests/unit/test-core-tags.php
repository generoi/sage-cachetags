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
}
