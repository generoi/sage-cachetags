<?php

use Genero\Sage\CacheTags\Actions\Core;
use Genero\Sage\CacheTags\Actions\DebugComment;
use Genero\Sage\CacheTags\Actions\Nonce;
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

    // The WooCommerce, Polylang and Gravity Forms actions are auto-enabled when
    // their plugin is active — cart/checkout/account stay uncached, language-aware
    // purging, and prepopulated-form handling. Set false to manage 'action'
    // entirely yourself.
    'auto-detect-actions' => true,

    // A tag on every cacheable page + REST response, so one purge
    // (`wp cachetags clear page`) clears all WordPress-served pages while static
    // assets stay cached. Set to null to disable, or rename it. (Wired by the
    // BaseTag action automatically from this value — it isn't listed in 'action'.)
    'base-tag' => 'page',

    // Daily store garbage collection: prune cache_tags rows whose URL hasn't been
    // rendered within this age (12h / 30d / 4w), so query-string / bot / campaign
    // variants don't accumulate forever. A row's age is "last seen", so live pages
    // are never pruned. MUST exceed your edge cache's max TTL — pruning a URL still
    // cached at the edge leaves an object you can't purge by tag. null to disable.
    'prune-older-than' => '30d',

    'action' => [
        Core::class,
        DebugComment::class,

        // Purge pages tagged 'nonce' twice daily so a cached page never serves an
        // expired nonce. Light cron; remove this to opt out.
        Nonce::class,

        // Emit the cache-tag HTTP header (above) for surrogate-key CDNs like
        // Fastly. Not needed for URL/store-based purging (e.g. WP Super Cache).
        // \Genero\Sage\CacheTags\Actions\HttpHeader::class,

        // WooCommerce / Polylang / Gravity Forms are added automatically when
        // active (see auto-detect-actions above); list them here to force them on.

        // Tag REST API read responses for headless/decoupled setups. Keep Core
        // enabled alongside it so block-derived tags are collected too.
        // \Genero\Sage\CacheTags\Actions\RestApi::class,

        // Zero-config: tag every post/term fetched via WP_Query / get_the_terms
        // instead of wiring tags per template or block. Complements Core.
        // \Genero\Sage\CacheTags\Actions\AutoTag::class,

        // Serve cached pages to logged-in customers/subscribers (admin bar
        // hidden, identical catalog content). Only enable when the theme renders
        // no per-user markup server-side and the edge stops bypassing their
        // login cookie. Cart/checkout/account still bypass.
        // \Genero\Sage\CacheTags\Actions\CacheCustomers::class,
    ],
];
