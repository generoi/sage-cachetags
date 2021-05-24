<?php

namespace Genero\Sage\CacheTags;

use Genero\Sage\CacheTags\Contracts\Invalidator;
use Genero\Sage\CacheTags\Contracts\Store;
use Genero\Sage\CacheTags\Stores\WordpressDbStore;
use Roots\Acorn\ServiceProvider;
use WP_Post;

class CacheTagsServiceProvider extends ServiceProvider
{
    /**
     * @var CacheTags $cacheTags
     */
    protected $cacheTags;

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton(CacheTags::class);

        $this->app->when(CacheTags::class)
            ->needs(Invalidator::class)
            ->give($this->app->config->get('cachetags.invalidator'));

        $this->app->when(CacheTags::class)
            ->needs(Store::class)
            ->give($this->app->config->get('cachetags.store', WordpressDbStore::class));
    }

    public function bindActions(): void
    {
        \add_action('wp_footer', [$this, 'saveCacheTags']);
        \add_action('transition_post_status', [$this, 'transitionPostStatus'], 10, 3);
        // \add_action('updated_post_meta', [$this, 'updatedPostMeta'], 10, 2);
        // \add_action('update_option', [$this, 'flush']);
        // \add_action('wp_update_nav_menu', [$this, 'flush']);

        if ($this->app->config->get('cachetags.debug')) {
            \add_action('wp_footer', [$this, 'printCacheTagsDebug']);
        }
    }

    public function savedTerm(int $termId, int $taxonomyId, string $taxonomy, bool $updated): void
    {
        $postIds = \get_posts([
            'post_type' => 'any',
            'tax_query' => [
                ['taxonomy' => $taxonomy, 'terms' => $termId],
            ],
            'posts_per_page' => -1,
            'fields' => 'ids',
        ]);

        $cacheTags = [
            ...CacheTags::getTermCacheTags($termId),
            ...CacheTags::getMultiplePostCacheTags($postIds),
            // @TODO: Other types
            ...CacheTags::getArchiveCacheTags(\get_taxonomy($taxonomy)->object_type),
        ];

        $this->cacheTags->clear($cacheTags);
    }

    public function updatedPostMeta(int $metaId, int $objectId): void
    {
        $this->app->make(CacheTags::class)->clear([
            ...CacheTags::getPostCacheTags($objectId),
        ]);
    }

    public function transitionPostStatus(string $newStatus, string $oldStatus, WP_Post $post): void
    {
        $cacheTags = [
            ...CacheTags::getPostCacheTags($post->ID),
        ];

        if ($newStatus === 'publish' && $newStatus !== $oldStatus) {
            $cacheTags = [
                ...$cacheTags,
                ...CacheTags::getArchiveCacheTags($post->post_type) // @TODO: pagination
            ];
        }
        // @TODO: Trash and trash slugs.

        $this->cacheTags->clear($cacheTags);
    }

    /**
     * Save the cache tags used on the rendered page.
     */
    public function saveCacheTags(): void
    {
        $this->cacheTags->save($this->currentUrl());

        if ($header = $this->app->config->get('cachetags.http-header')) {
            header(sprintf(
                '%s: %s',
                $header,
                collect($this->cacheTags->get())->join(',')
            ));
        }
    }

    public function printCacheTagsDebug(): void
    {
        echo sprintf("
            <!-- sage-cachetags
            Url: %s
            Tags: %s
            -->
        ", $this->currentUrl(), collect($this->cacheTags->get())->join(', '));
    }

    public function flush(): void
    {
        $this->cacheTags->flush();
    }

    /**
     * Return the current page url.
     */
    protected function currentUrl(): string
    {
        global $wp;
        return \home_url($wp->request);
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->cacheTags = $this->app->make(CacheTags::class);

        $this->bindActions();

        $this->publishes([
            __DIR__ . '/../config/cachetags.php' => $this->app->configPath('cachetags.php'),
        ], 'config');
    }
}
