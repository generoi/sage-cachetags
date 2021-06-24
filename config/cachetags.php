<?php

use Genero\Sage\CacheTags\Actions\Core;
use Genero\Sage\CacheTags\Actions\DebugComment;
use Genero\Sage\CacheTags\Actions\Gravityform;
use Genero\Sage\CacheTags\Invalidators\SuperCacheInvalidator;
use Genero\Sage\CacheTags\Stores\WordpressDbStore;

return [
    'debug' => defined('WP_DEBUG') ? WP_DEBUG : false,
    'http-header' => 'Cache-Tag',
    'store' => WordpressDbStore::class,
    'invalidator' => [
        SuperCacheInvalidator::class
    ],
    'action' => [
        Core::class,
        DebugComment::class,
        Gravityform::class,
    ]
];
