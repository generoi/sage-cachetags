<?php

use Genero\Sage\CacheTags\Bootstrap;

/**
 * Canonical store-URL building for REST responses.
 *
 * @covers \Genero\Sage\CacheTags\Bootstrap::restUrl
 */
class TestRestUrl extends WP_UnitTestCase
{
    private ReflectionMethod $restUrl;

    private Bootstrap $bootstrap;

    public function set_up(): void
    {
        parent::set_up();

        // Headless sites use pretty permalinks; ensure rest_url() returns the
        // /wp-json/ form rather than the ?rest_route= fallback.
        $this->set_permalink_structure('/%postname%/');

        $this->bootstrap = new Bootstrap;
        $this->restUrl = (new ReflectionObject($this->bootstrap))->getMethod('restUrl');
        $this->restUrl->setAccessible(true);
    }

    private function restUrl(string $route, array $queryParams = []): string
    {
        $request = new WP_REST_Request('GET', $route);
        $request->set_query_params($queryParams);

        return $this->restUrl->invoke($this->bootstrap, $request);
    }

    public function test_collection_query_params_are_preserved_and_sorted(): void
    {
        $url = $this->restUrl('/wp/v2/posts', ['per_page' => '2', 'page' => '1']);

        $this->assertStringEndsWith('/wp-json/wp/v2/posts?page=1&per_page=2', $url);
    }

    public function test_machinery_params_are_dropped(): void
    {
        $url = $this->restUrl('/wp/v2/posts', ['_wpnonce' => 'abc', 'context' => 'view', 'page' => '2']);

        $this->assertStringEndsWith('/wp-json/wp/v2/posts?page=2', $url);
    }

    public function test_response_shaping_params_are_kept(): void
    {
        // _embed / _fields change the response body, so they belong in the key.
        $url = $this->restUrl('/wp/v2/posts', ['_embed' => '1', '_fields' => 'id', 'page' => '2']);

        $this->assertStringEndsWith('/wp-json/wp/v2/posts?_embed=1&_fields=id&page=2', $url);
    }

    public function test_filtered_variants_get_distinct_urls(): void
    {
        $page1 = $this->restUrl('/wp/v2/posts', ['page' => '1']);
        $page2 = $this->restUrl('/wp/v2/posts', ['page' => '2']);
        $category = $this->restUrl('/wp/v2/posts', ['categories' => '5']);

        $this->assertNotSame($page1, $page2);
        $this->assertNotSame($page1, $category);
    }

    public function test_no_query_string_when_only_ignored_params(): void
    {
        $url = $this->restUrl('/wp/v2/posts', ['_wpnonce' => 'abc', 'context' => 'view']);

        $this->assertStringEndsWith('/wp-json/wp/v2/posts', $url);
        $this->assertStringNotContainsString('?', $url);
    }

    public function test_unregistered_params_are_dropped_but_server_params_kept(): void
    {
        $request = new WP_REST_Request('GET', '/wp/v2/posts');
        $request->set_query_params(['page' => '2', 'cache_buster' => 'xyz', '_embed' => '1']);
        // Mirror what route matching sets during a real dispatch.
        $request->set_attributes(['args' => ['page' => []]]);

        $url = $this->restUrl->invoke($this->bootstrap, $request);

        $this->assertStringEndsWith('/wp-json/wp/v2/posts?_embed=1&page=2', $url);
        $this->assertStringNotContainsString('cache_buster', $url);
    }
}
