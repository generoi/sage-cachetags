<?php

namespace Genero\Sage\CacheTags\Tests;

use Genero\Sage\CacheTags\CacheTags;
use ReflectionObject;
use WP_UnitTestCase;

/**
 * Base case for tests that exercise the request-scoped tag accumulator.
 *
 * The CacheTags instance is a process-wide singleton that accumulates tags as
 * a request renders. In production that lasts one request; across a test run
 * many simulated requests share the process, so the accumulated and queued
 * tags are reset before each test to keep them isolated.
 */
abstract class RestTestCase extends WP_UnitTestCase
{
    protected CacheTags $cacheTags;

    public function set_up(): void
    {
        parent::set_up();

        // Rebuild the REST server for each test. WordPress backs up hooks in
        // set_up and restores them in tear_down, which strips the action's
        // rest_prepare_* filters (added when the server is first built). The
        // server is a persistent global, so without this reset rest_api_init
        // never re-fires to re-register them — exactly as it does per request.
        $GLOBALS['wp_rest_server'] = null;

        $this->cacheTags = CacheTags::getInstance();
        $this->resetCacheTags();
    }

    public function tear_down(): void
    {
        $GLOBALS['wp_rest_server'] = null;

        parent::tear_down();
    }

    /**
     * Clear the singleton's accumulated and queued tags.
     */
    protected function resetCacheTags(): void
    {
        $reflection = new ReflectionObject($this->cacheTags);

        foreach (['cacheTags', 'purgeTags'] as $property) {
            $prop = $reflection->getProperty($property);
            $prop->setAccessible(true);
            $prop->setValue($this->cacheTags, []);
        }
    }

    /**
     * Cache tags emitted on a REST response by the HttpHeader action.
     *
     * @return string[]
     */
    protected function cacheTagHeader(\WP_REST_Response $response): array
    {
        $header = $response->get_headers()['Cache-Tag'] ?? '';

        return $header === '' ? [] : explode(' ', $header);
    }

    /**
     * URLs stored against a given tag.
     *
     * @return string[]
     */
    protected function storedUrls(string $tag): array
    {
        global $wpdb;

        return $wpdb->get_col($wpdb->prepare(
            "SELECT url FROM {$wpdb->prefix}cache_tags WHERE tag = %s",
            $tag
        ));
    }

    /**
     * Expected store key for a route. Deliberately re-derives the canonical URL
     * independently of Bootstrap::restUrl() so assertions can't pass by testing
     * the production code against itself; keep the two in lockstep.
     */
    protected function storedUrl(string $route, array $queryParams = []): string
    {
        $url = rest_url($route);

        if ($queryParams) {
            ksort($queryParams);
            $url .= '?'.http_build_query($queryParams);
        }

        return $url;
    }
}
