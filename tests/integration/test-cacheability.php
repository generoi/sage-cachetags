<?php

use Genero\Sage\CacheTags\Actions\CacheCustomers;
use Genero\Sage\CacheTags\Actions\WooCommerce;
use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Util;

/**
 * Cacheability gating: which front-end requests may be stored in a shared
 * cache, and the per-integration vetoes / opt-ins.
 *
 * @covers \Genero\Sage\CacheTags\Util::isCacheableRequest
 * @covers \Genero\Sage\CacheTags\Actions\CacheCustomers
 * @covers \Genero\Sage\CacheTags\Actions\WooCommerce
 */
class TestCacheability extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        $this->set_permalink_structure('/%postname%/');
    }

    // --- Util::isCacheableRequest ------------------------------------------

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
        add_filter('cachetags/cacheable', '__return_true', 5);

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

    // --- WooCommerce vetoes ------------------------------------------------

    public function test_woocommerce_has_no_opinion_without_woocommerce(): void
    {
        $this->assertTrue((new WooCommerce(CacheTags::getInstance()))->isCacheable(true));
    }

    public function test_woocommerce_marks_pages_with_an_auth_form_non_cacheable(): void
    {
        $action = new WooCommerce(CacheTags::getInstance());
        $this->assertTrue($action->isCacheable(true));

        $action->markAuthForm();

        $this->assertFalse($action->isCacheable(true));
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
}
