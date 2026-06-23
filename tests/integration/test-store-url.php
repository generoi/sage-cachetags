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

    public function test_is_path_only_by_default(): void
    {
        $_GET = ['orderby' => 'price'];

        $url = Util::currentUrl();

        $this->assertStringEndsWith('/shop/', $url);
        $this->assertStringNotContainsString('?', $url);
    }

    public function test_includes_sorted_query_when_opted_in(): void
    {
        $_GET = ['orderby' => 'price', 'colour' => 'blue'];
        add_filter('cachetags/store-query-string', '__return_true');

        $url = Util::currentUrl();

        // Sorted, so the same URL is keyed regardless of param order.
        $this->assertStringEndsWith('/shop/?colour=blue&orderby=price', $url);
    }

    public function test_strips_tracking_params_when_opted_in(): void
    {
        $_GET = ['utm_source' => 'newsletter', 'gclid' => 'x', 'fbclid' => 'y', '_' => '123'];
        add_filter('cachetags/store-query-string', '__return_true');

        $url = Util::currentUrl();

        // Only tracking/volatile params present → nothing keyworthy left.
        $this->assertStringEndsWith('/shop/', $url);
        $this->assertStringNotContainsString('?', $url);
    }

    public function test_ignored_params_are_filterable(): void
    {
        $_GET = ['ref' => 'campaign', 'orderby' => 'price'];
        add_filter('cachetags/store-query-string', '__return_true');
        add_filter('cachetags/url-ignored-params', fn ($p) => [...$p, 'ref']);

        $url = Util::currentUrl();

        $this->assertStringEndsWith('/shop/?orderby=price', $url);
        $this->assertStringNotContainsString('ref', $url);
    }
}
