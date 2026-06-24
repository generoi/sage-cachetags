<?php

namespace Genero\Sage\CacheTags\Actions;

use Genero\Sage\CacheTags\Contracts\Action;
use Genero\Sage\CacheTags\Util;
use WP_REST_Request;
use WP_REST_Response;

class HttpHeader extends AbstractAction
{
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
            $this->emit($header, implode(' ', $tags));
        }
    }

    /**
     * Send the response header. Runs late (wp_footer, after tags are collected,
     * with output buffered in bind()), so it uses header() directly; isolated
     * here so the surrounding gating/building can be tested without it.
     */
    protected function emit(string $header, string $value): void
    {
        header(sprintf('%s: %s', $header, $value));
    }

    /**
     * REST API response headers.
     *
     * Only emitted for responses that may be publicly cached, and only when
     * there are tags to emit.
     */
    public function restPostDispatch($response, $server = null, ?WP_REST_Request $request = null)
    {
        // Gate exactly as Bootstrap::saveCacheTagsRest does, so a Cache-Tag
        // header is never emitted for a response whose URL we don't also store —
        // that desync would cache a page at the edge that no purge could clear.
        if (! $response instanceof WP_REST_Response) {
            return $response;
        }

        if (! $request instanceof WP_REST_Request || ! Util::isCacheableRestRequest($request)) {
            return $response;
        }

        if (! Util::isCacheableRestResponse($response)) {
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
