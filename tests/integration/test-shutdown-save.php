<?php

use Genero\Sage\CacheTags\Actions\Feed;
use Genero\Sage\CacheTags\Actions\Sitemap;
use Genero\Sage\CacheTags\Bootstrap;
use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Stores\TransientStore;

/**
 * Feeds and sitemaps print no wp_footer, so their actions save at shutdown.
 *
 * @covers \Genero\Sage\CacheTags\Concerns\SavesAtShutdown
 */
class TestShutdownSave extends WP_UnitTestCase
{
    private ?CacheTags $saved;

    private CacheTags $cacheTags;

    public function set_up(): void
    {
        parent::set_up();
        $this->saved = $this->instanceProp()->getValue();
        $this->instanceProp()->setValue(null, null);
        $this->cacheTags = (new Bootstrap)->store(TransientStore::class)->actions([])->disable()->bootstrap();
    }

    public function tear_down(): void
    {
        $this->instanceProp()->setValue(null, $this->saved);
        parent::tear_down();
    }

    private function instanceProp(): ReflectionProperty
    {
        $prop = new ReflectionProperty(CacheTags::class, 'instance');
        $prop->setAccessible(true);

        return $prop;
    }

    public function test_a_feed_is_stored(): void
    {
        $this->go_to(home_url('/?feed=rss2'));
        $this->cacheTags->add('stored-feed');

        (new Feed($this->cacheTags))->saveFeedCacheTags();

        $this->assertNotEmpty($this->cacheTags->store->get(['stored-feed']));
    }

    public function test_a_page_is_not_stored_by_the_feed_action(): void
    {
        $this->go_to(home_url('/'));
        $this->cacheTags->add('shutdown-page');

        (new Feed($this->cacheTags))->saveFeedCacheTags();
        (new Sitemap($this->cacheTags))->saveSitemapCacheTags();

        $this->assertEmpty($this->cacheTags->store->get(['shutdown-page']));
    }

    public function test_a_sitemap_is_stored(): void
    {
        $this->go_to(home_url('/'));
        // go_to() can't route a sitemap here; set the var the router would.
        $GLOBALS['wp_query']->set('sitemap', 'posts');
        $this->cacheTags->add('stored-sitemap');

        (new Sitemap($this->cacheTags))->saveSitemapCacheTags();

        $this->assertNotEmpty($this->cacheTags->store->get(['stored-sitemap']));
    }

    /** do_feed() wp_die()s an unknown ?feed= with a 404 without set_404(). */
    public function test_an_error_response_is_not_stored(): void
    {
        $this->go_to(home_url('/?feed=rss2'));
        $this->cacheTags->add('stored-error');
        $previous = http_response_code();
        http_response_code(404);

        try {
            (new Feed($this->cacheTags))->saveFeedCacheTags();
        } finally {
            http_response_code($previous ?: 200);
        }

        $this->assertEmpty($this->cacheTags->store->get(['stored-error']));
    }
}
