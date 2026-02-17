<?php

namespace Genero\Sage\CacheTags;

use Genero\Sage\CacheTags\Console\DatabaseCommand;
use Genero\Sage\CacheTags\Console\FlushCommand;
use Genero\Sage\CacheTags\Stores\WordpressDbStore;
use Illuminate\Support\ServiceProvider;

class CacheTagsServiceProvider extends ServiceProvider
{
    /**
     * @var CacheTags
     */
    protected $cacheTags;

    public function register(): void
    {
        $this->app->singleton(CacheTags::class, fn () => $this->createBootstrap()->bootstrap());
    }

    public function boot(): void
    {
        $this->cacheTags = $this->app->make(CacheTags::class);

        $this->publishes([
            __DIR__.'/../config/cachetags.php' => $this->app->configPath('cachetags.php'),
        ], 'config');

        $this->commands([
            DatabaseCommand::class,
            FlushCommand::class,
        ]);
    }

    protected function createBootstrap(): Bootstrap
    {
        $config = $this->app->config;

        $bootstrap = new Bootstrap(
            debug: $config->get('cachetags.debug', defined('WP_DEBUG') ? WP_DEBUG : false),
            httpHeader: $config->get('cachetags.http-header', 'Cache-Tag'),
            disable: $config->get('cachetags.disable', false),
            store: $config->get('cachetags.store', WordpressDbStore::class),
            invalidators: $config->get('cachetags.invalidator', []),
            actions: $config->get('cachetags.action', []),
            nonceCron: $config->get('cachetags.nonce-cron', false),
        );

        return $bootstrap;
    }
}
