<?php

use Genero\Sage\CacheTags\Bootstrap;
use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Stores\WordpressDbStore;
use Genero\Sage\CacheTags\Util;

/**
 * A page whose URL doesn't fit the store's url column can't be purged, so it
 * is neither stored nor cached.
 *
 * @covers \Genero\Sage\CacheTags\Util::isStorableUrl
 * @covers \Genero\Sage\CacheTags\Util::isCacheableRequest
 * @covers \Genero\Sage\CacheTags\Bootstrap::preventCachingUnstorableUrl
 * @covers \Genero\Sage\CacheTags\Stores\WordpressDbStore::save
 */
class TestLongUrl extends WP_UnitTestCase
{
    private string $request;

    private int $nocacheHeaders = 0;

    public function set_up(): void
    {
        parent::set_up();
        global $wp;
        $this->request = (string) $wp->request;
        add_filter('nocache_headers', [$this, 'countNocacheHeaders']);
    }

    public function tear_down(): void
    {
        global $wp;
        $wp->request = $this->request;
        remove_filter('nocache_headers', [$this, 'countNocacheHeaders']);
        parent::tear_down();
    }

    public function countNocacheHeaders(array $headers): array
    {
        $this->nocacheHeaders++;

        return $headers;
    }

    public function test_a_long_url_is_neither_stored_nor_cached(): void
    {
        $this->visit(str_repeat('a', Util::MAX_LENGTH));
        $this->assertGreaterThan(Util::MAX_LENGTH, strlen(Util::currentUrl()));

        $store = new WordpressDbStore;
        $bootstrap = $this->bootstrap($store);
        CacheTags::getInstance()->add(['post:4242']);

        $this->assertFalse(Util::isCacheableRequest());

        $bootstrap->preventCachingUnstorableUrl();
        $bootstrap->saveCacheTags();

        $this->assertSame(1, $this->nocacheHeaders, 'no-cache headers keep the edge off it');
        $this->assertTrue(defined('DONOTCACHEPAGE'), 'page caches skip it');
        $this->assertSame([], $store->get(['post:4242']), 'nothing stored, not even a truncated URL');
    }

    public function test_a_normal_url_is_stored_and_cacheable(): void
    {
        $this->visit('a-normal-page');

        $store = new WordpressDbStore;
        $bootstrap = $this->bootstrap($store);
        CacheTags::getInstance()->add(['post:4243']);

        $this->assertTrue(Util::isCacheableRequest());

        $bootstrap->preventCachingUnstorableUrl();
        $bootstrap->saveCacheTags();

        $this->assertSame(0, $this->nocacheHeaders, 'no no-cache headers');
        $this->assertSame([Util::currentUrl()], $store->get(['post:4243']));
    }

    public function test_the_store_does_not_insert_an_overlong_url(): void
    {
        $store = new WordpressDbStore;
        $long = 'https://example.com/'.str_repeat('a', Util::MAX_LENGTH).'/';

        $this->assertFalse($store->save(['post:4244'], $long));
        $this->assertSame([], $store->get(['post:4244']));

        $limit = 'https://example.com/'.str_repeat('a', Util::MAX_LENGTH - 21).'/';
        $this->assertSame(Util::MAX_LENGTH, strlen($limit));
        $this->assertTrue($store->save(['post:4244'], $limit));
        $this->assertSame([$limit], $store->get(['post:4244']), 'a URL exactly at the limit is stored');
    }

    private function visit(string $path): void
    {
        global $wp;
        $wp->request = $path;
    }

    private function bootstrap(WordpressDbStore $store): Bootstrap
    {
        $bootstrap = new Bootstrap;
        $bootstrap->store($store)->actions([])->autoDetectActions(false)->baseTag(null)->pruneOlderThan(null)->bootstrap();

        return $bootstrap;
    }
}
