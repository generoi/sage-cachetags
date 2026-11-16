<?php

use Genero\Sage\CacheTags\Invalidators\KinstaCacheInvalidator;
use Genero\Sage\CacheTags\Invalidators\KinstaGroupCacheInvalidator;
use Genero\Sage\CacheTags\Util;

/**
 * @covers \Genero\Sage\CacheTags\Invalidators\KinstaCacheInvalidator
 * @covers \Genero\Sage\CacheTags\Invalidators\KinstaGroupCacheInvalidator
 */
class TestKinstaInvalidators extends WP_UnitTestCase
{
    private array $urls = [
        'shop' => 'https://example.com/shop/',
        'home' => 'https://example.com/',
    ];

    public function test_default_invalidator_purges_each_url_exactly(): void
    {
        $list = (new KinstaCacheInvalidator)->purgeList($this->urls);

        $this->assertSame([
            'single|shop' => 'example.com/shop/',
            'single|home' => 'example.com/',
        ], $list);
    }

    public function test_group_invalidator_prefix_purges_paths_but_not_the_root(): void
    {
        $list = (new KinstaGroupCacheInvalidator)->purgeList($this->urls);

        $this->assertSame([
            // Prefix purge clears /shop/, /shop/page/2/, /shop/?orderby=… at once.
            'group|shop' => 'example.com/shop/',
            // The root would match the whole site, so it stays exact.
            'single|home' => 'example.com/',
        ], $list);
    }

    // A group purge of a language home ("/sv/") would empty that whole language.
    public function test_group_invalidator_purges_exact_urls_exactly(): void
    {
        add_filter(KinstaGroupCacheInvalidator::FILTER_EXACT_URLS, fn ($urls) => [...$urls, 'https://example.com/sv/', '/fi/']);

        $list = (new KinstaGroupCacheInvalidator)->purgeList([
            'sv' => 'https://example.com/sv/',
            'sv-page' => 'https://example.com/sv/produkter/',
            'fi' => 'https://other.example/fi',
            'sv-other-host' => 'https://other.example/sv/',
        ]);

        $this->assertSame([
            'single|sv' => 'example.com/sv/',
            // Beneath a language home stays a prefix purge.
            'group|sv-page' => 'example.com/sv/produkter/',
            // A host-less path matches on any host, with or without the slash.
            'single|fi' => 'other.example/fi',
            // A full URL only matches its own host.
            'group|sv-other-host' => 'other.example/sv/',
        ], $list);
    }

    public function test_exact_urls_default_to_the_site_and_language_homes(): void
    {
        $urls = (new KinstaGroupCacheInvalidator)->exactUrls();

        $this->assertContains(home_url('/'), $urls);
        if (function_exists('pll_languages_list')) {
            foreach (pll_languages_list() as $language) {
                $this->assertContains(pll_home_url($language), $urls, "{$language} home");
            }
        }
    }

    public function test_a_language_home_is_purged_exactly_with_polylang(): void
    {
        if (! function_exists('pll_home_url') || ! function_exists('PLL') || ! PLL() || ! isset(PLL()->model)) {
            $this->markTestSkipped('Polylang is not installed in this environment.');
        }
        $model = PLL()->model;
        if (! $model->get_language('fi')) {
            $model->add_language(['name' => 'Finnish', 'slug' => 'fi', 'locale' => 'fi', 'rtl' => 0, 'term_group' => 1, 'flag' => 'fi']);
            $model->clean_languages_cache();
        }

        $home = pll_home_url('fi');
        $list = (new KinstaGroupCacheInvalidator)->purgeList(['fi' => $home]);

        $this->assertSame(['single|fi'], array_keys($list));
    }

    /**
     * A switch_to_blog() loop purges several sites with one invalidator; each
     * must use its own site's homes, not the first site's.
     *
     * @group multisite
     */
    public function test_exact_urls_are_resolved_per_site(): void
    {
        if (! is_multisite()) {
            $this->markTestSkipped('Requires multisite (run via the multisite CI job).');
        }

        // The converted test network has ms_files_rewriting on, whose upload
        // path needs the legacy UPLOADBLOGSDIR constant to create a site.
        update_site_option('ms_files_rewriting', 0);
        $blogId = self::factory()->blog->create(['path' => '/cachetags-sub/']);
        $subHome = get_home_url($blogId, '/');
        $invalidator = new KinstaGroupCacheInvalidator;

        $this->assertSame(['group|sub'], array_keys($invalidator->purgeList(['sub' => $subHome])), 'a path on the main site');

        switch_to_blog($blogId);
        try {
            $this->assertSame(['single|sub'], array_keys($invalidator->purgeList(['sub' => $subHome])), "the subsite's home");
        } finally {
            restore_current_blog();
        }
    }

    // Kinsta serves these from the bare URL's entry; other edges (SiteGround,
    // WP Rocket, Super Cache) cache ?ref= as its own key, so only Kinsta adds them.
    public function test_kinsta_invalidators_ignore_the_params_kinstas_edge_leaves_out_of_its_key(): void
    {
        $this->assertNotContains('ref', Util::IGNORED_URL_PARAMS, 'not ignored for every edge');
        $this->assertNotContains('ref', apply_filters('cachetags/url-ignored-params', Util::IGNORED_URL_PARAMS));

        new KinstaCacheInvalidator;

        $ignored = apply_filters('cachetags/url-ignored-params', Util::IGNORED_URL_PARAMS);
        foreach (['_ga', 'mc_cid', 'mc_eid', 'ref', 'utm_source'] as $param) {
            $this->assertContains($param, $ignored, $param);
        }
    }

    public function test_the_group_invalidator_ignores_them_too(): void
    {
        new KinstaGroupCacheInvalidator;

        $this->assertContains('mc_cid', apply_filters('cachetags/url-ignored-params', Util::IGNORED_URL_PARAMS));
    }

    public function test_group_invalidator_disables_query_string_storage(): void
    {
        new KinstaGroupCacheInvalidator;

        $this->assertFalse(apply_filters('cachetags/store-query-string', true));
    }

    public function test_clear_posts_the_purge_list_to_the_immediate_endpoint(): void
    {
        $invalidator = new class extends KinstaCacheInvalidator
        {
            public array $posts = [];

            protected function post(string $endpoint, string $body): bool
            {
                $this->posts[] = [$endpoint, $body];

                return true;
            }
        };

        $this->assertTrue($invalidator->clear($this->urls, ['post:1']));
        $this->assertCount(1, $invalidator->posts, 'small purge fits one request');
        $this->assertSame(KinstaCacheInvalidator::IMMEDIATE_PATH, $invalidator->posts[0][0]);
        $this->assertStringContainsString('example.com', urldecode($invalidator->posts[0][1]));
    }

    public function test_clear_bulk_routes_to_the_throttled_endpoint_not_a_full_flush(): void
    {
        $invalidator = new class extends KinstaCacheInvalidator
        {
            public bool $flushed = false;

            /** @var string[] */
            public array $endpoints = [];

            protected function post(string $endpoint, string $body): bool
            {
                $this->endpoints[] = $endpoint;

                return true;
            }

            public function flush(): bool
            {
                $this->flushed = true;

                return true;
            }
        };

        $urls = [];
        for ($i = 0; $i < 6000; $i++) {
            $urls["key{$i}"] = "https://example.com/some/longer/path/number-{$i}/";
        }

        $this->assertTrue($invalidator->clear($urls, ['post:1']));
        $this->assertFalse($invalidator->flushed, 'a bulk purge must NOT nuke the whole cache');
        $this->assertGreaterThan(KinstaCacheInvalidator::IMMEDIATE_MAX_REQUESTS, count($invalidator->endpoints));
        $this->assertSame(
            [KinstaCacheInvalidator::THROTTLED_PATH],
            array_values(array_unique($invalidator->endpoints)),
            'bulk chunks all go to the throttled endpoint',
        );
    }

    public function test_flush_calls_the_clear_all_endpoint(): void
    {
        add_filter('pre_http_request', fn () => ['response' => ['code' => 200], 'body' => '']);

        $this->assertTrue((new KinstaCacheInvalidator)->flush());
    }

    public function test_clear_runs_the_real_curl_request_and_logs_failure_when_unreachable(): void
    {
        // The Kinsta MU endpoint (https://localhost/kinsta-clear-cache/...) isn't
        // reachable from the test runner, so the real cURL in post() executes and
        // returns a connection failure — exercising the actual request code path
        // rather than a subclass override, and surfacing it for diagnosis.
        $invalidator = new class extends KinstaCacheInvalidator
        {
            /** @var array<int, array<string, mixed>> */
            public array $failures = [];

            protected function logPurgeFailure(string $endpoint, int $status, int $errno, string $error): void
            {
                $this->failures[] = ['endpoint' => $endpoint, 'status' => $status, 'errno' => $errno];
            }
        };

        $result = $invalidator->clear(['https://example.com/a/'], ['post:1']);

        $this->assertFalse($result);
        $this->assertNotEmpty($invalidator->failures, 'a failed purge is logged so it can be noticed');
        $this->assertNotSame(0, $invalidator->failures[0]['errno'], 'the connection error is captured');
    }
}
