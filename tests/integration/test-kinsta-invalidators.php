<?php

use Genero\Sage\CacheTags\Invalidators\KinstaCacheInvalidator;
use Genero\Sage\CacheTags\Invalidators\KinstaGroupCacheInvalidator;

/**
 * @covers \Genero\Sage\CacheTags\Invalidators\KinstaCacheInvalidator
 * @covers \Genero\Sage\CacheTags\Invalidators\KinstaGroupCacheInvalidator
 */
class TestKinstaInvalidators extends WP_UnitTestCase
{
    private array $urls = [
        'shop' => 'https://example.com/shop/',
        'home' => 'https://example.com/',
    ];

    public function test_default_invalidator_purges_each_url_exactly(): void
    {
        $list = (new KinstaCacheInvalidator)->purgeList($this->urls);

        $this->assertSame([
            'single|shop' => 'example.com/shop/',
            'single|home' => 'example.com/',
        ], $list);
    }

    public function test_group_invalidator_prefix_purges_paths_but_not_the_root(): void
    {
        $list = (new KinstaGroupCacheInvalidator)->purgeList($this->urls);

        $this->assertSame([
            // Prefix purge clears /shop/, /shop/page/2/, /shop/?orderby=… at once.
            'group|shop' => 'example.com/shop/',
            // The root would match the whole site, so it stays exact.
            'single|home' => 'example.com/',
        ], $list);
    }

    public function test_group_invalidator_disables_query_string_storage(): void
    {
        new KinstaGroupCacheInvalidator;

        $this->assertFalse(apply_filters('cachetags/store-query-string', true));
    }
}
