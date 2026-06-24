<?php

use Genero\Sage\CacheTags\Actions\WooCommerce;
use Genero\Sage\CacheTags\CacheTags;

/**
 * Real integration against an installed WooCommerce: exercises the action's
 * cacheability vetoes and tagging through WC's actual conditional tags and pages.
 *
 * @covers \Genero\Sage\CacheTags\Actions\WooCommerce
 */
class TestWooCommerceIntegration extends WP_UnitTestCase
{
    private array $get;

    public function set_up(): void
    {
        parent::set_up();
        if (! class_exists('WooCommerce')) {
            $this->markTestSkipped('WooCommerce is not installed in this environment.');
        }
        $this->set_permalink_structure('/%postname%/');
        $this->get = $_GET;
    }

    public function tear_down(): void
    {
        $_GET = $this->get;
        parent::tear_down();
    }

    private function action(): WooCommerce
    {
        return new WooCommerce(CacheTags::getInstance());
    }

    private function resetTags(): CacheTags
    {
        $cacheTags = CacheTags::getInstance();
        $ref = new ReflectionProperty($cacheTags, 'cacheTags');
        $ref->setAccessible(true);
        $ref->setValue($cacheTags, []);

        return $cacheTags;
    }

    /**
     * Create a WooCommerce page, point its option at it, and navigate there so
     * the matching conditional tag (is_cart/is_checkout/…) is true.
     */
    private function goToWooPage(string $option, string $content = ''): int
    {
        $id = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_content' => $content,
        ]);
        update_option($option, $id);
        $this->go_to(get_permalink($id));

        return $id;
    }

    public function test_cart_page_is_not_cacheable(): void
    {
        $this->goToWooPage('woocommerce_cart_page_id');

        $this->assertTrue(is_cart());
        $this->assertFalse($this->action()->isCacheable(true));
    }

    public function test_checkout_page_is_not_cacheable(): void
    {
        $this->goToWooPage('woocommerce_checkout_page_id');

        $this->assertTrue(is_checkout());
        $this->assertFalse($this->action()->isCacheable(true));
    }

    public function test_account_page_is_not_cacheable(): void
    {
        $this->goToWooPage('woocommerce_myaccount_page_id');

        $this->assertTrue(is_account_page());
        $this->assertFalse($this->action()->isCacheable(true));
    }

    public function test_add_to_cart_request_is_not_cacheable(): void
    {
        $this->go_to(home_url('/'));
        $_GET['add-to-cart'] = '5';

        $this->assertFalse($this->action()->isCacheable(true));
    }

    public function test_shop_archive_is_tagged_with_its_page(): void
    {
        $shopId = $this->goToWooPage('woocommerce_shop_page_id');
        $this->assertTrue(is_shop());

        $cacheTags = $this->resetTags();
        $this->action()->addTemplateCacheTags();

        $this->assertContains("post:{$shopId}", $cacheTags->get());
    }

    public function test_product_collection_block_tags_the_product_archive(): void
    {
        $cacheTags = $this->resetTags();
        $block = ['blockName' => 'woocommerce/product-collection', 'attrs' => []];

        $this->action()->addBlockCacheTags('', $block, new WP_Block($block));

        $this->assertContains('archive:product', $cacheTags->get());
    }
}
