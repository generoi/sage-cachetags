<?php

use Genero\Sage\CacheTags\Actions\Site;
use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Tag;
use Genero\Sage\CacheTags\Tags\SiteTags;

/**
 * @covers \Genero\Sage\CacheTags\Actions\Site
 * @covers \Genero\Sage\CacheTags\Tags\SiteTags
 */
class TestSiteAction extends WP_UnitTestCase
{
    private function action(): Site
    {
        return new Site(CacheTags::getInstance());
    }

    public function test_prefixes_each_tag_with_the_current_site(): void
    {
        $id = get_current_blog_id();

        $result = Tag::toStrings($this->action()->addSitePrefix(['post:1', 'term:5']));

        $this->assertSame(["site:{$id}:post:1", "site:{$id}:term:5"], $result);
    }

    public function test_does_not_double_prefix_a_site_tag(): void
    {
        $id = get_current_blog_id();
        $siteTag = (string) SiteTags::sites()[0];

        $result = Tag::toStrings($this->action()->addSitePrefix([$siteTag, 'post:1']));

        $this->assertSame([$siteTag, "site:{$id}:post:1"], $result);
    }

    // Only THIS site's own bare tag is left unscoped; a custom site:* tag or
    // another site's tag flowing through still gets scoped (no cross-site collision).
    public function test_scopes_a_custom_or_foreign_site_tag(): void
    {
        $id = get_current_blog_id();

        $result = Tag::toStrings($this->action()->addSitePrefix(['site:foo', "site:{$id}", 'post:1']));

        $this->assertSame(["site:{$id}:site:foo", "site:{$id}", "site:{$id}:post:1"], $result);
    }

    public function test_add_site_tag_adds_the_current_site(): void
    {
        $cacheTags = CacheTags::getInstance();
        $ref = new ReflectionProperty($cacheTags, 'cacheTags');
        $ref->setAccessible(true);
        $ref->setValue($cacheTags, []);

        $this->action()->addSiteTag();

        $this->assertContains('site:'.get_current_blog_id(), $cacheTags->get());
    }
}
