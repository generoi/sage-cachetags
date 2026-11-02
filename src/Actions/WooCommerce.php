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
        \add_action('woocommerce_update_product', [$this, 'onProductSave']);
        \add_action('woocommerce_update_product_variation', [$this, 'onProductSave']);
        \add_action('woocommerce_product_set_stock_status', [$this, 'onProductChange']);
        \add_action('woocommerce_variation_set_stock_status', [$this, 'onProductChange']);
        \add_action('woocommerce_product_set_stock', [$this, 'onProductObjectChange']);
        \add_action('woocommerce_variation_set_stock', [$this, 'onProductObjectChange']);
        \add_action('set_object_terms', [$this, 'onProductTermsSet'], 10, 6);
    }

    /**
     * A general save changes the listing card, so purge the product page AND the
     * listings. A theme that tags every card it renders with its `post:{id}` can
     * opt out: those cards are already purged through their own tag, and only
     * membership changes (see below) still need the listings cleared.
     */
    public function onProductSave($productId): void
    {
        if (apply_filters('cachetags/woocommerce-save-purges-listings', true)) {
            $this->clearProduct((int) $productId, true);

            return;
        }

        $this->clearProduct((int) $productId, false);
        // Pages over the header budget collapse their post tags to this coarse tag.
        $this->cacheTags->clear(CoreTags::anyArchive('product'));
    }

    /**
     * A stock-status flip (in↔out) changes the card and can add or remove the
     * product from a listing. Accepts a product id (most WC product actions pass
     * one first).
     */
    public function onProductChange($productId): void
    {
        $this->clearProduct((int) $productId, true);
    }

    /**
     * A product moving between terms joins or leaves listings it isn't tagged on
     * yet. WooCommerce re-sets a product's terms on every save, so only an actual
     * change counts.
     *
     * @param  array<int|string>  $terms
     * @param  int[]  $taxonomyIds
     * @param  int[]  $oldTaxonomyIds
     */
    public function onProductTermsSet(int $objectId, array $terms, array $taxonomyIds, string $taxonomy, bool $append, array $oldTaxonomyIds): void
    {
        if (get_post_type($objectId) !== 'product') {
            return;
        }

        $new = array_map('intval', $taxonomyIds);
        $old = array_map('intval', $oldTaxonomyIds);
        sort($new);
        sort($old);

        if ($new !== $old) {
            $this->clearProduct($objectId, true);
        }
    }

    /**
     * woocommerce_{product,variation}_set_stock fires on every stock-quantity
     * change — e.g. each order. The product page may show the exact count, but
     * the listing card shows only in/out status (handled by the *_set_stock_status
     * hooks), so don't purge the whole archive on every decrement.
     *
     * @param  mixed  $product  WC_Product
     */
    public function onProductObjectChange($product): void
    {
        if (is_object($product) && method_exists($product, 'get_id')) {
            $this->clearProduct((int) $product->get_id(), false);
        }
    }

    protected function clearProduct(int $productId, bool $includeListings): void
    {
        if (! $productId) {
            return;
        }

        // A variation's price/stock surfaces on its parent product page and in
        // listings, so purge the parent too.
        $parentId = wp_get_post_parent_id($productId);
        $productIds = $parentId ? [$productId, $parentId] : [$productId];

        $tags = CoreTags::posts($productIds);

        if ($includeListings) {
            // Shop/category/related listings show the product card (price/status).
            $tags = [
                ...$tags,
                ...CoreTags::archive('product'),
                ...CoreTags::anyArchive('product'),
            ];
        }

        $this->cacheTags->clear($tags);
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
                $this->cacheTags->add(WooCommerceTags::shop());
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
