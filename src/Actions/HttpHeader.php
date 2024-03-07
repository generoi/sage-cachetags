<?php

namespace Genero\Sage\CacheTags\Actions;

use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Contracts\Action;
use Roots\Acorn\Application;
use WP_REST_Response;

class HttpHeader implements Action
{
    protected Application $app;
    protected CacheTags $cacheTags;

    public function __construct(Application $app, CacheTags $cacheTags)
    {
        $this->app = $app;
        $this->cacheTags = $cacheTags;
    }

    public function bind(): void
    {
        if (! $this->app->config->get('cachetags.http-header')) {
            return;
        }

        // Core registers wp_ob_end_flush_all() as a shutdown action
        ob_start();

        \add_action('wp_footer', [$this, 'addHttpHeader']);
        \add_filter('rest_post_dispatch', [$this, 'restPostDispatch']);
    }

    public function addHttpHeader(): void
    {
        if ($header = $this->app->config->get('cachetags.http-header')) {
            header(sprintf(
                '%s: %s',
                $header,
                collect($this->cacheTags->get())->join(' ')
            ));
        }
    }

    /**
     * REST API response headers
     */
    public function restPostDispatch(WP_REST_Response $response): WP_REST_Response
    {
        if ($header = $this->app->config->get('cachetags.http-header')) {
            $headers = $response->get_headers();
            $headers[$header] = collect($this->cacheTags->get())->join(' ');
            $response->set_headers($headers);
        }

        return $response;
    }
}
