<?php

namespace Genero\Sage\CacheTags\Invalidators;

use Genero\Sage\CacheTags\Contracts\Invalidator;

class KinstaCacheInvalidator implements Invalidator
{
    const IMMEDIATE_PATH = 'https://localhost/kinsta-clear-cache/v2/immediate';
    const CLEAR_ALL_PATH = 'https://localhost/kinsta-clear-cache-all';
    const POST_MAX_BODY_SIZE = 51200;

    public function clear(array $urls, array $tags): bool
    {
        if (defined('KINSTAMU_DISABLE_AUTOPURGE') && KINSTAMU_DISABLE_AUTOPURGE === true) {
            return false;
        }

        $purgeRequest = collect($urls)
            ->map(fn ($url) => str_replace(['http://', 'https://'], '', $url))
            ->mapWithKeys(function ($url, $key) {
                return ["single|$key" => $url];
            })
            ->all();

        $purgeRequest = apply_filters('KinstaCache/purgeImmediate', $purgeRequest);

        $requests = collect($this->chunkRequest($purgeRequest, self::POST_MAX_BODY_SIZE));
        if ($requests->count() > 3) {
            $result = $this->flush();
        } else {
            $result = $requests
                ->map(fn  ($chunk) => $this->post(self::IMMEDIATE_PATH, $chunk))
                ->reduce(fn (bool $result, bool $purgeResult) => $purgeResult ? $result : false, true);
        }
        return $result;
    }

    public function chunkRequest(array $request, int $maxSize): array
    {
        $chunks = [];
        $parts = explode('&', http_build_query($request));
        $inProgressChunk = '';

        foreach ($parts as $part) {
            // The in progress chunk _if_ the part would be added
            $chunk = $inProgressChunk ? $inProgressChunk . '&' . $part : $part;
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
        curl_close($request);

        return $errorCode === 0 && $responseCode === 200;
    }


    public function flush(): bool
    {
        $response = wp_remote_get(self::CLEAR_ALL_PATH, [
            'sslverify' => false,
            'timeout' => 5,
        ]);
        return !is_wp_error($response);
    }
}
