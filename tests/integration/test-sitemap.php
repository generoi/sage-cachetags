<?php

use Genero\Sage\CacheTags\Actions\Sitemap;
use Genero\Sage\CacheTags\Tests\RestTestCase;

/**
 * @covers \Genero\Sage\CacheTags\Actions\Sitemap
 */
class TestSitemap extends RestTestCase
{
    /** Tags the Sitemap action adds for a request with the given query vars. */
    private function sitemapTags(array $queryVars): array
    {
        $this->resetCacheTags();
        // add() resets get()'s memo, so a request adding nothing reads [marker].
        $this->cacheTags->add('marker');
        $wp = new WP;
        $wp->query_vars = $queryVars;
        (new Sitemap($this->cacheTags))->addSitemapCacheTags($wp);

        return array_values(array_diff($this->cacheTags->get(), ['marker']));
    }

    public function test_core_post_type_sitemap_is_tagged_with_that_type_only(): void
    {
        $this->assertSame(['archive:page:any'], $this->sitemapTags(['sitemap' => 'posts', 'sitemap-subtype' => 'page']));
    }

    /** Only terms with posts are listed, so publishing a post can add one. */
    public function test_taxonomy_sitemap_is_tagged_with_its_terms_and_their_posts(): void
    {
        foreach ([['sitemap' => 'taxonomies', 'sitemap-subtype' => 'category'], ['sitemap' => 'category']] as $vars) {
            $tags = $this->sitemapTags($vars);

            $this->assertContains('taxonomy:category:any', $tags);
            $this->assertContains('archive:post:any', $tags);
            $this->assertNotContains('archive:page:any', $tags);
        }
    }

    public function test_assigning_terms_clears_the_taxonomy_sitemap(): void
    {
        $postId = self::factory()->post->create(['post_status' => 'publish']);
        $categoryId = self::factory()->category->create();
        $sitemap = new Sitemap($this->cacheTags);
        $sitemap->bind();
        $this->resetCacheTags();

        wp_set_object_terms($postId, [$categoryId], 'category');

        $this->assertContains('taxonomy:category:any', $this->queuedPurgeTags());
    }

    public function test_core_sitemap_index_is_tagged_with_listing_membership(): void
    {
        $tags = $this->sitemapTags(['sitemap' => 'index']);

        $this->assertContains('archive:post', $tags);
        $this->assertContains('taxonomy:category', $tags);
        $this->assertNotContains('archive:post:any', $tags);
    }

    public function test_yoast_post_type_sitemap_is_tagged_with_that_type_only(): void
    {
        $this->assertSame(['archive:post:any'], $this->sitemapTags(['sitemap' => 'post', 'sitemap_n' => '']));
    }

    /** Yoast's index dates each sub-sitemap by its newest entry. */
    public function test_yoast_sitemap_index_is_tagged_with_any_change(): void
    {
        $tags = $this->sitemapTags(['sitemap' => '1']);

        $this->assertContains('archive:post:any', $tags);
        $this->assertContains('taxonomy:category:any', $tags);
    }

    public function test_author_sitemaps_are_tagged_with_users_and_listings(): void
    {
        foreach (['users', 'author'] as $sitemap) {
            $tags = $this->sitemapTags(['sitemap' => $sitemap]);

            $this->assertContains('role:author', $tags);
            $this->assertContains('archive:post', $tags);
        }
    }

    public function test_non_sitemap_request_gets_no_sitemap_tags(): void
    {
        $this->assertSame([], $this->sitemapTags(['pagename' => 'about']));
    }
}
