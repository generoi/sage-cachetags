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

    // With storage on, the query string is part of the stored URL anyway.
    public function test_nothing_is_skipped_with_query_string_storage_on(): void
    {
        $_GET = ['s' => 'shoes', 'orderby' => 'price'];

        $this->assertFalse(Util::isUnstoredQueryVariant());
    }

    public function test_render_changing_params_are_not_stored_with_storage_off(): void
    {
        add_filter('cachetags/store-query-string', '__return_false');

        foreach ([['s' => 'shoes'], ['orderby' => 'price'], ['paged' => '2'], ['cat' => '5'], ['filter_color' => 'red'], ['min_price' => '10'], ['fwp_size' => 'm'], ['utm_source' => 'x', 'orderby' => 'price']] as $get) {
            $_GET = $get;

            $this->assertTrue(Util::isUnstoredQueryVariant(), http_build_query($get));
        }
    }

    // An unknown param fails toward storing: it renders the bare URL's page and
    // the edge may serve it from the bare URL's entry, which must stay purgeable.
    public function test_unknown_and_tracking_params_are_stored_as_the_bare_url(): void
    {
        add_filter('cachetags/store-query-string', '__return_false');

        foreach (['_kx', 'mtm_campaign', 'pk_source', '_hsenc', 'ttclid', 'igshid', 'yclid', 'utm_source', 'gclid', 'q'] as $param) {
            $_GET = [$param => 'x'];

            $this->assertFalse(Util::isUnstoredQueryVariant(), $param);
            $this->assertStringEndsWith('/shop/', Util::currentUrl(), $param);
        }
    }

    public function test_render_params_are_filterable(): void
    {
        add_filter('cachetags/store-query-string', '__return_false');
        add_filter('cachetags/render-params', fn ($params) => [...$params, 'view']);
        $_GET = ['view' => 'grid'];

        $this->assertTrue(Util::isUnstoredQueryVariant());
    }

    public function test_overlong_query_string_falls_back_to_the_path(): void
    {
        $_GET = ['q' => str_repeat('a', 300)];

        $url = Util::currentUrl();

        $this->assertStringEndsWith('/shop/', $url, 'a key over the varchar(191) column falls back to the path');
        $this->assertStringNotContainsString('?', $url);
    }
}
