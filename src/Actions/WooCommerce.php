<?php

namespace Genero\Sage\CacheTags\Actions;

use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Contracts\Action;
use Genero\Sage\CacheTags\Tags\CoreTags;
use Genero\Sage\CacheTags\Tags\WooCommerceTags;
use WP_Block;
use WP_Post;

class WooCommerce implements Action
{
    /**
     * Written meta props that can't change which products a listing selects or how
     * it orders them. Anything not named here purges the listings, so a new prop
     * over-purges rather than going stale.
     */
    const NON_LISTING_META_PROPS = [
        'sku', 'global_unique_id', 'tax_status', 'tax_class', 'manage_stock',
        'stock_quantity', 'backorders', 'low_stock_amount', 'sold_individually',
        'weight', 'length', 'width', 'height', 'upsell_ids', 'cross_sell_ids',
        'purchase_note', 'default_attributes', 'virtual', 'downloadable',
        'shipping_class_id', 'downloads', 'image_id', 'gallery_image_ids',
        'download_limit', 'download_expiry', 'cogs_value',
    ];

    /**
     * Post fields a listing can order by. They aren't reported as written meta.
     */
    const LISTING_POST_PROPS = ['name', 'menu_order', 'date_created'];

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
        \add_action('woocommerce_product_read', [$this, 'onProductRead']);
        \add_action('woocommerce_before_product_object_save', [$this, 'onBeforeProductSave']);
        \add_action('woocommerce_product_object_updated_props', [$this, 'onProductPropsUpdated'], 10, 2);
        \add_action('post_updated', [$this, 'onProductPostUpdated'], 10, 3);
        \add_action('woocommerce_update_product', [$this, 'onProductSave']);
        \add_action('woocommerce_update_product_variation', [$this, 'onProductSave']);
        \add_action('woocommerce_product_set_stock_status', [$this, 'onProductChange']);
        \add_action('woocommerce_variation_set_stock_status', [$this, 'onProductChange']);
        \add_action('woocommerce_product_set_stock', [$this, 'onProductObjectChange']);
        \add_action('woocommerce_variation_set_stock', [$this, 'onProductObjectChange']);
        \add_action('set_object_terms', [$this, 'onProductTermsSet'], 10, 6);
    }

    /**
     * Tag every product loaded while rendering. WooCommerce loads one for each
     * post in a loop, so this covers listing cards in any theme, and a change to a
     * product reaches the pages showing it through `post:{id}`. Imports and other
     * bulk work load thousands of products and render nothing, so they're skipped.
     */
    public function onProductRead($productId): void
    {
        if (is_admin() || wp_doing_cron() || (defined('WP_CLI') && WP_CLI)) {
            return;
        }

        $this->cacheTags->add(CoreTags::posts((int) $productId));
    }

    /**
     * The wp-admin form saves the title and date through wp_update_post, so they
     * never show up in the product's changes.
     */
    public function onProductPostUpdated(int $postId, WP_Post $after, WP_Post $before): void
    {
        if ($after->post_type !== 'product') {
            return;
        }

        if ($after->post_title !== $before->post_title || $after->post_date !== $before->post_date || $after->menu_order !== $before->menu_order) {
            $this->cacheTags->clear(CoreTags::archive('product'));
        }
    }

    /**
     * Listings show a product through its own tag, so a save purges that. Pages
     * over the header budget collapse their post tags to the coarse `:any` tag.
     */
    public function onProductSave($productId): void
    {
        $this->clearProduct((int) $productId, false);
        $this->cacheTags->clear(CoreTags::anyArchive('product'));
    }

    /**
     * A listing the product isn't on yet only needs purging if the save can change
     * which products it selects or how it orders them. WooCommerce reports only the
     * meta it actually wrote, after applying sale dates to the price. Get_changes()
     * can't be used for this: an admin save always lists attributes and dates.
     *
     * @param  mixed  $product  WC_Product
     * @param  string[]  $updatedProps
     */
    public function onProductPropsUpdated($product, array $updatedProps): void
    {
        if (array_diff($updatedProps, self::NON_LISTING_META_PROPS)) {
            $this->cacheTags->clear(CoreTags::archive('product'));
        }
    }

    /**
     * Runs before the save, which resets the changes.
     *
     * @param  mixed  $product  WC_Product
     */
    public function onBeforeProductSave($product): void
    {
        if ($product->get_id() && array_intersect_key($product->get_changes(), array_flip(self::LISTING_POST_PROPS))) {
            $this->cacheTags->clear(CoreTags::archive('product'));
        }
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
