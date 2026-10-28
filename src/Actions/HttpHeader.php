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

        \add_action('template_redirect', [$this, 'addPlaceholderHttpHeader'], PHP_INT_MAX);
        \add_action('wp_footer', [$this, 'addHttpHeader']);
        \add_filter('rest_post_dispatch', [$this, 'restPostDispatch'], 10, 3);
    }

    /**
     * Send the tags known before rendering (the site tag, with the Site action)
     * so a page whose render dies before wp_footer — a fatal after output, an
     * exit() mid-template — is still purgeable by a flush rather than cached
     * untagged until it expires. addHttpHeader() replaces or removes it.
     */
    public function addPlaceholderHttpHeader(): void
    {
        if (Util::isCacheableRequest()) {
            $this->emitTags();
        }
    }

    public function addHttpHeader(): void
    {
        // Decided during the render (a form, the cart): drop the placeholder too.
        if (! Util::isCacheableRequest() || ! $this->emitTags()) {
            foreach (array_keys($this->headers()) as $header) {
                $this->remove($header);
            }
        }
    }

    /**
     * Emit the current tags in every configured header; false when there are none.
     */
    protected function emitTags(): bool
    {
        $tags = $this->cacheTags->get();

        if (empty($tags)) {
            return false;
        }

        foreach ($this->headers() as $header => $separator) {
            $this->emit($header, implode($separator, $tags));
        }

        return true;
    }

    /**
     * The configured headers mapped to their tag separator. A header name on its
     * own (a string, or a list entry) is space-separated; map names to
     * separators to choose, e.g. ['Surrogate-Key' => ' ', 'Cache-Tag' => ',']
     * to feed Fastly and Cloudflare (which needs commas) at once.
     *
     * @return array<string, string>
     */
    protected function headers(): array
    {
        $headers = [];
        foreach ((array) $this->cacheTags->httpHeader as $name => $separator) {
            if (is_int($name)) {
                [$name, $separator] = [$separator, ' '];
            }
            if ($name) {
                $headers[$name] = $separator;
            }
        }

        return $headers;
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

    protected function remove(string $header): void
    {
        header_remove($header);
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

        if (! empty($tags) && $this->headers()) {
            $headers = $response->get_headers();
            foreach ($this->headers() as $header => $separator) {
                $headers[$header] = implode($separator, $tags);
            }
            $response->set_headers($headers);
        }

        return $response;
    }
}
