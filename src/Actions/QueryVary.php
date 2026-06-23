<?php

namespace Genero\Sage\CacheTags\Actions;

use Genero\Sage\CacheTags\Bootstrap;
use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Contracts\Action;

/**
 * Vary the cache key by the WP-core search/sort query parameters, so those
 * listing variants are cached and purged separately instead of collapsing to
 * one key. Opt-in, for sites that use them.
 *
 * Plugin-specific params live in their own actions (Polylang, FacetWP), each
 * contributing to the shared cachetags/url-allowed-params filter, so they're
 * only in play when that integration is enabled.
 *
 * The core params are added only on listing/search views — they're inert on
 * singular content, so a stray `?orderby=` crawled onto a post or 404 doesn't
 * fork the cache key. REST collections key off their registered route args, so
 * this stays out of the REST path.
 */
class QueryVary implements Action
{
    const CORE_PARAMS = ['s', 'orderby', 'order', 'paged'];

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
        return $this->isListingRequest()
            ? [...$params, ...self::CORE_PARAMS]
            : $params;
    }

    /**
     * Front-end archive/search views, where search/sort params change output.
     * REST is excluded — its keys already include the route's registered args.
     */
    protected function isListingRequest(): bool
    {
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return false;
        }

        return ! is_singular() && ! is_404();
    }
}
