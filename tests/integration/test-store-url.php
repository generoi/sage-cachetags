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
        $_GET = ['ref' => 'campaign', 'orderby' => 'price'];
        add_filter('cachetags/url-ignored-params', fn ($p) => [...$p, 'ref']);

        $url = Util::currentUrl();

        $this->assertStringEndsWith('/shop/?orderby=price', $url);
        $this->assertStringNotContainsString('ref', $url);
    }

    public function test_overlong_query_string_falls_back_to_the_path(): void
    {
        $_GET = ['q' => str_repeat('a', 300)];

        $url = Util::currentUrl();

        $this->assertStringEndsWith('/shop/', $url, 'a key over the varchar(191) column falls back to the path');
        $this->assertStringNotContainsString('?', $url);
    }
}
