<?php

namespace Genero\Sage\CacheTags\Invalidators;

use Genero\Sage\CacheTags\Actions\Site;
use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Contracts\Invalidator;
use Genero\Sage\CacheTags\Tag;
use Genero\Sage\CacheTags\Tags\SiteTags;
use Genero\Sage\CacheTags\Util;
use WpOrg\Requests\Exception;
use WpOrg\Requests\Requests;
use WpOrg\Requests\Response;

/**
 * Purge Cloudflare by Cache-Tag, in every zone listed in CLOUDFLARE_ZONE_IDS
 * (comma-separated) — one site served on several domains is one zone per
 * domain, and tags are per zone. Authenticates with CLOUDFLARE_API_TOKEN, which
 * needs the Cache Purge permission on each zone.
 */
class CloudflareCacheInvalidator implements Invalidator
{
    const API_BASE_URL = 'https://api.cloudflare.com/client/v4/zones/';

    /** Cloudflare accepts at most 100 tags per purge request, on every plan. */
    const MAX_TAGS_PER_PURGE = 100;

    /** Cap how many purge requests are in flight at once. */
    const MAX_CONCURRENT_PURGES = 10;

    /** Retry a rate-limited (429) purge this many times before deferring it. */
    const MAX_PURGE_RETRIES = 2;

    /** Cap the 429 backoff so a shutdown-time purge can't hang for minutes. */
    const MAX_BACKOFF_SECONDS = 10;

    /**
     * Purges still rate-limited after the retries are stored here, per zone, and
     * retried by a single cron event — Cloudflare's Free plan allows only
     * 5 purges/minute per account, so the budget runs out on a busy site.
     */
    const DEFERRED_OPTION = 'cachetags_cloudflare_deferred';

    const DEFERRED_HOOK = 'cachetags/cloudflare-deferred';

    /** Seconds until the deferred retry; the Free plan refills a token every 12s. */
    const DEFERRED_DELAY = 60;

    protected ?string $apiToken;

    /** @var string[] */
    protected array $zoneIds;

    public function __construct()
    {
        $this->apiToken = Util::env('CLOUDFLARE_API_TOKEN');
        $this->zoneIds = array_values(array_filter(array_map('trim', explode(',', (string) Util::env('CLOUDFLARE_ZONE_IDS')))));

        // A static callback, so however many instances exist it's hooked once.
        add_action(self::DEFERRED_HOOK, [static::class, 'runDeferred']);
    }

    public function clear(array $urls, array $tags): bool
    {
        if ($tags === []) {
            return true;
        }

        return $this->purge($this->tagPayloads($tags));
    }

    public function flush(): bool
    {
        if ($this->hasAction(Site::class)) {
            $tags = Tag::toStrings(SiteTags::sites('any'));

            return $this->clear($tags, $tags);
        }

        return $this->purge([['purge_everything' => true]]);
    }

    public static function runDeferred(): void
    {
        (new static)->retryDeferred();
    }

    /**
     * Send the deferred purges, coalesced per zone. Whatever is still
     * rate-limited is deferred again.
     */
    public function retryDeferred(): bool
    {
        $queue = get_option(self::DEFERRED_OPTION, []);
        delete_option(self::DEFERRED_OPTION);

        $jobs = [];
        foreach ((array) $queue as $zone => $tags) {
            $payloads = $tags === true ? [['purge_everything' => true]] : $this->tagPayloads($tags);
            foreach ($payloads as $payload) {
                $jobs[] = ['zone' => (string) $zone, 'payload' => $payload];
            }
        }

        return $this->dispatch($jobs);
    }

    /**
     * @param  string[]  $tags
     * @return array<int, array{tags: string[]}>
     */
    protected function tagPayloads(array $tags): array
    {
        return array_map(
            fn (array $chunk) => ['tags' => array_values($chunk)],
            array_chunk(array_values($tags), self::MAX_TAGS_PER_PURGE)
        );
    }

    /**
     * Send each payload to every zone.
     *
     * @param  array<int, array<string, mixed>>  $payloads
     */
    protected function purge(array $payloads): bool
    {
        if (! $this->apiToken || $this->zoneIds === []) {
            return false;
        }

        $jobs = [];
        foreach ($this->zoneIds as $zone) {
            foreach ($payloads as $payload) {
                $jobs[] = ['zone' => $zone, 'payload' => $payload];
            }
        }

        return $this->dispatch($jobs);
    }

    /**
     * Send purge jobs concurrently in bounded windows. Rate-limited jobs are
     * retried briefly and then deferred to cron, so they count as handled; any
     * other failure makes the result false (after the remaining zones are still
     * attempted, so one bad zone doesn't block the rest).
     *
     * @param  array<int, array{zone: string, payload: array<string, mixed>}>  $jobs
     */
    protected function dispatch(array $jobs): bool
    {
        $ok = true;
        $deferred = [];

        foreach (array_chunk($jobs, self::MAX_CONCURRENT_PURGES) as $window) {
            // The budget is spent: don't burn retries on the rest, queue them.
            if ($deferred !== []) {
                array_push($deferred, ...$window);

                continue;
            }

            $pending = $window;

            for ($attempt = 0; ; $attempt++) {
                $responses = $this->requestMultiple(array_map([$this, 'buildRequest'], $pending));
                $retry = [];
                $retryAfter = '';

                foreach ($pending as $i => $job) {
                    $response = $responses[$i] ?? null;

                    if ($this->isSuccess($response)) {
                        continue;
                    }

                    if ($response instanceof Response && $response->status_code === 429) {
                        $retry[] = $job;
                        $retryAfter = (string) ($response->headers['retry-after'] ?? $retryAfter);

                        continue;
                    }

                    $ok = false;
                }

                if ($retry === []) {
                    break;
                }

                if ($attempt >= self::MAX_PURGE_RETRIES) {
                    array_push($deferred, ...$retry);
                    break;
                }

                $this->pauseBeforeRetry($this->backoffSeconds($retryAfter, $attempt));
                $pending = $retry;
            }
        }

        if ($deferred !== []) {
            $this->defer($deferred);
        }

        return $ok;
    }

    /**
     * Merge rate-limited jobs into the stored per-zone queue and schedule one
     * retry. A zone's purge_everything supersedes any queued tags.
     *
     * @param  array<int, array{zone: string, payload: array<string, mixed>}>  $jobs
     */
    protected function defer(array $jobs): void
    {
        $queue = (array) get_option(self::DEFERRED_OPTION, []);

        foreach ($jobs as ['zone' => $zone, 'payload' => $payload]) {
            $queued = $queue[$zone] ?? [];
            $queue[$zone] = isset($payload['purge_everything']) || $queued === true
                ? true
                : array_values(array_unique([...$queued, ...$payload['tags']]));
        }

        update_option(self::DEFERRED_OPTION, $queue, false);

        if (! wp_next_scheduled(self::DEFERRED_HOOK)) {
            wp_schedule_single_event(time() + self::DEFERRED_DELAY, self::DEFERRED_HOOK);
        }
    }

    /**
     * Cloudflare can answer 200 with "success": false, so check both.
     */
    protected function isSuccess(mixed $response): bool
    {
        if (! $response instanceof Response || $response->status_code !== 200) {
            return false;
        }

        return (json_decode($response->body, true)['success'] ?? false) === true;
    }

    /**
     * Real concurrent dispatch — isolated as a seam so the retry/defer logic can
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
     * Seconds to wait before retrying a 429: Cloudflare documents Retry-After
     * (delta-seconds) for its API rate limit, though not for the purge bucket
     * specifically; fall back to exponential backoff. Capped so a shutdown-time purge can't hang, floored at 1s.
     */
    protected function backoffSeconds(string $retryAfter, int $attempt): int
    {
        if ($retryAfter !== '') {
            $wait = is_numeric($retryAfter) ? (int) $retryAfter : (strtotime($retryAfter) ?: time()) - time();
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

    protected function hasAction(string $action): bool
    {
        $cacheTags = CacheTags::getInstance();
        if ($cacheTags === null) {
            return false;
        }

        return $cacheTags->hasAction($action);
    }

    /**
     * @param  array{zone: string, payload: array<string, mixed>}  $job
     * @return array<string, mixed>
     */
    protected function buildRequest(array $job): array
    {
        return [
            'url' => self::API_BASE_URL.rawurlencode($job['zone']).'/purge_cache',
            'type' => Requests::POST,
            'headers' => [
                'Authorization' => 'Bearer '.$this->apiToken,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'data' => wp_json_encode($job['payload']),
        ];
    }
}
