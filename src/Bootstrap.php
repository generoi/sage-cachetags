<?php

namespace Genero\Sage\CacheTags;

use Genero\Sage\CacheTags\Actions\Core;
use Genero\Sage\CacheTags\Concerns\CreatesDatabaseTable;
use Genero\Sage\CacheTags\Contracts\Action;
use Genero\Sage\CacheTags\Contracts\Invalidator;
use Genero\Sage\CacheTags\Contracts\Store;
use Genero\Sage\CacheTags\Stores\WordpressDbStore;
use WP_REST_Request;
use WP_Site;

/**
 * Bootstrap CacheTags for standalone WordPress usage (without Acorn).
 */
class Bootstrap
{
    use CreatesDatabaseTable;

    /**
     * Query parameters that never change the cached representation and so are
     * excluded from the stored REST URL. Deliberately minimal: stripping a
     * param risks collapsing two distinct CDN entries into one store key (so a
     * purge would miss one), while keeping a param is always purge-safe. Only
     * these random per-request values are dropped — any cache entry keyed on
     * them is never reused, so collapsing them can't cause staleness, and
     * keeping them would just bloat the store with dead keys. Everything else
     * (incl. context, which changes the body) is cached separately.
     */
    const IGNORED_QUERY_PARAMS = ['_wpnonce', '_'];

    /**
     * Server parameters that DO change the response body, so they belong in the
     * cache key even though they aren't registered route arguments.
     */
    const RESPONSE_QUERY_PARAMS = ['_embed', '_fields', '_envelope', '_locale'];

    const FILTER_URL_IGNORED_PARAMS = 'cachetags/rest-url-ignored-params';

    protected bool $debug;

    protected ?CacheTags $cacheTags = null;

    public function __construct(
        ?bool $debug = null,
        protected ?string $httpHeader = 'Cache-Tag',
        protected bool $disable = false,
        protected string|Store $store = WordpressDbStore::class,
        protected array $invalidators = [],
        /** @var Action[] */
        protected array $actions = [Core::class],
        protected bool $nonceCron = false,
        protected int $storeClearDelay = 0,
    ) {
        $this->debug = $debug ?? (defined('WP_DEBUG') ? WP_DEBUG : false);
    }

    /**
     * Set the store implementation.
     */
    public function store(string|Store $store): static
    {
        $this->store = $store;

        return $this;
    }

    /**
     * Set invalidators.
     */
    public function invalidators(string|Invalidator|array $invalidators): static
    {
        $this->invalidators = [...(is_array($invalidators) ? $invalidators : [$invalidators])];

        return $this;
    }

    /**
     * Set actions.
     */
    public function actions(string|Action|array $actions): static
    {
        $this->actions = [...(is_array($actions) ? $actions : [$actions])];

        return $this;
    }

    /**
     * Set debug mode.
     */
    public function debug(bool $debug): static
    {
        $this->debug = $debug;

        return $this;
    }

    /**
     * Set HTTP header name.
     */
    public function httpHeader(?string $header): static
    {
        $this->httpHeader = $header;

        return $this;
    }

    /**
     * Disable cache tags.
     */
    public function disable(bool $disable = true): static
    {
        $this->disable = $disable;

        return $this;
    }

    public function nonceCron(bool $enable = true): static
    {
        $this->nonceCron = $enable;

        return $this;
    }

    public function storeClearDelay(int $seconds): static
    {
        $this->storeClearDelay = $seconds;

        return $this;
    }

    /**
     * Bootstrap CacheTags and return the instance.
     */
    public function bootstrap(): CacheTags
    {
        $store = $this->resolve($this->store, Store::class);

        if ($this->storeClearDelay > 0) {
            $store = new Stores\DeferredClearStore($store, $this->storeClearDelay);
        }

        $this->cacheTags = CacheTags::make(
            store: $store,
            debug: $this->debug,
            httpHeader: $this->httpHeader,
            invalidators: array_map(
                fn ($invalidator) => $this->resolve($invalidator, Invalidator::class),
                array_filter($this->invalidators)
            ),
        );

        // Bind actions
        $this->bindActions();

        // Register WP-CLI commands if available
        $this->registerWpCliCommands();

        // Ensure the cache tags table is created for newly added multisite subsites.
        // The activation hook only provisions sites existing at activation time.
        if (is_multisite()) {
            add_action('wp_initialize_site', [$this, 'createTableForNewSite'], 100);
        }

        // Apply schema migrations for existing installs — activation hooks don't
        // re-run on plugin updates. Runs per-site in admin; `wp cachetags
        // database` covers headless/network upgrades.
        add_action('admin_init', [$this, 'upgradeTable']);

        // Set up WordPress hooks
        if (! $this->disable) {
            add_action('wp_footer', [$this, 'saveCacheTags']);
            add_filter('rest_post_dispatch', [$this, 'saveCacheTagsRest'], 10, 3);
            add_action('shutdown', [$this, 'purgeCacheTags']);

            if ($this->cacheTags->store instanceof Stores\DeferredClearStore) {
                $this->cacheTags->store->register();
            }

            if ($this->nonceCron) {
                NonceCron::register();
            } else {
                NonceCron::unschedule();
            }
        } else {
            NonceCron::unschedule();
        }

        return $this->cacheTags;
    }

    protected function registerWpCliCommands(): void
    {
        if (! defined('WP_CLI') || ! WP_CLI || ! class_exists('WP_CLI')) {
            return;
        }

        \WP_CLI::add_command('cachetags database', WpCli\DatabaseCommand::class);
        \WP_CLI::add_command('cachetags flush', WpCli\FlushCommand::class);
        \WP_CLI::add_command('cachetags clear', WpCli\ClearCommand::class);
    }

    protected function bindActions(): void
    {
        // Bind all actions
        $actions = array_map(
            fn ($action) => match (true) {
                is_string($action) => new $action($this->cacheTags),
                $action instanceof Action => $action,
                default => throw new \InvalidArgumentException('Action must implement '.Action::class),
            },
            array_filter($this->actions)
        );

        foreach ($actions as $action) {
            $this->cacheTags->bindAction($action);
        }
    }

    /**
     * Create the cache tags table for a newly initialized multisite subsite.
     */
    public function createTableForNewSite(WP_Site $newSite): void
    {
        switch_to_blog((int) $newSite->blog_id);

        try {
            $this->createTable();
        } finally {
            restore_current_blog();
        }
    }

    public function saveCacheTags(): void
    {
        if (Util::isCacheableRequest()) {
            $this->cacheTags->save(Util::currentUrl());
        } elseif (! defined('DONOTCACHEPAGE')) {
            // Make the non-cacheable verdict (preview, logged-in, forms, cart,
            // …) actionable: page caches (WP Super Cache, Batcache, the theme
            // cache-control providers) honour DONOTCACHEPAGE. Edge caches key on
            // Cache-Control sent earlier, so their bypass must come from the
            // theme/VCL consulting Util::isCacheableRequest().
            define('DONOTCACHEPAGE', true);
        }
    }

    public function saveCacheTagsRest($response, $server, WP_REST_Request $request)
    {
        if (Util::isCacheableRestRequest($request)) {
            $this->cacheTags->save($this->restUrl($request));
        }

        return $response;
    }

    /**
     * Build the canonical URL a REST response is cached under.
     *
     * Query parameters that change the representation (e.g. page, per_page,
     * filters) are preserved and sorted so paginated/filtered collection
     * variants get distinct, stable store keys that match what a CDN caches.
     *
     * Only parameters the matched route actually registers are kept, so
     * arbitrary client-supplied params can't fork the cache key and bloat the
     * store. Parameters internal to the REST machinery are dropped too.
     */
    protected function restUrl(WP_REST_Request $request): string
    {
        $url = strtok(rest_url($request->get_route()), '?');
        $params = $request->get_query_params();

        // Keep parameters declared by the matched route plus the server params
        // that change the response body, so arbitrary client params can't fork
        // the cache key while representation-affecting ones are preserved.
        $registered = $request->get_attributes()['args'] ?? [];
        if (! empty($registered)) {
            $params = array_intersect_key($params, $registered + array_flip(self::RESPONSE_QUERY_PARAMS));
        }

        $ignored = apply_filters(self::FILTER_URL_IGNORED_PARAMS, self::IGNORED_QUERY_PARAMS);
        $params = array_diff_key($params, array_flip($ignored));

        if (empty($params)) {
            return $url;
        }

        ksort($params);

        return $url.'?'.http_build_query($params);
    }

    public function purgeCacheTags(): void
    {
        $this->cacheTags->purgeQueued();
    }

    /**
     * Resolve a class from string or return existing instance.
     *
     * @template T
     *
     * @param  string|T  $value
     * @param  class-string<T>  $contract
     * @return T
     */
    protected function resolve(string|object $value, string $contract): object
    {
        return match (true) {
            is_string($value) => new $value,
            $value instanceof $contract => $value,
            default => throw new \InvalidArgumentException("Value must implement {$contract}"),
        };
    }
}
