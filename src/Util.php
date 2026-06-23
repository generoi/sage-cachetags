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
     * Whether the current front-end request may be stored in a shared cache.
     */
    public static function isCacheableRequest(): bool
    {
        // Previews render unsaved content; the admin bar bakes per-user chrome
        // into the HTML. Never cache either — not overridable.
        if (is_preview() || is_admin_bar_showing()) {
            return false;
        }

        // Logged-in requests are non-cacheable unless an integration opts them
        // in via cachetags/cache-logged-in (e.g. WooCommerce customers, who see
        // identical catalog pages and whose admin bar is hidden). The general
        // cachetags/cacheable filter still runs last and can veto (cart, forms).
        $cacheable = ! is_user_logged_in() || (bool) apply_filters('cachetags/cache-logged-in', false);

        return (bool) apply_filters('cachetags/cacheable', $cacheable);
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

    public static function currentUrl(): string
    {
        global $wp;

        return trailingslashit(home_url($wp->request));
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
