<?php

namespace Genero\Sage\CacheTags\Actions;

use Genero\Sage\CacheTags\Bootstrap;
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
        \add_filter(Bootstrap::FILTER_ALLOWED_PARAMS, [$this, 'allowQueryParams']);
        \add_filter('cachetags/cacheable', [$this, 'isCacheable']);

        // Auth forms can be embedded on any page (a login widget, a
        // [woocommerce_my_account] shortcode), not just My Account — flag them
        // as they render so isCacheable() can bail.
        foreach (['woocommerce_login_form_start', 'woocommerce_register_form_start', 'woocommerce_lostpassword_form'] as $hook) {
            \add_action($hook, [$this, 'markAuthForm']);
        }
    }

    public function markAuthForm(): void
    {
        $this->hasAuthForm = true;
    }

    /**
     * Vary the cache key by WooCommerce shop sorting/filtering params (price,
     * rating, layered-nav attributes, review pagination), which are
     * query-string based and rendered server-side.
     *
     * @param  string[]  $params
     * @return string[]
     */
    public function allowQueryParams(array $params): array
    {
        if (! function_exists('is_woocommerce') || ! is_woocommerce()) {
            return $params;
        }

        $params = [...$params, 'orderby', 'min_price', 'max_price', 'rating_filter', 'product-page'];

        // Layered-nav filters on archives use global (taxonomy) attributes:
        // filter_<attr> + query_type_<attr>. Local attributes can't drive
        // layered nav, so the global taxonomy list is complete here.
        if (function_exists('wc_get_attribute_taxonomies')) {
            foreach (wc_get_attribute_taxonomies() as $attribute) {
                $params[] = 'filter_'.$attribute->attribute_name;
                $params[] = 'query_type_'.$attribute->attribute_name;
            }
        }

        // Variation selection on a single variable product. Enumerate the
        // product's own variation attributes — this covers both global (pa_*)
        // and custom/local attributes, with the exact attribute_<slug> param
        // the variation form uses. No global list needed.
        $params = [...$params, ...$this->variationParams()];

        return array_values(array_unique($params));
    }

    /**
     * attribute_<slug> params for the current variable product's variation
     * attributes (global and local alike).
     *
     * @return string[]
     */
    protected function variationParams(): array
    {
        if (! function_exists('is_product') || ! is_product()) {
            return [];
        }

        $product = wc_get_product();
        if (! $product || ! $product->is_type('variable')) {
            return [];
        }

        return array_map(
            fn ($attribute) => 'attribute_'.sanitize_title($attribute),
            array_keys($product->get_variation_attributes())
        );
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

        if ($this->hasAuthForm) {
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
