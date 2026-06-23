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
    public function __construct(protected CacheTags $cacheTags) {}

    public function bind(): void
    {
        \add_filter('template_redirect', [$this, 'addTemplateCacheTags']);
        \add_filter('render_block', [$this, 'addBlockCacheTags'], 10, 3);
        \add_filter(Bootstrap::FILTER_ALLOWED_PARAMS, [$this, 'allowQueryParams']);
        \add_filter('cachetags/cacheable', [$this, 'isCacheable']);
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

        // Layered-nav attribute filters: filter_<attr> + query_type_<attr>.
        if (function_exists('wc_get_attribute_taxonomies')) {
            foreach (wc_get_attribute_taxonomies() as $attribute) {
                $params[] = 'filter_'.$attribute->attribute_name;
                $params[] = 'query_type_'.$attribute->attribute_name;
            }
        }

        return $params;
    }

    /**
     * Cart, checkout, account and add-to-cart requests are per-session and
     * mutate state — they must not be publicly cached.
     */
    public function isCacheable(bool $cacheable): bool
    {
        if (! $cacheable || ! function_exists('is_cart')) {
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
