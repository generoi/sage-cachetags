<?php

use Genero\Sage\CacheTags\Invalidators\KinstaCacheInvalidator;
use Genero\Sage\CacheTags\Invalidators\KinstaGroupCacheInvalidator;

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

    public function test_clear_bulk_flushes_when_it_would_take_more_than_three_requests(): void
    {
        $invalidator = new class extends KinstaCacheInvalidator
        {
            public bool $flushed = false;

            public int $postCount = 0;

            protected function post(string $endpoint, string $body): bool
            {
                $this->postCount++;

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
        $this->assertTrue($invalidator->flushed, 'over three chunks → single bulk flush');
        $this->assertSame(0, $invalidator->postCount, 'individual posts skipped');
    }

    public function test_flush_calls_the_clear_all_endpoint(): void
    {
        add_filter('pre_http_request', fn () => ['response' => ['code' => 200], 'body' => '']);

        $this->assertTrue((new KinstaCacheInvalidator)->flush());
    }

    public function test_clear_runs_the_real_curl_request_and_reports_failure_when_unreachable(): void
    {
        // The Kinsta MU endpoint (https://localhost/kinsta-clear-cache/...) isn't
        // reachable from the test runner, so the real cURL in post() executes and
        // returns a connection failure — exercising the actual request code path
        // rather than a subclass override.
        $result = (new KinstaCacheInvalidator)->clear(['https://example.com/a/'], ['post:1']);

        $this->assertFalse($result);
    }
}
