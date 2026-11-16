<?php

namespace Genero\Sage\CacheTags\Invalidators;

/**
 * Kinsta invalidator that purges by `group|` (prefix/wildcard) instead of
 * `single|` (exact URL).
 *
 * A group purge clears a URL together with everything beneath it, such as its
 * pagination (`/shop/page/2/`), in one request. Kinsta doesn't cache a
 * query-string URL (`/shop/?orderby`), and serves the params it leaves out of
 * its cache key (utm_*, …) from the bare path's entry, so the bare path is all
 * that needs storing; this invalidator disables query-string storage to keep the
 * store lean and avoid purging URLs Kinsta never cached.
 *
 * Use this on a standard Kinsta setup. Use the plain `KinstaCacheInvalidator`
 * (exact `single|` purges) if you've configured Kinsta to cache query-string
 * URLs and need each variant purged by its full URL.
 */
class KinstaGroupCacheInvalidator extends KinstaCacheInvalidator
{
    const FILTER_EXACT_URLS = 'cachetags/kinsta-exact-urls';

    /**
     * Normalized exact-match keys per blog id, resolved on a blog's first purge
     * (a switch_to_blog() loop purges several sites with one invalidator).
     *
     * @var array<int, array<string, true>>
     */
    protected array $exactUrls = [];

    public function __construct()
    {
        \add_filter('cachetags/store-query-string', '__return_false');
    }

    protected function purgeKey(string|int $key, string $url): string
    {
        // `group|` is a string-prefix wildcard — it clears the URL and every URL
        // that starts with it. The site root ("/") would match the entire site,
        // and a language home ("/sv/") the entire language, so those purge
        // exactly. Trailing slashes keep "/shop/" from also matching "/shopping/".
        $path = parse_url($url, PHP_URL_PATH) ?: '/';

        return (rtrim($path, '/') === '' || $this->isExactUrl($url) ? 'single|' : 'group|').$key;
    }

    /**
     * URLs (or host-less paths) that a group purge would match far too much
     * beneath: the site home and, with Polylang, each language's home. Filter
     * with `cachetags/kinsta-exact-urls` to add a section front page whose
     * prefix covers a large part of the site.
     *
     * @return string[]
     */
    public function exactUrls(): array
    {
        $urls = [\home_url('/')];

        if (function_exists('pll_languages_list') && function_exists('pll_home_url')) {
            foreach (pll_languages_list() as $language) {
                $urls[] = pll_home_url($language);
            }
        }

        return array_values(array_unique((array) \apply_filters(self::FILTER_EXACT_URLS, $urls)));
    }

    protected function isExactUrl(string $url): bool
    {
        $blogId = get_current_blog_id();
        if (! isset($this->exactUrls[$blogId])) {
            $this->exactUrls[$blogId] = [];
            foreach ($this->exactUrls() as $exact) {
                $this->exactUrls[$blogId][$this->exactKey($exact)] = true;
            }
        }

        return isset($this->exactUrls[$blogId][$this->exactKey($url)])
            || isset($this->exactUrls[$blogId][$this->exactKey((string) parse_url($url, PHP_URL_PATH))]);
    }

    /**
     * Compare host and path, ignoring scheme, query string and trailing slash.
     * A path without a host matches that path on any host.
     */
    protected function exactKey(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        $path = rtrim((string) parse_url($url, PHP_URL_PATH), '/').'/';

        return strtolower((string) $host).$path;
    }
}
