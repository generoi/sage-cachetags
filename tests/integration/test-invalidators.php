<?php

use Genero\Sage\CacheTags\Invalidators\SiteGroundCacheInvalidator;
use Genero\Sage\CacheTags\Invalidators\SuperCacheInvalidator;

// Recording stubs for the host-cache provider functions, which the invalidators
// call directly (the real plugins aren't present in the test environment).
if (! function_exists('sg_cachepress_purge_cache')) {
    function sg_cachepress_purge_cache($url = false)
    {
        $GLOBALS['__sg_calls'][] = $url;

        return true;
    }
}
if (! function_exists('wpsc_delete_url_cache')) {
    function wpsc_delete_url_cache($url)
    {
        $GLOBALS['__wpsc_url_calls'][] = $url;

        return true;
    }
}
if (! function_exists('wpsc_delete_files')) {
    function wpsc_delete_files($dir)
    {
        $GLOBALS['__wpsc_flush'] = true;

        return true;
    }
}
if (! function_exists('get_supercache_dir')) {
    function get_supercache_dir()
    {
        return '/tmp/supercache';
    }
}

/**
 * @covers \Genero\Sage\CacheTags\Invalidators\SiteGroundCacheInvalidator
 * @covers \Genero\Sage\CacheTags\Invalidators\SuperCacheInvalidator
 */
class TestInvalidators extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        $GLOBALS['__sg_calls'] = [];
        $GLOBALS['__wpsc_url_calls'] = [];
        $GLOBALS['__wpsc_flush'] = false;
    }

    public function test_siteground_purges_each_url_under_the_threshold(): void
    {
        (new SiteGroundCacheInvalidator)->clear(['/a/', '/b/'], ['post:1']);

        $this->assertSame(['/a/', '/b/'], $GLOBALS['__sg_calls']);
    }

    public function test_siteground_bulk_flushes_over_the_threshold(): void
    {
        add_filter('cachetags/siteground-bulk-purge-threshold', fn () => 1);

        (new SiteGroundCacheInvalidator)->clear(['/a/', '/b/'], ['post:1']);

        // A single flush call (no url argument) rather than per-url purges.
        $this->assertSame([false], $GLOBALS['__sg_calls']);
    }

    public function test_supercache_purges_each_url_and_flushes(): void
    {
        $invalidator = new SuperCacheInvalidator;

        $this->assertTrue($invalidator->clear(['/a/', '/b/'], ['post:1']));
        $this->assertSame(['/a/', '/b/'], $GLOBALS['__wpsc_url_calls']);

        $this->assertTrue($invalidator->flush());
        $this->assertTrue($GLOBALS['__wpsc_flush']);
    }
}
