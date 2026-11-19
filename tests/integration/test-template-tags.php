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

    public function test_feed_is_tagged_with_its_items_and_listing(): void
    {
        $postId = self::factory()->post->create(['post_status' => 'publish']);

        $tags = $this->templateTags(home_url('/?feed=rss2'));

        $this->assertContains("post:{$postId}", $tags);
        $this->assertContains('archive:post', $tags);
    }

    public function test_post_comment_feed_is_tagged_with_its_post(): void
    {
        $postId = self::factory()->post->create(['post_status' => 'publish']);

        $this->assertContains("post:{$postId}", $this->templateTags(home_url('/?feed=rss2&p='.$postId)));
    }

    public function test_sitemap_is_tagged_with_any_post_and_term_change(): void
    {
        $this->resetCacheTags();
        $wp = new WP;
        $wp->query_vars = ['sitemap' => 'posts', 'sitemap-subtype' => 'post'];

        (new Core($this->cacheTags))->addSitemapCacheTags($wp);

        $tags = $this->cacheTags->get();
        $this->assertContains('archive:post:any', $tags);
        $this->assertContains('taxonomy:category:any', $tags);
    }

    public function test_non_sitemap_request_gets_no_sitemap_tags(): void
    {
        $this->resetCacheTags();
        $wp = new WP;
        $wp->query_vars = ['pagename' => 'about'];

        (new Core($this->cacheTags))->addSitemapCacheTags($wp);

        $this->assertSame([], $this->cacheTags->get());
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
