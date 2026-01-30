<?php

namespace Genero\Sage\CacheTags;

class Util
{
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
     * Normalize cache tags: flatten, filter empty values, remove duplicates, and re-index.
     *
     * @param  array<string|array<string>>  $tags
     * @return string[]
     */
    public static function normalizeTags(array $tags): array
    {
        $tags = self::flatten($tags);
        $tags = array_filter($tags);
        $tags = array_unique($tags);

        return array_values($tags);
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
