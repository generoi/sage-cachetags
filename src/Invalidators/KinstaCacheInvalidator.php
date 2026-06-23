<?php

namespace Genero\Sage\CacheTags\Invalidators;

use Genero\Sage\CacheTags\Contracts\Invalidator;
use Genero\Sage\CacheTags\Util;

class KinstaCacheInvalidator implements Invalidator
{
    const IMMEDIATE_PATH = 'https://localhost/kinsta-clear-cache/v2/immediate';

    const CLEAR_ALL_PATH = 'https://localhost/kinsta-clear-cache-all';

    const POST_MAX_BODY_SIZE = 51200;

    /**
     * @param  string[]  $urls
     * @param  string[]  $tags
     */
    public function clear(array $urls, array $tags): bool
    {
        if (defined('KINSTAMU_DISABLE_AUTOPURGE') && KINSTAMU_DISABLE_AUTOPURGE === true) {
            return false;
        }

        $requests = Util::chunkRequest($this->purgeList($urls), self::POST_MAX_BODY_SIZE);

        if (count($requests) > 3) {
            return $this->flush();
        }

        return array_reduce(
            $requests,
            fn (bool $result, string $chunk) => $this->post(self::IMMEDIATE_PATH, $chunk) ? $result : false,
            true
        );
    }

    /**
     * Build the Kinsta purge request: a map of `<type>|<key>` => scheme-less URL.
     *
     * @param  string[]  $urls
     * @return array<string, string>
     */
    public function purgeList(array $urls): array
    {
        $purgeRequest = [];
        foreach ($urls as $key => $url) {
            $purgeRequest[$this->purgeKey($key, $url)] = str_replace(['http://', 'https://'], '', $url);
        }

        return apply_filters('KinstaCache/purgeImmediate', $purgeRequest);
    }

    /**
     * Purge-list key for a URL. `single|` purges the exact URL only.
     */
    protected function purgeKey(string|int $key, string $url): string
    {
        return "single|$key";
    }

    protected function post(string $endpoint, string $body): bool
    {
        $timeout = defined('KINSTAMU_CACHE_PURGE_TIMEOUT') ? (int) KINSTAMU_CACHE_PURGE_TIMEOUT : 5;
        $request = curl_init($endpoint);
        curl_setopt($request, CURLOPT_POST, true);
        curl_setopt($request, CURLOPT_POSTFIELDS, $body);
        curl_setopt($request, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($request, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($request, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($request, CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt($request, CURLOPT_TIMEOUT, $timeout);

        curl_exec($request);
        $errorCode = curl_errno($request);
        $responseCode = curl_getinfo($request, CURLINFO_HTTP_CODE);

        return $errorCode === 0 && $responseCode === 200;
    }

    public function flush(): bool
    {
        $response = wp_remote_get(self::CLEAR_ALL_PATH, [
            'sslverify' => false,
            'timeout' => 5,
        ]);

        return ! is_wp_error($response);
    }
}
