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
        $params = array_merge(self::core(), self::wooCommerce(), self::facetwp(), self::polylang());

        /**
         * Final say over the allowlist — add a site's bespoke params, or remove
         * one the built-ins got wrong. Receives and returns string[].
         */
        $params = (array) apply_filters(self::FILTER, $params);

        // Reject names that aren't header/cache-key safe: a comma would corrupt the
        // comma-joined dictionary value (and the VCL's filtersep split), whitespace
        // or control chars would break the edge filter. Mirrors Util::isValidTag.
        $params = array_filter(
            array_map('strval', $params),
            fn ($param) => (bool) preg_match('/^[A-Za-z0-9_\-]+$/', $param)
        );

        $params = array_values(array_unique($params));
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
    /**
     * WordPress's content-determining query vars — the built-ins plus anything a
     * theme/plugin registers via the `query_vars` filter, so custom routable params
     * aren't silently stripped. These all select content, so allowlisting them is
     * safe; high-cardinality noise (trackers) is never a registered query var.
     *
     * @return string[]
     */
    protected static function core(): array
    {
        $builtins = [
            's', 'post_type', 'orderby', 'order', 'paged', 'page', 'cpage',
            'p', 'page_id', 'name', 'pagename', 'cat', 'category_name', 'tag',
            'author', 'author_name', 'feed', 'm', 'year', 'monthnum', 'day',
        ];

        return array_map('strval', (array) apply_filters('query_vars', $builtins));
    }

    protected static function wooCommerce(): array
    {
        return function_exists('wc_get_attribute_taxonomies')
            ? self::wooCommerceParams(wc_get_attribute_taxonomies())
            : [];
    }

    /**
     * WooCommerce archive params for the given attribute taxonomies. Pure (no WC
     * calls) so it's testable without WooCommerce loaded.
     *
     * @param  object[]  $taxonomies  objects with an `attribute_name` (un-prefixed slug)
     * @return string[]
     */
    public static function wooCommerceParams(array $taxonomies): array
    {
        // post_type keeps product search (?s=…&post_type=product) distinct from
        // blog search; product-page is the products-block pager.
        $params = [
            'post_type', 'product-page',
            'min_price', 'max_price', 'rating_filter', 'filter_stock_status',
            'product_cat', 'product_tag',
        ];

        foreach ($taxonomies as $attribute) {
            // Layered-nav / attribute-filter block read `filter_{slug}` +
            // `query_type_{slug}`, where slug is the un-prefixed attribute name.
            $slug = $attribute->attribute_name;
            $params[] = "filter_{$slug}";
            $params[] = "query_type_{$slug}";
        }

        return $params;
    }

    protected static function facetwp(): array
    {
        if (! function_exists('FWP') || ! is_object(FWP()->helper ?? null)) {
            return [];
        }

        return self::facetParams((array) FWP()->helper->get_facets());
    }

    /**
     * FacetWP query vars for the given facets. FacetWP prefixes every URL var with
     * `_` (a facet named "color" reads `?_color=…`), plus its own pager/sort vars.
     * Pure so it's testable without FacetWP loaded.
     *
     * @param  array<array{name?: string}>  $facets
     * @return string[]
     */
    public static function facetParams(array $facets): array
    {
        $params = ['_paged', '_per_page', '_sort'];

        foreach ($facets as $facet) {
            if (! empty($facet['name'])) {
                $params[] = '_'.$facet['name'];
            }
        }

        return $params;
    }

    /**
     * Polylang in query-string language mode keys on `?lang=…`.
     *
     * @return string[]
     */
    protected static function polylang(): array
    {
        return defined('POLYLANG_VERSION') ? ['lang'] : [];
    }
}
