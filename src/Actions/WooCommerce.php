<?php

namespace Genero\Sage\CacheTags\Actions;

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
