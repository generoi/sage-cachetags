<?php

namespace Genero\Sage\CacheTags;

use Genero\Sage\CacheTags\Actions\Core;
use Genero\Sage\CacheTags\Contracts\Action;
use Genero\Sage\CacheTags\Contracts\Invalidator;
use Genero\Sage\CacheTags\Contracts\Store;
use Genero\Sage\CacheTags\Stores\WordpressDbStore;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Bootstrap CacheTags for standalone WordPress usage (without Acorn).
 */
class Bootstrap
{
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

    /**
     * Bootstrap CacheTags and return the instance.
     */
    public function bootstrap(): CacheTags
    {
        $this->cacheTags = CacheTags::make(
            store: $this->resolve($this->store, Store::class),
            debug: $this->debug,
            httpHeader: $this->httpHeader,
            invalidators: array_map(
                fn ($invalidator) => $this->resolve($invalidator, Invalidator::class),
                array_filter($this->invalidators)
            )
        );

        // Bind actions
        $this->bindActions();

        // Register WP-CLI commands if available
        $this->registerWpCliCommands();

        // Set up WordPress hooks
        if (! $this->disable) {
            add_action('wp_footer', [$this, 'saveCacheTags']);
            add_filter('rest_post_dispatch', [$this, 'saveCacheTagsRest'], 10, 3);
            add_action('shutdown', [$this, 'purgeCacheTags']);

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

    public function saveCacheTags(): void
    {
        $this->cacheTags->save(Util::currentUrl());
    }

    public function saveCacheTagsRest(WP_REST_Response $response, $server, WP_REST_Request $request): WP_REST_Response
    {
        $route = $request->get_route();
        $restUrl = rest_url($route);
        $url = strtok($restUrl, '?');

        $this->cacheTags->save($url);

        return $response;
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
