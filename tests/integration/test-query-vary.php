<?php

use Genero\Sage\CacheTags\Actions\FacetWP;
use Genero\Sage\CacheTags\Actions\Polylang;
use Genero\Sage\CacheTags\Actions\QueryVary;
use Genero\Sage\CacheTags\Bootstrap;
use Genero\Sage\CacheTags\CacheTags;

/**
 * Including known query params in the front-end + REST cache key, and the
 * actions that contribute them.
 *
 * @covers \Genero\Sage\CacheTags\Actions\QueryVary
 * @covers \Genero\Sage\CacheTags\Bootstrap::frontendUrl
 * @covers \Genero\Sage\CacheTags\Bootstrap::restUrl
 */
class TestQueryVary extends WP_UnitTestCase
{
    private Bootstrap $bootstrap;

    private array $get;

    public function set_up(): void
    {
        parent::set_up();
        $this->set_permalink_structure('/%postname%/');
        $this->bootstrap = new Bootstrap;
        $this->get = $_GET;
        global $wp;
        $wp->request = 'shop';
    }

    public function tear_down(): void
    {
        $_GET = $this->get;
        parent::tear_down();
    }

    private function invoke(string $method, ...$args)
    {
        $ref = (new ReflectionObject($this->bootstrap))->getMethod($method);
        $ref->setAccessible(true);

        return $ref->invoke($this->bootstrap, ...$args);
    }

    private function queryVary(): QueryVary
    {
        return new QueryVary(CacheTags::getInstance());
    }

    // --- Shared URL-building mechanism -------------------------------------

    public function test_front_end_url_is_path_only_by_default(): void
    {
        $_GET = ['orderby' => 'price'];

        $url = $this->invoke('frontendUrl');

        $this->assertStringEndsWith('/shop/', $url);
        $this->assertStringNotContainsString('?', $url);
    }

    public function test_front_end_url_includes_allowed_params(): void
    {
        $_GET = ['orderby' => 'price', 'junk' => 'x'];
        add_filter(Bootstrap::FILTER_ALLOWED_PARAMS, fn () => ['orderby']);

        $url = $this->invoke('frontendUrl');

        $this->assertStringEndsWith('/shop/?orderby=price', $url);
        $this->assertStringNotContainsString('junk', $url);
    }

    public function test_rest_url_keeps_allowed_params_even_when_unregistered(): void
    {
        add_filter(Bootstrap::FILTER_ALLOWED_PARAMS, fn () => ['lang']);
        $request = new WP_REST_Request('GET', '/wp/v2/posts');
        $request->set_query_params(['lang' => 'fr', 'junk' => 'x', 'page' => '2']);
        $request->set_attributes(['args' => ['page' => []]]);

        $url = $this->invoke('restUrl', $request);

        $this->assertStringEndsWith('/wp-json/wp/v2/posts?lang=fr&page=2', $url);
        $this->assertStringNotContainsString('junk', $url);
    }

    // --- QueryVary: core params, gated to listing views --------------------

    public function test_query_vary_adds_core_params_on_listing_views(): void
    {
        $this->go_to(home_url('/?s=hello'));

        $params = $this->queryVary()->allowedParams([]);

        foreach (['s', 'orderby', 'order', 'paged'] as $param) {
            $this->assertContains($param, $params);
        }
    }

    public function test_query_vary_skips_core_params_on_singular_views(): void
    {
        $postId = self::factory()->post->create(['post_status' => 'publish']);
        $this->go_to(get_permalink($postId));

        $this->assertSame([], $this->queryVary()->allowedParams([]));
    }

    // --- Integration actions contribute their own params -------------------

    public function test_polylang_action_contributes_lang(): void
    {
        $params = (new Polylang(CacheTags::getInstance()))->allowLanguageParam([]);

        $this->assertSame(['lang'], $params);
    }

    public function test_facetwp_action_is_a_passthrough_without_facetwp(): void
    {
        $params = (new FacetWP(CacheTags::getInstance()))->allowFacetParams(['existing']);

        $this->assertSame(['existing'], $params);
    }
}
