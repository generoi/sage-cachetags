<?php

namespace Genero\Sage\CacheTags;

/**
 * Re-requests purged pages from the server itself, so the page cache holds
 * them again before a visitor or a crawler has to wait for the render.
 *
 * Purges queue their URLs (WarmInvalidator); `wp cachetags warm`, run from the
 * server's crontab, requests them one at a time with a pause between. A run
 * stops early when the site answers 429 or 503: whatever is in front of PHP
 * (a rate limiter, the host) says it's busy, and warming must never compete
 * with real traffic. Only plain pages are warmed: no query strings (search,
 * facets, campaign links), no REST, admin or login URLs, and nothing matching
 * the config's `warm-skip` path patterns or the cachetags/warm/skip filter.
 *
 * The queue is an option, best effort: two purges landing at once can lose
 * a URL, which is then simply cached by its next visitor as before.
 */
class Warmer
{
    const OPTION = 'cachetags_warm_queue';

    /** At most this many URLs wait; the oldest drop first. */
    const MAX_QUEUE = 20000;

    /** Seconds a queued URL waits, so its purge has reached the cache first. */
    const SETTLE = 30;

    /** Return true to leave a URL unwarmed: ($skip, $url). */
    const FILTER_SKIP = 'cachetags/warm/skip';

    /** The headers each warming request sends. */
    const FILTER_HEADERS = 'cachetags/warm/request-headers';

    /**
     * Regular expressions matched against a URL's path, from config `warm-skip`.
     *
     * @var string[]
     */
    public static array $skip = [];

    /**
     * Queue URLs to warm; one already queued keeps its place.
     *
     * @param  string[]  $urls
     */
    public static function queue(array $urls, ?int $now = null): void
    {
        $now ??= time();
        $queue = self::load();
        $added = false;
        foreach ($urls as $url) {
            if (! isset($queue[$url]) && ! self::skips($url)) {
                $queue[$url] = $now;
                $added = true;
            }
        }
        if (! $added) {
            return;
        }
        if (count($queue) > self::MAX_QUEUE) {
            $queue = array_slice($queue, -self::MAX_QUEUE, null, true);
        }
        update_option(self::OPTION, $queue, false);
    }

    /**
     * Warm queued URLs for up to $seconds, pausing $pause seconds between.
     *
     * @return array{warmed: int, failed: int, left: int, stopped: ?string}
     */
    public static function run(int $seconds = 50, float $pause = 0.2, ?int $now = null): array
    {
        $now ??= time();
        $deadline = microtime(true) + $seconds;
        $queue = self::load();
        $done = [];
        $warmed = $failed = 0;
        $stopped = null;

        foreach ($queue as $url => $queuedAt) {
            if (microtime(true) >= $deadline) {
                break;
            }
            if ($queuedAt > $now - self::SETTLE) {
                continue;
            }
            if (self::skips($url)) {
                $done[$url] = $queuedAt;

                continue;
            }

            $code = self::request($url);
            if ($code === 429 || $code === 503) {
                $stopped = "the site answered {$code}";

                break;
            }
            $done[$url] = $queuedAt;
            $code >= 200 && $code < 400 ? $warmed++ : $failed++;

            if ($pause > 0) {
                usleep((int) ($pause * 1_000_000));
            }
        }

        // Purges may have queued more meanwhile: remove only what this run did,
        // and not a URL queued again after it was taken.
        $latest = self::load();
        foreach ($done as $url => $queuedAt) {
            if (($latest[$url] ?? null) === $queuedAt) {
                unset($latest[$url]);
            }
        }
        update_option(self::OPTION, $latest, false);

        return ['warmed' => $warmed, 'failed' => $failed, 'left' => count($latest), 'stopped' => $stopped];
    }

    /** Whether a URL is left unwarmed. */
    public static function skips(string $url): bool
    {
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        $skip = wp_parse_url($url, PHP_URL_QUERY) !== null
            || str_contains($path, '/wp-json/')
            || str_contains($path, '/wp-admin/')
            || str_ends_with($path, '/wp-login.php');
        foreach (self::$skip as $pattern) {
            if (! $skip && @preg_match($pattern, $path) === 1) {
                $skip = true;
            }
        }

        return (bool) apply_filters(self::FILTER_SKIP, $skip, $url);
    }

    /** How many URLs are queued. */
    public static function count(): int
    {
        return count(self::load());
    }

    /**
     * @return array<string, int> URL => when it was queued
     */
    protected static function load(): array
    {
        $queue = get_option(self::OPTION, []);

        return is_array($queue) ? $queue : [];
    }

    /** The status code of a GET as an anonymous visitor's, 0 on failure. */
    protected static function request(string $url): int
    {
        $response = wp_remote_get($url, [
            'timeout' => 30,
            'redirection' => 0,
            'decompress' => false,
            'cookies' => [],
            'headers' => (array) apply_filters(self::FILTER_HEADERS, [
                'Accept-Encoding' => 'gzip, br',
                'User-Agent' => 'Mozilla/5.0 (compatible; sage-cachetags warmer)',
            ], $url),
        ]);

        return is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
    }
}
