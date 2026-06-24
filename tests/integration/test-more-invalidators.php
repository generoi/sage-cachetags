<?php

use Genero\Sage\CacheTags\Invalidators\DebugCacheInvalidator;
use Genero\Sage\CacheTags\Invalidators\WpRocketCacheInvalidator;

if (! function_exists('rocket_clean_files')) {
    function rocket_clean_files($urls)
    {
        $GLOBALS['__rocket_files'] = $urls;

        return true;
    }
}
if (! function_exists('rocket_clean_domain')) {
    function rocket_clean_domain()
    {
        $GLOBALS['__rocket_domain'] = true;
    }
}

/**
 * @covers \Genero\Sage\CacheTags\Invalidators\WpRocketCacheInvalidator
 * @covers \Genero\Sage\CacheTags\Invalidators\DebugCacheInvalidator
 */
class TestMoreInvalidators extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        $GLOBALS['__rocket_files'] = null;
        $GLOBALS['__rocket_domain'] = false;
    }

    public function test_wprocket_cleans_the_given_urls(): void
    {
        $this->assertTrue((new WpRocketCacheInvalidator)->clear(['/a/', '/b/'], ['post:1']));
        $this->assertSame(['/a/', '/b/'], $GLOBALS['__rocket_files']);
    }

    public function test_wprocket_skips_the_call_for_no_urls(): void
    {
        $this->assertTrue((new WpRocketCacheInvalidator)->clear([], ['post:1']));
        $this->assertNull($GLOBALS['__rocket_files']);
    }

    public function test_wprocket_flush_cleans_the_domain(): void
    {
        $this->assertTrue((new WpRocketCacheInvalidator)->flush());
        $this->assertTrue($GLOBALS['__rocket_domain']);
    }

    public function test_debug_invalidator_always_succeeds(): void
    {
        $this->assertTrue((new DebugCacheInvalidator)->clear(['/a/'], ['post:1']));
        $this->assertTrue((new DebugCacheInvalidator)->flush());
    }
}
