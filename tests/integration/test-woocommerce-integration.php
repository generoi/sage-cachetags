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

    public function test_an_auth_form_makes_the_page_non_cacheable(): void
    {
        $action = $this->action();
        $action->markAuthForm();

        $this->assertFalse($action->isCacheable(true), 'a rendered login/register form bails caching');
    }

    public function test_a_cart_block_on_any_page_is_not_cacheable(): void
    {
        $pageId = self::factory()->post->create([
            'post_status' => 'publish',
            'post_content' => '<!-- wp:woocommerce/cart /-->',
        ]);
        $this->go_to(get_permalink($pageId));

        $this->assertFalse($this->action()->isCacheable(true), 'block-based cart preloads per-user state');
    }

    public function test_featured_category_block_tags_its_category(): void
    {
        $cacheTags = $this->resetTags();
        $block = ['blockName' => 'woocommerce/featured-category', 'attrs' => ['categoryId' => 15]];

        $this->action()->addBlockCacheTags('', $block, new WP_Block($block));

        $this->assertContains('term:15', $cacheTags->get());
    }

    public function test_a_block_with_a_product_id_tags_that_product(): void
    {
        $cacheTags = $this->resetTags();
        $block = ['blockName' => 'woocommerce/single-product', 'attrs' => ['productId' => 20]];

        $this->action()->addBlockCacheTags('', $block, new WP_Block($block));

        $this->assertContains('post:20', $cacheTags->get());
    }

    private function purgeTags(): array
    {
        $cacheTags = CacheTags::getInstance();
        $ref = new ReflectionProperty($cacheTags, 'purgeTags');
        $ref->setAccessible(true);

        return $ref->getValue($cacheTags);
    }

    private function resetPurge(): void
    {
        $cacheTags = CacheTags::getInstance();
        $ref = new ReflectionProperty($cacheTags, 'purgeTags');
        $ref->setAccessible(true);
        $ref->setValue($cacheTags, []);
    }

    // H3: WC writes price/stock as meta via direct $wpdb (no transition_post_status),
    // so the action hooks WC's own product-change actions to purge.
    public function test_bind_registers_the_product_change_purge_hooks(): void
    {
        $action = $this->action();
        $action->bind();

        $this->assertNotFalse(has_action('woocommerce_update_product', [$action, 'onProductChange']));
        $this->assertNotFalse(has_action('woocommerce_product_set_stock', [$action, 'onProductObjectChange']));
        $this->assertNotFalse(has_action('woocommerce_variation_set_stock', [$action, 'onProductObjectChange']));
    }

    public function test_a_product_change_purges_the_product_and_its_listings(): void
    {
        $productId = self::factory()->post->create(['post_type' => 'product', 'post_status' => 'publish']);
        $this->resetPurge();

        $this->action()->onProductChange($productId);

        $tags = $this->purgeTags();
        $this->assertContains("post:{$productId}", $tags, 'the product page');
        $this->assertContains('archive:product', $tags, 'shop/category listings');
    }

    public function test_a_variation_change_purges_its_parent_product(): void
    {
        $parentId = self::factory()->post->create(['post_type' => 'product', 'post_status' => 'publish']);
        $variationId = self::factory()->post->create(['post_type' => 'product_variation', 'post_parent' => $parentId]);
        $this->resetPurge();

        $this->action()->onProductChange($variationId);

        $tags = $this->purgeTags();
        $this->assertContains("post:{$variationId}", $tags);
        $this->assertContains("post:{$parentId}", $tags, 'parent product page shows the variation price/stock');
    }
}
