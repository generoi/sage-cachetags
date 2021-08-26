<?php

namespace Genero\Sage\CacheTags\Tags;

use WC_Product;
use WP_Post;

class WooCommerceTags
{
    /**
     * Return cache tags for one or multiple products.
     *
     * @param mixed $products
     */
    public static function products($products = null): array
    {
        if (is_numeric($products) || $products instanceof WP_Post || $products instanceof WC_Product) {
            $products = [$products];
        }

        if (is_array($products)) {
            return collect($products)
                // Pluck the IDs if it's a list of objects.
                ->map(fn ($product) => $product instanceof WP_Post ? $product->ID : $product)
                ->map(fn ($product) => $product instanceof WC_Product ? $product->get_id() : $product)
                ->map(fn ($productId) => ["post:$productId"])
                ->flatten()
                ->all();
        }

        return [];
    }
}
