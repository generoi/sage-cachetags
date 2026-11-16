<?php

use Genero\Sage\CacheTags\Util;

/**
 * The URL a front-end page's tags are stored under.
 *
 * @covers \Genero\Sage\CacheTags\Util::currentUrl
 */
class TestStoreUrl extends WP_UnitTestCase
{
    private array $get;

    public function set_up(): void
    {
        parent::set_up();
        $this->set_permalink_structure('/%postname%/');
        $this->get = $_GET;
        global $wp;
        $wp->request = 'shop';
    }

    public function tear_down(): void
    {
        $_GET = $this->get;
        parent::tear_down();
    }

    public function test_includes_the_sorted_query_string_by_default(): void
    {
        $_GET = ['orderby' => 'price', 'colour' => 'blue'];

        $url = Util::currentUrl();

        // Sorted, so the same URL is keyed regardless of param order.
        $this->assertStringEndsWith('/shop/?colour=blue&orderby=price', $url);
    }

    public function test_no_query_string_stays_path_only(): void
    {
        $_GET = [];

        $this->assertStringEndsWith('/shop/', Util::currentUrl());
    }

    public function test_can_opt_out_to_path_only(): void
    {
        $_GET = ['orderby' => 'price'];
        add_filter('cachetags/store-query-string', '__return_false');

        $url = Util::currentUrl();

        $this->assertStringEndsWith('/shop/', $url);
        $this->assertStringNotContainsString('?', $url);
    }

    public function test_strips_tracking_and_volatile_params(): void
    {
        $_GET = ['utm_source' => 'newsletter', 'gclid' => 'x', 'fbclid' => 'y', '_' => '123'];

        $url = Util::currentUrl();

        $this->assertStringEndsWith('/shop/', $url);
        $this->assertStringNotContainsString('?', $url);
    }

    public function test_ignored_params_are_filterable(): void
    {
        $_GET = ['campaign_id' => 'spring', 'orderby' => 'price'];
        add_filter('cachetags/url-ignored-params', fn ($p) => [...$p, 'campaign_id']);

        $url = Util::currentUrl();

        $this->assertStringEndsWith('/shop/?orderby=price', $url);
        $this->assertStringNotContainsString('campaign_id', $url);
    }

    // Kinsta serves these from the bare URL's cache entry, so a render with one
    // must be stored (and purged) as the bare URL.
    public function test_params_kinsta_leaves_out_of_its_cache_key_map_to_the_bare_url(): void
    {
        add_filter('cachetags/store-query-string', '__return_false');

        foreach (['_ga', 'mc_cid', 'mc_eid', 'ref', 'fbclid', 'dclid', 'utm_campaign'] as $param) {
            $_GET = [$param => 'x'];

            $this->assertFalse(Util::isQueryStringDropped(), $param);
            $this->assertStringEndsWith('/shop/', Util::currentUrl(), $param);
        }
    }

    public function test_a_query_string_is_dropped_only_when_storage_would_drop_it(): void
    {
        $_GET = ['s' => 'shoes'];
        $this->assertFalse(Util::isQueryStringDropped(), 'stored with its query string');

        add_filter('cachetags/store-query-string', '__return_false');
        $this->assertTrue(Util::isQueryStringDropped(), 'storage off');

        $_GET = [];
        $this->assertFalse(Util::isQueryStringDropped(), 'no query string');
    }

    // Tracking params render the same page, so they still map to the bare URL.
    public function test_ignored_params_alone_are_not_a_dropped_query_string(): void
    {
        add_filter('cachetags/store-query-string', '__return_false');
        $_GET = ['utm_source' => 'newsletter', 'gclid' => 'x'];

        $this->assertFalse(Util::isQueryStringDropped());
    }

    // With storage on the edge may cache query strings (SiteGround); an
    // overlong one keeps falling back to the bare URL's row, as before.
    public function test_an_overlong_query_string_is_dropped_only_with_storage_off(): void
    {
        $_GET = ['q' => str_repeat('a', 300)];
        $this->assertFalse(Util::isQueryStringDropped(), 'storage on');

        add_filter('cachetags/store-query-string', '__return_false');
        $this->assertTrue(Util::isQueryStringDropped(), 'storage off');
    }

    public function test_overlong_query_string_falls_back_to_the_path(): void
    {
        $_GET = ['q' => str_repeat('a', 300)];

        $url = Util::currentUrl();

        $this->assertStringEndsWith('/shop/', $url, 'a key over the varchar(191) column falls back to the path');
        $this->assertStringNotContainsString('?', $url);
    }
}
