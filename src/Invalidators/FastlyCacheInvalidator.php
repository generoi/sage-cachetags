<?php

namespace Genero\Sage\CacheTags\Invalidators;

use Genero\Sage\CacheTags\Actions\Site;
use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Contracts\Invalidator;
use Genero\Sage\CacheTags\Tag;
use Genero\Sage\CacheTags\Tags\SiteTags;
use Genero\Sage\CacheTags\Util;
use WP_Error;
use WpOrg\Requests\Exception;
use WpOrg\Requests\Requests;
use WpOrg\Requests\Response;

class FastlyCacheInvalidator implements Invalidator
{
    const FASTLY_BASE_URL = 'https://api.fastly.com/service/';

    /**
     * Fastly's bulk surrogate-key purge accepts at most 256 keys per request; a
     * larger payload is rejected wholesale with HTTP 400. Bulk edits or a
     * multisite flush can exceed this, so keys are chunked.
     */
    const MAX_KEYS_PER_PURGE = 256;

    /**
     * Cap how many chunk purges fan out at once. Fastly's purge budget is ~100k/h
     * (~27/s); firing hundreds of requests in one burst risks 429s and exhausts
     * local connection handles for no gain. Windows of this size keep it bounded.
     */
    const MAX_CONCURRENT_PURGES = 10;

    /** Retry a rate-limited (429) purge this many times before giving up. */
    const MAX_PURGE_RETRIES = 2;

    /** Cap the 429 backoff so a shutdown-time purge can't hang for minutes. */
    const MAX_BACKOFF_SECONDS = 10;

    protected ?string $serviceId;

    protected ?string $apiKey;

    public function __construct()
    {
        $this->serviceId = Util::env('FASTLY_SERVICE_ID');
        $this->apiKey = Util::env('FASTLY_API_KEY');
    }

    public function clear(array $urls, array $tags): bool
    {
        $chunks = array_chunk($tags, self::MAX_KEYS_PER_PURGE);

        if ($chunks === []) {
            return true;
        }

        // One request — the overwhelmingly common case — stays a simple blocking
        // call (and goes through the WP HTTP API).
        if (count($chunks) === 1) {
            return ! is_wp_error($this->apiCall('/purge/', ['surrogate_keys' => array_values($chunks[0])]));
        }

        // Bulk (>256 keys): fan the chunks out concurrently rather than make N
        // sequential blocking round-trips.
        return $this->purgeChunksInParallel($chunks);
    }

    /**
     * @param  array<int, string[]>  $chunks
     */
    protected function purgeChunksInParallel(array $chunks): bool
    {
        $url = self::FASTLY_BASE_URL.$this->serviceId.'/purge/';

        $requests = array_map(function (array $chunk) use ($url) {
            // Reuse buildRequest so the soft-purge subclass's header carries over.
            $args = $this->buildRequest(['surrogate_keys' => array_values($chunk)]);

            return [
                'url' => $url,
                'type' => Requests::POST,
                'headers' => $args['headers'],
                'data' => $args['body'],
            ];
        }, $chunks);

        return $this->dispatchParallel($requests);
    }

    /**
     * Send prepared requests concurrently; true only if every purge returns 200.
     * Isolated as a seam so the chunking can be asserted without real HTTP.
     *
     * @param  array<int, array<string, mixed>>  $requests
     */
    protected function dispatchParallel(array $requests): bool
    {
        // Bounded fan-out: at most MAX_CONCURRENT_PURGES in flight per window.
        foreach (array_chunk($requests, self::MAX_CONCURRENT_PURGES) as $window) {
            $pending = array_values($window);

            for ($attempt = 0; ; $attempt++) {
                $responses = $this->requestMultiple($pending);
                $retry = [];
                $retryAfter = '';
                $reset = '';

                foreach ($responses as $i => $response) {
                    if ($response instanceof Response && $response->status_code === 200) {
                        continue;
                    }

                    // Retry only the rate-limited chunks (re-sending the rest would
                    // just spend more of the purge budget). Other failures are fatal.
                    if ($response instanceof Response && $response->status_code === 429 && $attempt < self::MAX_PURGE_RETRIES) {
                        $retry[] = $pending[$i];
                        $retryAfter = (string) ($response->headers['retry-after'] ?? $retryAfter);
                        $reset = (string) ($response->headers['fastly-ratelimit-reset'] ?? $reset);

                        continue;
                    }

                    return false;
                }

                if ($retry === []) {
                    break;
                }

                $this->pauseBeforeRetry($this->backoffSeconds($retryAfter, $reset, $attempt));
                $pending = $retry;
            }
        }

        return true;
    }

    /**
     * Real concurrent dispatch — isolated as a seam so the retry/window logic can
     * be tested without real HTTP.
     *
     * @param  array<int, array<string, mixed>>  $requests
     * @return array<int, Response|Exception>
     */
    protected function requestMultiple(array $requests): array
    {
        return Requests::request_multiple($requests, ['timeout' => 5]);
    }

    /**
     * Seconds to wait before retrying a 429. Fastly's purge limit is a separate
     * bucket from the API limit and isn't documented to return a timing header at
     * all, so try, in order: Retry-After (defensive — Fastly doesn't document it,
     * but a proxy might add it; delta-seconds or HTTP-date), then the documented
     * Fastly-RateLimit-Reset (a Unix timestamp), then plain exponential backoff.
     * The wait is capped so a purge running on shutdown can't hang, floored at 1s.
     */
    protected function backoffSeconds(string $retryAfter, string $reset, int $attempt): int
    {
        if ($retryAfter !== '') {
            $wait = is_numeric($retryAfter) ? (int) $retryAfter : (strtotime($retryAfter) ?: time()) - time();
        } elseif ((int) $reset > 0) {
            $wait = (int) $reset - time();
        } else {
            $wait = 2 ** $attempt; // 1s, 2s, 4s, …
        }

        return max(1, min(self::MAX_BACKOFF_SECONDS, (int) $wait));
    }

    /** Seam so tests don't actually sleep. */
    protected function pauseBeforeRetry(int $seconds): void
    {
        sleep($seconds);
    }

    public function flush(): bool
    {
        if ($this->hasAction(Site::class)) {
            $tags = Tag::toStrings(SiteTags::sites('any'));

            return $this->clear($tags, $tags);
        } else {
            $response = $this->apiCall('/purge_all');
        }

        return ! is_wp_error($response);
    }

    protected function hasAction(string $action): bool
    {
        $cacheTags = CacheTags::getInstance();
        if ($cacheTags === null) {
            return false;
        }

        return $cacheTags->hasAction($action);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    protected function apiCall(string $path, ?array $payload = null): true|WP_Error
    {
        $url = self::FASTLY_BASE_URL.$this->serviceId.$path;

        for ($attempt = 0; ; $attempt++) {
            $result = wp_remote_post($url, $this->buildRequest($payload));
            if (is_wp_error($result)) {
                return $result;
            }

            $responseCode = wp_remote_retrieve_response_code($result);
            if ($responseCode === 200) {
                return true;
            }

            // Rate-limited: back off (Retry-After / Fastly-RateLimit-Reset /
            // exponential) and retry.
            if ($responseCode === 429 && $attempt < self::MAX_PURGE_RETRIES) {
                $this->pauseBeforeRetry($this->backoffSeconds(
                    (string) wp_remote_retrieve_header($result, 'retry-after'),
                    (string) wp_remote_retrieve_header($result, 'fastly-ratelimit-reset'),
                    $attempt,
                ));

                continue;
            }

            $response = json_decode(wp_remote_retrieve_body($result));

            return new WP_Error('fastly_error', $response->msg ?? 'Unknown', $response->detail ?? '');
        }
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    protected function buildRequest(?array $payload = null): array
    {
        $args = [
            'headers' => [
                'Fastly-Key' => Util::env('FASTLY_API_KEY'),
                // 'fastly-soft-purge' => '1',
                'Accept' => 'application/json',
            ],
            'timeout' => 5,
        ];

        if (isset($payload)) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode($payload);
        }

        return $args;
    }
}
