<?php

namespace Genero\Sage\CacheTags\Tags;

use Genero\Sage\CacheTags\Tag;
use WC_Product;
use WP_Post;

class WooCommerceTags
{
    /**
     * Return cache tags for one or multiple products.
     *
     * @param  mixed  $products
     * @return Tag[]
     */
    public static function products($products = null): array
    {
        if (is_numeric($products) || $products instanceof WP_Post || $products instanceof WC_Product) {
            $products = [$products];
        }

        if (is_array($products)) {
            return array_map(
                fn ($product) => Tag::post((int) match (true) {
                    $product instanceof WP_Post => $product->ID,
                    $product instanceof WC_Product => $product->get_id(),
                    default => $product,
                }),
                $products
            );
        }

        return [];
    }

    /**
     * @return Tag[]
     */
    public static function shop(): array
    {
        $pageId = wc_get_page_id('shop');
        if ($pageId === -1) {
            return [];
        }

        return [Tag::post($pageId)];
    }
}
