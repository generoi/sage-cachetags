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

        // Tag REST API read responses for headless/decoupled setups. Keep Core
        // enabled alongside it so block-derived tags are collected too.
        // \Genero\Sage\CacheTags\Actions\RestApi::class,

        // Include known query params (search, sort, Polylang lang, FacetWP
        // selections) in the front-end + REST cache key; arbitrary params are
        // ignored. Opt-in for sites that know their GET parameters.
        // \Genero\Sage\CacheTags\Actions\QueryParams::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Store Clear Delay
    |--------------------------------------------------------------------------
    |
    | Delay in seconds before clearing the tag store after a successful purge.
    | When set to 0 (default), the store is cleared immediately. Setting a
    | delay (e.g. 60) prevents race conditions when multiple related posts
    | are trashed or updated in quick succession — each operation can still
    | find URLs in the store for its purge before the store is cleaned up.
    |
    */
    'store-clear-delay' => 0,

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
