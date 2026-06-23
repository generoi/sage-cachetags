<?php

namespace Genero\Sage\CacheTags\Invalidators;

/**
 * Kinsta invalidator that purges by `group|` (prefix/wildcard) instead of
 * `single|` (exact URL).
 *
 * A group purge clears a URL together with everything beneath it — its
 * pagination (`/shop/page/2/`) and its query-string variants (`/shop/?orderby`)
 * — in one request. So the bare path is all that needs storing; this invalidator
 * disables query-string storage to keep the store lean and avoid purging URLs
 * Kinsta never cached (query strings bypass its cache).
 *
 * Use this on a standard Kinsta setup. Use the plain `KinstaCacheInvalidator`
 * (exact `single|` purges) if you've configured Kinsta to cache query-string
 * URLs and need each variant purged by its full URL.
 */
class KinstaGroupCacheInvalidator extends KinstaCacheInvalidator
{
    public function __construct()
    {
        \add_filter('cachetags/store-query-string', '__return_false');
    }

    protected function purgeKey(string|int $key, string $url): string
    {
        // `group|` is a string-prefix wildcard — it clears the URL and every URL
        // that starts with it. The site root ("/") would match the entire site,
        // so purge that exactly. Trailing slashes keep "/shop/" from also
        // matching "/shopping/".
        $path = parse_url($url, PHP_URL_PATH) ?: '/';

        return (rtrim($path, '/') === '' ? 'single|' : 'group|').$key;
    }
}
