<?php

namespace Genero\Sage\CacheTags\Invalidators;

use Genero\Sage\CacheTags\Actions\Site;
use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Contracts\Invalidator;
use Genero\Sage\CacheTags\Tags\SiteTags;
use Genero\Sage\CacheTags\Util;
use WP_Error;

class FastlyCacheInvalidator implements Invalidator
{
    const FASTLY_BASE_URL = 'https://api.fastly.com/service/';

    protected string $serviceId;

    protected string $apiKey;

    public function __construct()
    {
        $this->serviceId = Util::env('FASTLY_SERVICE_ID');
        $this->apiKey = Util::env('FASTLY_API_KEY');
    }

    public function clear(array $urls, array $tags): bool
    {
        $response = $this->apiCall('/purge/', [
            'surrogate_keys' => $tags,
        ]);

        return ! is_wp_error($response);
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
