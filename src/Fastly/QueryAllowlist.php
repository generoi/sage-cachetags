<?php

namespace Genero\Sage\CacheTags\Fastly;

/**
 * Computes the set of query-string parameter names that are *cache-significant*
 * for this site — the ones an edge must keep in the cache key. Everything else
 * (utm_*, gclid, fbclid, random bot/session junk) can be stripped at the edge so
 * variants collapse to one cached object.
 *
 * This is the dynamic half of the edge query-param normalisation: WordPress knows
 * which params matter (WooCommerce attributes, FacetWP facets, search), the edge
 * doesn't. The list is pushed to a Fastly Edge Dictionary and a static VCL snippet
 * filters the query string by it — see AllowlistDictionary and the README.
 *
 * Best-effort: the built-ins below cover the common cases, but a site MUST review
 * the result (`wp cachetags fastly-allowlist preview`) and add anything missing
 * via the `cachetags/fastly-allowed-query-params` filter. An incomplete list
 * silently collapses real variants into one cached page — fail-dangerous.
 */
class QueryAllowlist
{
    const FILTER = 'cachetags/fastly-allowed-query-params';

    /**
     * @return string[] sorted, de-duplicated param names
     */
    public static function collect(): array
    {
        $params = ['s', 'orderby', 'order', 'paged', 'page'];

        $params = array_merge($params, self::wooCommerce(), self::facetwp());

        /**
         * Final say over the allowlist — add a site's bespoke params, or remove
         * one the built-ins got wrong. Receives and returns string[].
         */
        $params = (array) apply_filters(self::FILTER, $params);

        $params = array_values(array_unique(array_filter(array_map('strval', $params))));
        sort($params);

        return $params;
    }

    /**
     * WooCommerce archive/shop filtering params, including the layered-nav widget
     * params for each registered product attribute (`filter_{slug}` +
     * `query_type_{slug}`).
     *
     * @return string[]
     */
    protected static function wooCommerce(): array
    {
        if (! function_exists('wc_get_attribute_taxonomies')) {
            return [];
        }

        $params = ['min_price', 'max_price', 'rating_filter', 'product_cat', 'product_tag'];

        foreach (wc_get_attribute_taxonomies() as $attribute) {
            $slug = $attribute->attribute_name;
            $params[] = "filter_{$slug}";
            $params[] = "query_type_{$slug}";
        }

        return $params;
    }

    /**
     * FacetWP facet query vars (each facet reads `?{name}=…`).
     *
     * @return string[]
     */
    protected static function facetwp(): array
    {
        if (! function_exists('FWP') || ! is_object(FWP()->helper ?? null)) {
            return [];
        }

        return array_map(
            fn ($facet) => (string) ($facet['name'] ?? ''),
            (array) FWP()->helper->get_facets()
        );
    }
}
