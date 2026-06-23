<?php

namespace Genero\Sage\CacheTags\Actions;

use Genero\Sage\CacheTags\Bootstrap;
use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Contracts\Action;

/**
 * Include known cache-significant query parameters in the cache key for BOTH
 * the front-end and REST API, so paginated/filtered/sorted/translated variants
 * are cached and purged separately instead of collapsing to one key.
 *
 * Opt-in, for sites that know their GET parameters: it allow-lists a known set
 * (WP-core search/sort, plus active integrations like Polylang and FacetWP) and
 * intentionally ignores everything else, so arbitrary params (tracking, crawler
 * noise) don't fork the cache key. Extend the set with the
 * cachetags/url-allowed-params filter. ACF isn't included — its data shapes the
 * response body, not the URL, and is covered by content tags.
 *
 * Verify the allow-list against the site's actual query params: it only ADDS
 * params (keeping a param is purge-safe), so a missing one means that variant
 * shares a key with the base URL — over-caching at worst here, but stale if the
 * site genuinely varies content by a param not in the list.
 */
class QueryParams implements Action
{
    public function __construct(protected CacheTags $cacheTags) {}

    public function bind(): void
    {
        \add_filter(Bootstrap::FILTER_ALLOWED_PARAMS, [$this, 'allowedParams']);
    }

    /**
     * @param  string[]  $params
     * @return string[]
     */
    public function allowedParams(array $params): array
    {
        $params = [
            ...$params,
            // WP core: search and sort vary cached output (pagination/taxonomy/
            // date are path-based with pretty permalinks).
            's',
            'orderby',
            'order',
            'paged',
            ...$this->polylangParams(),
            ...$this->facetwpParams(),
        ];

        return array_values(array_unique($params));
    }

    /**
     * Polylang's language query var (used when the language isn't a path
     * segment, and on REST).
     *
     * @return string[]
     */
    protected function polylangParams(): array
    {
        return function_exists('pll_current_language') ? ['lang'] : [];
    }

    /**
     * FacetWP selection params. FacetWP reflects selections in the query string
     * as `_<facet-name>` plus `_paged`/`_sort` (the leading-underscore prefix).
     *
     * @return string[]
     */
    protected function facetwpParams(): array
    {
        if (! function_exists('FWP') || ! is_object(FWP()->helper ?? null)) {
            return [];
        }

        $facets = array_map(
            fn ($facet) => '_'.$facet['name'],
            FWP()->helper->get_facets() ?: []
        );

        return [...$facets, '_paged', '_sort'];
    }
}
