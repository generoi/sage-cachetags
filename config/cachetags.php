<?php

use Genero\Sage\CacheTags\Actions\Core;
use Genero\Sage\CacheTags\Actions\DebugComment;
use Genero\Sage\CacheTags\Actions\Gravityform;
use Genero\Sage\CacheTags\Invalidators\SuperCacheInvalidator;
use Genero\Sage\CacheTags\Stores\WordpressDbStore;

return [
    'disable' => false,
    'debug' => defined('WP_DEBUG') ? WP_DEBUG : false,
    'http-header' => 'Cache-Tag',
    'store' => WordpressDbStore::class,
    'invalidator' => [
        SuperCacheInvalidator::class,
    ],
    'action' => [
        Core::class,
        DebugComment::class,
        Gravityform::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Nonce Cron
    |--------------------------------------------------------------------------
    |
    | When enabled, WP-Cron will purge cache for pages tagged with 'nonce'
    | every 12 hours. Enable this when using forms with file uploads (e.g.
    | Gravity Forms) that are cached, since nonces expire after 12-24 hours.
    |
    */
    'nonce-cron' => false,
];
