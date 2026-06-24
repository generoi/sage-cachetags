<?php

use Genero\Sage\CacheTags\Tags\GravityformTags;
use Genero\Sage\CacheTags\Tags\SiteTags;
use Genero\Sage\CacheTags\Tags\WooCommerceTags;

/**
 * The tag "vocabulary" classes — the strings these build are what gets purged,
 * so the per-shape branches matter.
 *
 * @covers \Genero\Sage\CacheTags\Tags\GravityformTags
 * @covers \Genero\Sage\CacheTags\Tags\SiteTags
 * @covers \Genero\Sage\CacheTags\Tags\WooCommerceTags
 */
class TestTags extends WP_UnitTestCase
{
    public function test_gravityform_forms_from_id_object_array_and_null(): void
    {
        $this->assertSame(['gform:5'], GravityformTags::forms(5));
        $this->assertSame(['gform:5'], GravityformTags::forms(['id' => 5]));
        $this->assertSame(['gform:1', 'gform:2'], GravityformTags::forms([1, 2]));
        $this->assertSame([], GravityformTags::forms(null));
    }

    public function test_site_tags_for_current_explicit_and_unhandled_input(): void
    {
        $current = get_current_blog_id();

        $this->assertSame(["site:{$current}"], SiteTags::sites(), 'null → current site');
        $this->assertSame(['site:7'], SiteTags::sites(7));
        $this->assertSame(['site:3', 'site:4'], SiteTags::sites([3, 4]));
        // 'any' on a single-site install is just the current site.
        $this->assertSame(["site:{$current}"], SiteTags::sites('any'));
        $this->assertSame([], SiteTags::sites(new stdClass));
    }

    public function test_woocommerce_products_from_id_array_post_and_null(): void
    {
        $this->assertSame(['post:10'], WooCommerceTags::products(10));
        $this->assertSame(['post:10', 'post:11'], WooCommerceTags::products([10, 11]));
        $this->assertSame([], WooCommerceTags::products(null));

        $post = self::factory()->post->create_and_get();
        $this->assertSame(["post:{$post->ID}"], WooCommerceTags::products($post));
    }

    public function test_woocommerce_shop_tag_with_and_without_a_shop_page(): void
    {
        if (! function_exists('wc_get_page_id')) {
            $this->markTestSkipped('WooCommerce is not installed.');
        }

        $shopId = self::factory()->post->create();
        update_option('woocommerce_shop_page_id', $shopId);
        $this->assertSame(["post:{$shopId}"], WooCommerceTags::shop());

        delete_option('woocommerce_shop_page_id');
        $this->assertSame([], WooCommerceTags::shop(), 'no shop page → no tag');
    }
}
