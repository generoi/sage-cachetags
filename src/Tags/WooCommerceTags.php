<?php

namespace Genero\Sage\CacheTags\Tags;

use Genero\Sage\CacheTags\Util;
use WC_Product;
use WP_Post;

class WooCommerceTags
{
    /**
     * Return cache tags for one or multiple products.
     *
     * @param  mixed  $products
     * @return string[]
     */
    public static function products($products = null): array
    {
        if (is_numeric($products) || $products instanceof WP_Post || $products instanceof WC_Product) {
            $products = [$products];
        }

        if (is_array($products)) {
            $tags = array_map(
                function ($product) {
                    $id = match (true) {
                        $product instanceof WP_Post => $product->ID,
                        $product instanceof WC_Product => $product->get_id(),
                        default => $product
                    };

                    return [sprintf('post:%d', $id)];
                },
                $products
            );

            return Util::flatten($tags);
        }

        return [];
    }

    /**
     * @return string[]
     */
    public static function shop(): array
    {
        $pageId = wc_get_page_id('shop');
        if ($pageId === -1) {
            return [];
        }

        return ["post:$pageId"];
    }
}
