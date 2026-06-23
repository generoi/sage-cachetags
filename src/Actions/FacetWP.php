<?php

namespace Genero\Sage\CacheTags\Actions;

use Genero\Sage\CacheTags\Bootstrap;
use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Contracts\Action;

/**
 * Keep FacetWP selection params in the cache key, so facet-filtered listings
 * are cached and purged separately from the unfiltered page.
 *
 * FacetWP reflects selections in the query string as `_<facet-name>` (the
 * leading-underscore prefix), plus `_paged`/`_sort`. The facet names are read
 * from the registered facets. They only appear on facet-filtered requests, so
 * no view gating is needed — an unfiltered request carries none of them.
 */
class FacetWP implements Action
{
    public function __construct(protected CacheTags $cacheTags) {}

    public function bind(): void
    {
        \add_filter(Bootstrap::FILTER_ALLOWED_PARAMS, [$this, 'allowFacetParams']);
    }

    /**
     * @param  string[]  $params
     * @return string[]
     */
    public function allowFacetParams(array $params): array
    {
        if (! function_exists('FWP') || ! is_object(FWP()->helper ?? null)) {
            return $params;
        }

        $facets = array_map(
            fn ($facet) => '_'.$facet['name'],
            FWP()->helper->get_facets() ?: []
        );

        return [...$params, ...$facets, '_paged', '_sort'];
    }
}
