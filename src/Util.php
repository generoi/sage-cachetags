<?php

namespace Genero\Sage\CacheTags;

use WP_REST_Request;

class Util
{
    /**
     * Whether a REST response may be publicly cached, and so safe to tag,
     * store and emit a Cache-Tag header for.
     *
     * Only anonymous, read-only, non-edit responses qualify. Authenticated or
     * password-unlocked responses can contain personalized/protected data that
     * must never end up in a shared cache.
     */
    public static function isCacheableRestRequest(WP_REST_Request $request): bool
    {
        if (! in_array($request->get_method(), ['GET', 'HEAD'], true)) {
            return false;
        }

        if (is_user_logged_in()) {
            return false;
        }

        if ($request['context'] === 'edit') {
            return false;
        }

        return empty($request['password']);
    }

    /**
     * Flatten nested arrays recursively.
     *
     * @param  array<string|array>  $array
     * @return string[]
     */
    public static function flatten(array $array): array
    {
        $result = [];
        foreach ($array as $item) {
            if (is_array($item)) {
                $result = array_merge($result, self::flatten($item));
            } else {
                $result[] = $item;
            }
        }

        return $result;
    }

    /**
     * Normalize cache tags: flatten, drop invalid values, remove duplicates,
     * and re-index.
     *
     * @param  array<string|array<string>>  $tags
     * @return string[]
     */
    public static function normalizeTags(array $tags): array
    {
        $tags = self::flatten($tags);
        $tags = array_filter($tags, [self::class, 'isValidTag']);
        $tags = array_unique($tags);

        return array_values($tags);
    }

    /**
     * Whether a value is a usable cache tag: a non-empty, single header token
     * (no whitespace or control characters) that fits the store column.
     *
     * A tag with whitespace would split the space-delimited header, and an
     * over-long tag both overflows the store and, on Fastly, gets dropped
     * along with every following key. Such tags are discarded.
     */
    public static function isValidTag(mixed $tag): bool
    {
        if (! is_string($tag) || $tag === '') {
            return false;
        }

        if (strlen($tag) > (int) apply_filters('cachetags/max-tag-length', 191)) {
            return false;
        }

        return (bool) preg_match(
            apply_filters('cachetags/tag-pattern', '/^[^\s\x00-\x1F]+$/'),
            $tag
        );
    }

    /**
     * Tracking/volatile params dropped from the stored URL when the query
     * string is included, so it doesn't bloat the store. This is a generic
     * starting default — to actually *match* a URL-keyed edge it must equal
     * that edge's own strip list, which is site-specific (our Fastly VCLs strip
     * anywhere from 5 to 16 params: beamex strips only utm_ and gclid, herrfors
     * also strips campaign_id, tduid, gad_source, wbraid, dclid, _gl, …). Align
     * it per site via cachetags/url-ignored-params; comprehensive normalization
     * belongs at the edge.
     */
    const IGNORED_URL_PARAMS = [
        // Campaign / click trackers (Google, Microsoft, Facebook, GA linker).
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id',
        'gclid', 'gad_source', 'gbraid', 'wbraid', 'dclid', 'fbclid', 'msclkid', '_gl',
        // Volatile WordPress per-request params.
        '_wpnonce', '_',
    ];

    public static function currentUrl(): string
    {
        global $wp;

        $url = trailingslashit(home_url($wp->request));

        // Path-only by default. For Fastly this is moot — it purges by
        // Surrogate-Key (tag) and never reads the stored URL. For Kinsta it
        // matches what's actually cached, since query-string URLs bypass the
        // cache entirely. Opt in only on a URL-keyed cache that *does* cache
        // query strings (SiteGround, or Kinsta set to cache GET params), so the
        // stored URL matches the cached variant — at the cost of one store row
        // per visited query combination.
        if (empty($_GET) || ! apply_filters('cachetags/store-query-string', false)) {
            return $url;
        }

        $ignored = apply_filters('cachetags/url-ignored-params', self::IGNORED_URL_PARAMS);
        $params = array_diff_key($_GET, array_flip($ignored));

        if (empty($params)) {
            return $url;
        }

        ksort($params);
        $params = map_deep(wp_unslash($params), 'sanitize_text_field');

        return $url.'?'.http_build_query($params);
    }

    /**
     * Get environment variable, using any env() function in scope if available, otherwise getenv().
     */
    public static function env(string $key): string|false
    {
        // Check for env() in global namespace
        if (function_exists('env') && is_callable('env')) {
            return env($key);
        }

        // Fallback to getenv()
        return getenv($key);
    }

    /**
     * Chunk a query string into parts that don't exceed a maximum size.
     *
     * @param  array<string, string|int|float|bool|array|null>  $request
     * @return string[]
     */
    public static function chunkRequest(array $request, int $maxSize): array
    {
        $chunks = [];
        $parts = explode('&', http_build_query($request));
        $inProgressChunk = '';

        foreach ($parts as $part) {
            // The in progress chunk _if_ the part would be added
            $chunk = $inProgressChunk ? $inProgressChunk.'&'.$part : $part;
            // If it exceeds the limit, begin a new chunk
            if (strlen($chunk) > $maxSize) {
                $chunks[] = $inProgressChunk;
                $chunk = $part;
            }
            $inProgressChunk = $chunk;
        }

        if ($inProgressChunk) {
            $chunks[] = $inProgressChunk;
        }

        return $chunks;
    }
}
