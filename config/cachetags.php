<?php

use Genero\Sage\CacheTags\Invalidators\SuperCacheInvalidator;
use Genero\Sage\CacheTags\Stores\WordpressDbStore;

return [
    'debug' => defined('WP_DEBUG') ? WP_DEBUG : false,
    'store' => WordpressDbStore::class,
    'invalidator' => [
        SuperCacheInvalidator::class
    ],
];
