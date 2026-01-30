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

        $cleanedUrls = array_map(
            fn ($url) => str_replace(['http://', 'https://'], '', $url),
            $urls
        );
        $purgeRequest = [];
        foreach ($cleanedUrls as $key => $url) {
            $purgeRequest["single|$key"] = $url;
        }

        $purgeRequest = apply_filters('KinstaCache/purgeImmediate', $purgeRequest);

        $requests = Util::chunkRequest($purgeRequest, self::POST_MAX_BODY_SIZE);
        if (count($requests) > 3) {
            $result = $this->flush();
        } else {
            $result = array_reduce(
                $requests,
                fn (bool $result, string $chunk) => $this->post(self::IMMEDIATE_PATH, $chunk) ? $result : false,
                true
            );
        }

        return $result;
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
