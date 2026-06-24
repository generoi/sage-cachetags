<?php

namespace Genero\Sage\CacheTags\Actions;

use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Contracts\Action;
use Genero\Sage\CacheTags\Tags\CoreTags;
use Genero\Sage\CacheTags\Tags\WooCommerceTags;
use WP_Block;

class WooCommerce implements Action
{
    /**
     * Set when a login/register/lost-password form renders on the page (these
     * carry per-session nonces, so the page must not be publicly cached).
     */
    protected bool $hasAuthForm = false;

    public function __construct(protected CacheTags $cacheTags) {}

    public function bind(): void
    {
        \add_filter('template_redirect', [$this, 'addTemplateCacheTags']);
        \add_filter('render_block', [$this, 'addBlockCacheTags'], 10, 3);
        \add_filter('cachetags/cacheable', [$this, 'isCacheable']);

        // Auth forms can be embedded on any page (a login widget, a
        // [woocommerce_my_account] shortcode), not just My Account — flag them
        // as they render so isCacheable() can bail.
        foreach (['woocommerce_login_form_start', 'woocommerce_register_form_start', 'woocommerce_lostpassword_form'] as $hook) {
            \add_action($hook, [$this, 'markAuthForm']);
        }

        // WooCommerce writes price/stock/sale as post meta via a direct $wpdb
        // update (no transition_post_status), so Core never purges product pages
        // on stock reductions from orders, scheduled sales, REST/CRUD price
        // edits, or variation changes. Hook WC's own product-change actions.
        \add_action('woocommerce_update_product', [$this, 'onProductChange']);
        \add_action('woocommerce_update_product_variation', [$this, 'onProductChange']);
        \add_action('woocommerce_product_set_stock_status', [$this, 'onProductChange']);
        \add_action('woocommerce_variation_set_stock_status', [$this, 'onProductChange']);
        \add_action('woocommerce_product_set_stock', [$this, 'onProductObjectChange']);
        \add_action('woocommerce_variation_set_stock', [$this, 'onProductObjectChange']);
    }

    /**
     * Purge a product (and the listings that show it) on a price/stock/sale
     * change. Accepts a product id (most WC product actions pass one first).
     */
    public function onProductChange($productId): void
    {
        $this->clearProduct((int) $productId);
    }

    /**
     * woocommerce_{product,variation}_set_stock pass the product object.
     *
     * @param  mixed  $product  WC_Product
     */
    public function onProductObjectChange($product): void
    {
        if (is_object($product) && method_exists($product, 'get_id')) {
            $this->clearProduct((int) $product->get_id());
        }
    }

    protected function clearProduct(int $productId): void
    {
        if (! $productId) {
            return;
        }

        // A variation's price/stock surfaces on its parent product page and in
        // listings, so purge the parent too.
        $parentId = wp_get_post_parent_id($productId);
        $productIds = $parentId ? [$productId, $parentId] : [$productId];

        $this->cacheTags->clear([
            ...CoreTags::posts($productIds),
            // Shop/category/related listings show the product card (price/stock).
            ...CoreTags::archive('product'),
            ...CoreTags::anyArchive('product'),
        ]);
    }

    public function markAuthForm(): void
    {
        $this->hasAuthForm = true;
    }

    /**
     * Cart, checkout, account, add-to-cart and pages rendering an auth form are
     * per-session / state-mutating — they must not be publicly cached.
     */
    public function isCacheable(bool $cacheable): bool
    {
        if (! $cacheable) {
            return false;
        }

        if ($this->hasAuthForm || $this->hasCartCheckoutContent()) {
            return false;
        }

        if (! function_exists('is_cart')) {
            return $cacheable;
        }

        if (is_cart() || is_checkout() || is_account_page()) {
            return false;
        }

        return ! isset($_GET['add-to-cart']) && ! isset($_GET['wc-ajax']);
    }

    /**
     * Whether the current page renders the cart or checkout — as the designated
     * pages (caught by is_cart/is_checkout), or via the block/shortcode placed
     * on any page. Both render per-user cart state (the block preloads the Store
     * API cart into the HTML), so the page must never be publicly cached, block
     * or classic alike.
     */
    protected function hasCartCheckoutContent(): bool
    {
        $post = get_post();
        if (! $post) {
            return false;
        }

        return has_block('woocommerce/cart', $post)
            || has_block('woocommerce/checkout', $post)
            || has_shortcode($post->post_content, 'woocommerce_cart')
            || has_shortcode($post->post_content, 'woocommerce_checkout');
    }

    public function addTemplateCacheTags(): void
    {
        switch (true) {
            case function_exists('is_shop') && is_shop():
                $this->cacheTags->add([
                    ...WooCommerceTags::shop(),
                ]);
                break;
        }
    }

    /**
     * @param  array<string, mixed>  $block  WordPress block array
     */
    public function addBlockCacheTags(string $content, array $block, WP_Block $instance): string
    {
        $attributes = $block['attrs'] ?? [];

        $tags = [];
        if (! empty($attributes['productId'])) {
            $tags[] = WooCommerceTags::products($attributes['productId']);
        }

        switch ($block['blockName']) {
            case 'woocommerce/featured-category':
                $tags[] = CoreTags::terms($attributes['categoryId'] ?? null);
                break;

            case 'woocommerce/product-collection':
                $tags[] = CoreTags::archive('product');
                break;

            case 'woocommerce/all-reviews':
            case 'woocommerce/reviews-by-category':
            case 'woocommerce/reviews-by-product':
                // Retrieved by REST API
                break;
            case 'woocommerce/single-product':
            case 'woocommerce/featured-product':
                // Taken care of by general `productId`
                break;
        }

        $this->cacheTags->add($tags);

        return $content;
    }
}
