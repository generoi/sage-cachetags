<?php

namespace Genero\Sage\CacheTags\Invalidators;

/**
 * Invalidate instead of purge — Cloudflare's soft purge. Cached content is kept
 * and marked stale, so with stale-while-revalidate in the edge's cache headers
 * the next visitor gets it immediately while Cloudflare revalidates in the
 * background. Same selectors and rate limits as a purge.
 *
 * Cloudflare ignores stale-while-revalidate when s-maxage is present, so send
 * the edge its own header without it, e.g.
 * `Cloudflare-CDN-Cache-Control: max-age=604800, stale-while-revalidate=2592000`.
 *
 * @see https://developers.cloudflare.com/cache/guides/invalidate-cache/
 */
class CloudflareSoftCacheInvalidator extends CloudflareCacheInvalidator
{
    const ENDPOINT = 'invalidate_cache';
}
