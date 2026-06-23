<?php

namespace Genero\Sage\CacheTags\Actions;

use Genero\Sage\CacheTags\Bootstrap;
use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Contracts\Action;

/**
 * Keep FacetWP selection params in the cache key, so facet-filtered listings
 * are cached and purged separately from the unfiltered page.
 *
 * FacetWP parses the current request's selections into FWP()->request->url_vars
 * (the selected facet names, plus the paged/per_page/sort features) on a direct
 * page load. We key only those — so the contribution is naturally scoped to
 * facet pages with an active selection, and empty selections (which don't change
 * the page) are ignored. The query-string prefix is a FacetWP setting (default
 * `_`, can be `fwp_`), so it is read rather than hardcoded.
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
        if (! function_exists('FWP') || ! is_object(FWP()->request ?? null)) {
            return $params;
        }

        $prefix = FWP()->helper->get_setting('prefix') ?: '_';
        $selected = array_keys(FWP()->request->url_vars ?? []);

        return [
            ...$params,
            ...array_map(fn ($name) => $prefix.$name, $selected),
        ];
    }
}
