<?php

namespace Genero\Sage\CacheTags\Actions;

use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Contracts\Action;
use WP_REST_Response;

class HttpHeader implements Action
{
    public function __construct(protected CacheTags $cacheTags) {}

    public function bind(): void
    {
        if (! $this->cacheTags->httpHeader) {
            return;
        }

        // Core registers wp_ob_end_flush_all() as a shutdown action
        ob_start();

        \add_action('wp_footer', [$this, 'addHttpHeader']);
        \add_filter('rest_post_dispatch', [$this, 'restPostDispatch']);
    }

    public function addHttpHeader(): void
    {
        if ($header = $this->cacheTags->httpHeader) {
            header(sprintf(
                '%s: %s',
                $header,
                implode(' ', $this->cacheTags->get())
            ));
        }
    }

    /**
     * REST API response headers
     */
    public function restPostDispatch(WP_REST_Response $response): WP_REST_Response
    {
        if ($header = $this->cacheTags->httpHeader) {
            $headers = $response->get_headers();
            $headers[$header] = implode(' ', $this->cacheTags->get());
            $response->set_headers($headers);
        }

        return $response;
    }
}
