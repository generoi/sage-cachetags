<?php

namespace Genero\Sage\CacheTags\Actions;

use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Contracts\Action;
use Genero\Sage\CacheTags\Util;
use WP_REST_Request;
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
        \add_filter('rest_post_dispatch', [$this, 'restPostDispatch'], 10, 3);
    }

    public function addHttpHeader(): void
    {
        if (! Util::isCacheableRequest()) {
            return;
        }

        $tags = $this->cacheTags->get();
        $header = $this->cacheTags->httpHeader;

        if ($header && ! empty($tags)) {
            header(sprintf('%s: %s', $header, implode(' ', $tags)));
        }
    }

    /**
     * REST API response headers.
     *
     * Only emitted for responses that may be publicly cached, and only when
     * there are tags to emit.
     */
    public function restPostDispatch($response, $server = null, ?WP_REST_Request $request = null)
    {
        if (! $response instanceof WP_REST_Response) {
            return $response;
        }

        if ($request && ! Util::isCacheableRestRequest($request)) {
            return $response;
        }

        $tags = $this->cacheTags->get();
        $header = $this->cacheTags->httpHeader;

        if ($header && ! empty($tags)) {
            $headers = $response->get_headers();
            $headers[$header] = implode(' ', $tags);
            $response->set_headers($headers);
        }

        return $response;
    }
}
