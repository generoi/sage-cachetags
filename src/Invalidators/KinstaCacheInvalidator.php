<?php

namespace Genero\Sage\CacheTags\Invalidators;

use Genero\Sage\CacheTags\Contracts\Invalidator;

class KinstaCacheInvalidator implements Invalidator
{
    const IMMEDIATE_PATH = 'https://localhost/kinsta-clear-cache/v2/immediate';
    const CLEAR_ALL_PATH = 'https://localhost/kinsta-clear-cache-all';

    public function clear(array $urls): bool
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
        $response = wp_remote_post(self::IMMEDIATE_PATH, [
            'sslverify' => false,
            'timeout' => 5,
            'body' => $purgeRequest,
        ]);

        return !is_wp_error($response);
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
