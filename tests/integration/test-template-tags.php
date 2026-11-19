<?php

use Genero\Sage\CacheTags\Actions\Core;
use Genero\Sage\CacheTags\Tests\RestTestCase;

/**
 * Front-end template tagging (Core::addTemplateCacheTags).
 *
 * @covers \Genero\Sage\CacheTags\Actions\Core
 */
class TestTemplateTags extends RestTestCase
{
    /** Tags added for the template that would render the given URL. */
    private function templateTags(string $url): array
    {
        $this->resetCacheTags();
        $this->go_to($url);
        (new Core($this->cacheTags))->addTemplateCacheTags();

        return $this->cacheTags->get();
    }

    /** Tags added for a sitemap request with the given query vars. */
    private function sitemapTags(array $queryVars): array
    {
        $this->resetCacheTags();
        $wp = new WP;
        $wp->query_vars = $queryVars;
        (new Core($this->cacheTags))->addSitemapCacheTags($wp);

        return $this->cacheTags->get();
    }

    /** is_home() is never set on a feed, so the main feed needs its own listing tag. */
    public function test_main_feed_is_tagged_as_the_post_listing(): void
    {
        $this->assertContains('archive:post', $this->templateTags(home_url('/?feed=rss2')));
    }

    public function test_feed_is_tagged_with_its_items_authors_and_terms(): void
    {
        $authorId = self::factory()->user->create(['role' => 'author']);
        $categoryId = self::factory()->category->create();
        $tagId = self::factory()->tag->create();
        $postId = self::factory()->post->create(['post_status' => 'publish', 'post_author' => $authorId]);
        wp_set_post_categories($postId, [$categoryId]);
        wp_set_post_tags($postId, [$tagId]);

        $tags = $this->templateTags(home_url('/?feed=rss2'));

        $this->assertContains("post:{$postId}", $tags);
        $this->assertContains("user:{$authorId}", $tags);
        $this->assertContains("term:{$categoryId}", $tags);
        $this->assertContains("term:{$tagId}", $tags);
    }

    public function test_category_feed_is_tagged_like_its_term_page(): void
    {
        $categoryId = self::factory()->category->create();

        $tags = $this->templateTags(home_url('/?feed=rss2&cat='.$categoryId));

        $this->assertContains("term:{$categoryId}", $tags);
        $this->assertNotContains('archive:post', $tags);
    }

    public function test_post_comment_feed_is_tagged_with_its_post(): void
    {
        $postId = self::factory()->post->create(['post_status' => 'publish']);

        $this->assertContains("post:{$postId}", $this->templateTags(home_url('/?feed=rss2&p='.$postId)));
    }

    public function test_site_comments_feed_is_tagged_with_any_comment(): void
    {
        $tags = $this->templateTags(home_url('/?feed=rss2&withcomments=1'));

        $this->assertContains('comment:any', $tags);
        $this->assertNotContains('archive:post', $tags);
    }

    public function test_core_post_type_sitemap_is_tagged_with_that_type_only(): void
    {
        $tags = $this->sitemapTags(['sitemap' => 'posts', 'sitemap-subtype' => 'page']);

        $this->assertSame(['archive:page:any'], $tags);
    }

    public function test_core_taxonomy_sitemap_is_tagged_with_that_taxonomy_only(): void
    {
        $this->assertSame(['taxonomy:category:any'], $this->sitemapTags(['sitemap' => 'taxonomies', 'sitemap-subtype' => 'category']));
    }

    public function test_core_sitemap_index_is_tagged_with_listing_membership(): void
    {
        $tags = $this->sitemapTags(['sitemap' => 'index']);

        $this->assertContains('archive:post', $tags);
        $this->assertContains('taxonomy:category', $tags);
        $this->assertNotContains('archive:post:any', $tags);
    }

    public function test_yoast_type_sitemaps_are_tagged_with_their_type(): void
    {
        $this->assertSame(['archive:post:any'], $this->sitemapTags(['sitemap' => 'post', 'sitemap_n' => '']));
        $this->assertSame(['taxonomy:post_tag:any'], $this->sitemapTags(['sitemap' => 'post_tag']));
    }

    /** Yoast's index dates each sub-sitemap by its newest entry. */
    public function test_yoast_sitemap_index_is_tagged_with_any_change(): void
    {
        $tags = $this->sitemapTags(['sitemap' => '1']);

        $this->assertContains('archive:post:any', $tags);
        $this->assertContains('taxonomy:category:any', $tags);
    }

    public function test_non_sitemap_request_gets_no_sitemap_tags(): void
    {
        $this->assertSame([], $this->sitemapTags(['pagename' => 'about']));
    }

    public function test_search_results_are_tagged_as_a_post_archive(): void
    {
        $this->assertContains('archive:post', $this->templateTags(home_url('/?s=hello')));
    }

    /**
     * `/no-such-page/?s=x` sets is_page() and is_search() with no queried
     * object. WordPress renders the search template, so tag it as one rather
     * than fataling on the missing page.
     */
    public function test_search_on_a_missing_page_is_tagged_as_search(): void
    {
        $this->assertSame(['archive:post'], $this->templateTags(home_url('/?pagename=no-such-page&s=x')));
    }

    public function test_search_on_a_missing_term_is_tagged_as_search(): void
    {
        $this->assertSame(['archive:post'], $this->templateTags(home_url('/?category_name=no-such-term&s=x')));
    }

    public function test_date_archive_is_tagged(): void
    {
        self::factory()->post->create(['post_status' => 'publish', 'post_date' => '2020-01-15 12:00:00']);

        $this->assertContains('archive:post', $this->templateTags(home_url('/?year=2020&monthnum=1')));
    }

    public function test_attachment_page_is_tagged(): void
    {
        $postId = self::factory()->post->create(['post_status' => 'publish']);
        $attachmentId = self::factory()->attachment->create_object('image.jpg', $postId, [
            'post_mime_type' => 'image/jpeg',
        ]);

        $this->assertContains(
            "post:{$attachmentId}",
            $this->templateTags(home_url('/?attachment_id='.$attachmentId))
        );
    }
}
