<?php

use Genero\Sage\CacheTags\Actions\CacheCustomers;
use Genero\Sage\CacheTags\Actions\FacetWP;
use Genero\Sage\CacheTags\Actions\Polylang;
use Genero\Sage\CacheTags\Actions\QueryVary;
use Genero\Sage\CacheTags\Actions\WooCommerce;
use Genero\Sage\CacheTags\Actions\WPML;
use Genero\Sage\CacheTags\Bootstrap;
use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Util;

/**
 * Including known query params in the front-end + REST cache key, and the
 * actions that contribute them.
 *
 * @covers \Genero\Sage\CacheTags\Actions\QueryVary
 * @covers \Genero\Sage\CacheTags\Bootstrap::frontendUrl
 * @covers \Genero\Sage\CacheTags\Bootstrap::restUrl
 */
class TestQueryVary extends WP_UnitTestCase
{
    private Bootstrap $bootstrap;

    private array $get;

    public function set_up(): void
    {
        parent::set_up();
        $this->set_permalink_structure('/%postname%/');
        $this->bootstrap = new Bootstrap;
        $this->get = $_GET;
        global $wp;
        $wp->request = 'shop';
    }

    public function tear_down(): void
    {
        $_GET = $this->get;
        parent::tear_down();
    }

    private function invoke(string $method, ...$args)
    {
        $ref = (new ReflectionObject($this->bootstrap))->getMethod($method);
        $ref->setAccessible(true);

        return $ref->invoke($this->bootstrap, ...$args);
    }

    private function queryVary(): QueryVary
    {
        return new QueryVary(CacheTags::getInstance());
    }

    // --- Shared URL-building mechanism -------------------------------------

    public function test_front_end_url_is_path_only_by_default(): void
    {
        $_GET = ['orderby' => 'price'];

        $url = $this->invoke('frontendUrl');

        $this->assertStringEndsWith('/shop/', $url);
        $this->assertStringNotContainsString('?', $url);
    }

    public function test_front_end_url_includes_allowed_params(): void
    {
        $_GET = ['orderby' => 'price', 'junk' => 'x'];
        add_filter(Bootstrap::FILTER_ALLOWED_PARAMS, fn () => ['orderby']);

        $url = $this->invoke('frontendUrl');

        $this->assertStringEndsWith('/shop/?orderby=price', $url);
        $this->assertStringNotContainsString('junk', $url);
    }

    public function test_rest_url_keeps_allowed_params_even_when_unregistered(): void
    {
        add_filter(Bootstrap::FILTER_ALLOWED_PARAMS, fn () => ['lang']);
        $request = new WP_REST_Request('GET', '/wp/v2/posts');
        $request->set_query_params(['lang' => 'fr', 'junk' => 'x', 'page' => '2']);
        $request->set_attributes(['args' => ['page' => []]]);

        $url = $this->invoke('restUrl', $request);

        $this->assertStringEndsWith('/wp-json/wp/v2/posts?lang=fr&page=2', $url);
        $this->assertStringNotContainsString('junk', $url);
    }

    // --- QueryVary: core params, gated to listing views --------------------

    public function test_query_vary_adds_listing_params_on_listing_views(): void
    {
        $this->go_to(home_url('/?s=hello'));

        $params = $this->queryVary()->allowedParams([]);

        foreach (['s', 'orderby', 'order', 'paged'] as $param) {
            $this->assertContains($param, $params);
        }
        $this->assertNotContains('page', $params, 'pagination params are singular-only');
    }

    public function test_query_vary_adds_pagination_params_on_singular_views(): void
    {
        $postId = self::factory()->post->create(['post_status' => 'publish']);
        $this->go_to(get_permalink($postId));

        $params = $this->queryVary()->allowedParams([]);

        $this->assertContains('page', $params);
        $this->assertContains('cpage', $params);
        $this->assertNotContains('orderby', $params, 'listing params are listing-only');
    }

    // --- Integration actions contribute their own params -------------------

    public function test_polylang_action_contributes_lang(): void
    {
        $params = (new Polylang(CacheTags::getInstance()))->allowLanguageParam([]);

        $this->assertSame(['lang'], $params);
    }

    public function test_facetwp_action_is_a_passthrough_without_facetwp(): void
    {
        $params = (new FacetWP(CacheTags::getInstance()))->allowFacetParams(['existing']);

        $this->assertSame(['existing'], $params);
    }

    public function test_woocommerce_action_is_a_passthrough_without_woocommerce(): void
    {
        $action = new WooCommerce(CacheTags::getInstance());

        $this->assertSame(['existing'], $action->allowQueryParams(['existing']));
        $this->assertTrue($action->isCacheable(true), 'no opinion without WooCommerce');
    }

    public function test_woocommerce_never_caches_a_checkout_block_page(): void
    {
        $id = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_content' => '<!-- wp:woocommerce/checkout /-->',
        ]);
        $this->go_to(get_permalink($id));

        $this->assertFalse((new WooCommerce(CacheTags::getInstance()))->isCacheable(true));
    }

    public function test_woocommerce_never_caches_a_cart_shortcode_page(): void
    {
        // has_shortcode only matches registered shortcodes; WooCommerce
        // registers this one, so register it here to mirror that.
        add_shortcode('woocommerce_cart', '__return_empty_string');
        $id = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_content' => '[woocommerce_cart]',
        ]);
        $this->go_to(get_permalink($id));

        $this->assertFalse((new WooCommerce(CacheTags::getInstance()))->isCacheable(true));

        remove_shortcode('woocommerce_cart');
    }

    public function test_woocommerce_marks_pages_with_an_auth_form_non_cacheable(): void
    {
        $action = new WooCommerce(CacheTags::getInstance());
        $this->assertTrue($action->isCacheable(true));

        // Simulate a login/register/lost-password form rendering on the page.
        $action->markAuthForm();

        $this->assertFalse($action->isCacheable(true));
    }

    public function test_wpml_action_contributes_lang(): void
    {
        $params = (new WPML(CacheTags::getInstance()))->allowLanguageParam([]);

        $this->assertSame(['lang'], $params);
    }

    // --- Store-key safety --------------------------------------------------

    public function test_overlong_query_string_falls_back_to_the_base_url(): void
    {
        $_GET = ['s' => str_repeat('x', 300)];
        add_filter(Bootstrap::FILTER_ALLOWED_PARAMS, fn () => ['s']);

        $url = $this->invoke('frontendUrl');

        $this->assertStringEndsWith('/shop/', $url);
        $this->assertStringNotContainsString('?', $url, 'no truncated key is stored');
    }

    public function test_rest_url_drops_unknown_params_on_arg_less_routes(): void
    {
        $request = new WP_REST_Request('GET', '/my/v1/thing');
        $request->set_query_params(['junk' => 'x']);
        $request->set_attributes(['args' => []]);

        $url = $this->invoke('restUrl', $request);

        $this->assertStringNotContainsString('junk', $url);
    }

    public function test_non_cacheable_filter_blocks_caching(): void
    {
        $this->assertTrue(Util::isCacheableRequest());

        add_filter('cachetags/cacheable', '__return_false');

        $this->assertFalse(Util::isCacheableRequest());
    }

    public function test_admin_bar_request_is_never_cacheable(): void
    {
        add_filter('show_admin_bar', '__return_true');
        add_filter('cachetags/cacheable', '__return_true'); // even if a filter says yes

        $this->assertFalse(Util::isCacheableRequest());
    }

    public function test_logged_in_is_not_cacheable_by_default(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        add_filter('show_admin_bar', '__return_false');

        $this->assertFalse(Util::isCacheableRequest());
    }

    public function test_logged_in_can_be_opted_back_in_via_filter(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        add_filter('show_admin_bar', '__return_false');
        add_filter('cachetags/cache-logged-in', '__return_true');

        $this->assertTrue(Util::isCacheableRequest());
    }

    // --- CacheCustomers action (opt-in) ------------------------------------

    public function test_cache_customers_action_caches_subscribers(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));

        $this->assertTrue((new CacheCustomers(CacheTags::getInstance()))->cacheCustomers(false));
    }

    public function test_cache_customers_action_ignores_editors(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));

        $this->assertFalse((new CacheCustomers(CacheTags::getInstance()))->cacheCustomers(false));
    }

    public function test_cache_customers_action_makes_subscriber_requests_cacheable(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        add_filter('show_admin_bar', '__return_false');
        (new CacheCustomers(CacheTags::getInstance()))->bind();

        $this->assertTrue(Util::isCacheableRequest());
    }
}
