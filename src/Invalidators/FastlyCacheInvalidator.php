<?php

namespace Genero\Sage\CacheTags\Invalidators;

use Genero\Sage\CacheTags\Actions\Site;
use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Contracts\Invalidator;
use Genero\Sage\CacheTags\Tags\SiteTags;
use Genero\Sage\CacheTags\Util;
use WP_Error;
use WpOrg\Requests\Requests;
use WpOrg\Requests\Response;

class FastlyCacheInvalidator implements Invalidator
{
    const FASTLY_BASE_URL = 'https://api.fastly.com/service/';

    /**
     * Fastly's bulk surrogate-key purge accepts at most 256 keys per request;
     * a larger payload is rejected (and the purge silently fails). Bulk edits or
     * a multisite flush can exceed this, so keys are chunked.
     */
    const MAX_KEYS_PER_PURGE = 256;

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
        $responses = Requests::request_multiple($requests, ['timeout' => 5, 'verify' => false]);

        foreach ($responses as $response) {
            if (! $response instanceof Response || $response->status_code !== 200) {
                return false;
            }
        }

        return true;
    }

    public function flush(): bool
    {
        if ($this->hasAction(Site::class)) {
            $tags = SiteTags::sites('any');

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
        $result = wp_remote_post($url, $this->buildRequest($payload));
        if (is_wp_error($result)) {
            return $result;
        }
        $responseCode = wp_remote_retrieve_response_code($result);
        if ($responseCode === 200) {
            return true;
        }

        $response = json_decode(wp_remote_retrieve_body($result));

        return new WP_Error('fastly_error', $response->msg ?? 'Unknown', $response->detail ?? '');
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
            'sslverify' => false,
            'timeout' => 5,
        ];

        if (isset($payload)) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode($payload);
        }

        return $args;
    }
}
