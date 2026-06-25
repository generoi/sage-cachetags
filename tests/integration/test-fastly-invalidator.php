<?php

use Genero\Sage\CacheTags\Invalidators\FastlyCacheInvalidator;
use Genero\Sage\CacheTags\Invalidators\FastlySoftCacheInvalidator;
use WpOrg\Requests\Response;

/**
 * @covers \Genero\Sage\CacheTags\Invalidators\FastlyCacheInvalidator
 * @covers \Genero\Sage\CacheTags\Invalidators\FastlySoftCacheInvalidator
 */
class TestFastlyInvalidator extends WP_UnitTestCase
{
    private array $requests = [];

    public function set_up(): void
    {
        parent::set_up();
        $this->requests = [];
        add_filter('pre_http_request', [$this, 'capture'], 10, 3);
    }

    public function capture($pre, $args, $url)
    {
        $this->requests[] = ['url' => $url, 'args' => $args];

        return ['response' => ['code' => 200], 'body' => '{}'];
    }

    public function test_clear_purges_by_surrogate_key_and_ignores_the_urls(): void
    {
        $ok = (new FastlyCacheInvalidator)->clear(
            ['https://example.com/a/', 'https://example.com/b/'],
            ['post:1', 'term:5'],
        );

        $this->assertTrue($ok);
        $this->assertCount(1, $this->requests, 'one purge call');
        $this->assertStringContainsString('/purge/', $this->requests[0]['url']);

        $body = json_decode($this->requests[0]['args']['body'], true);
        $this->assertSame(['post:1', 'term:5'], $body['surrogate_keys']);
        // Fastly purges by tag — the stored URLs are not sent.
        $this->assertStringNotContainsString('example.com', wp_json_encode($body));
    }

    public function test_clear_chunks_and_dispatches_in_parallel_above_256_keys(): void
    {
        // >256 keys go through the parallel path; capture the prepared requests.
        $invalidator = new class extends FastlyCacheInvalidator
        {
            /** @var array<int, array<string, mixed>> */
            public array $dispatched = [];

            protected function dispatchParallel(array $requests): bool
            {
                $this->dispatched = $requests;

                return true;
            }
        };

        $tags = array_map(fn ($i) => "post:{$i}", range(1, 600));

        $this->assertTrue($invalidator->clear([], $tags));

        $sizes = array_map(
            fn ($request) => count(json_decode($request['data'], true)['surrogate_keys']),
            $invalidator->dispatched
        );
        // Fastly rejects >256 keys per request, so 600 → 256 + 256 + 88, each its
        // own concurrent request.
        $this->assertSame([256, 256, 88], $sizes);
    }

    public function test_single_purge_retries_after_a_429_then_succeeds(): void
    {
        remove_filter('pre_http_request', [$this, 'capture'], 10);
        $calls = 0;
        add_filter('pre_http_request', function () use (&$calls) {
            $calls++;

            return $calls === 1
                ? ['response' => ['code' => 429], 'headers' => ['fastly-ratelimit-reset' => (string) (time() + 1)], 'body' => '{}']
                : ['response' => ['code' => 200], 'body' => '{}'];
        });

        $invalidator = new class extends FastlyCacheInvalidator
        {
            public int $pauses = 0;

            protected function pauseBeforeRetry(int $seconds): void
            {
                $this->pauses++; // don't actually sleep in tests
            }
        };

        $this->assertTrue($invalidator->clear([], ['post:1']));
        $this->assertSame(2, $calls, 'retried once after the 429');
        $this->assertSame(1, $invalidator->pauses);
    }

    public function test_single_purge_gives_up_after_max_retries(): void
    {
        remove_filter('pre_http_request', [$this, 'capture'], 10);
        $calls = 0;
        add_filter('pre_http_request', function () use (&$calls) {
            $calls++;

            return ['response' => ['code' => 429], 'body' => '{"msg":"rate limited"}'];
        });

        $invalidator = new class extends FastlyCacheInvalidator
        {
            protected function pauseBeforeRetry(int $seconds): void {}
        };

        $this->assertFalse($invalidator->clear([], ['post:1']));
        $this->assertSame(FastlyCacheInvalidator::MAX_PURGE_RETRIES + 1, $calls, 'initial attempt + retries, then give up');
    }

    public function test_parallel_path_retries_rate_limited_chunks(): void
    {
        $invalidator = new class extends FastlyCacheInvalidator
        {
            public int $attempts = 0;

            public int $pauses = 0;

            protected function requestMultiple(array $requests): array
            {
                $this->attempts++;
                // Rate-limit the whole window once, then succeed.
                $code = $this->attempts === 1 ? 429 : 200;

                return array_map(function () use ($code) {
                    $response = new Response;
                    $response->status_code = $code;

                    return $response;
                }, $requests);
            }

            protected function pauseBeforeRetry(int $seconds): void
            {
                $this->pauses++;
            }
        };

        // >256 keys → the parallel path.
        $tags = array_map(fn ($i) => "post:{$i}", range(1, 300));

        $this->assertTrue($invalidator->clear([], $tags));
        $this->assertSame(2, $invalidator->attempts, 'dispatched again after the 429');
        $this->assertSame(1, $invalidator->pauses);
    }

    public function test_backoff_honours_the_reset_header_capped_and_floored(): void
    {
        $invalidator = new class extends FastlyCacheInvalidator
        {
            public function backoff(string $reset): int
            {
                return $this->backoffSeconds($reset);
            }
        };

        $this->assertGreaterThanOrEqual(4, $invalidator->backoff((string) (time() + 5)));
        $this->assertLessThanOrEqual(5, $invalidator->backoff((string) (time() + 5)));
        $this->assertSame(FastlyCacheInvalidator::MAX_BACKOFF_SECONDS, $invalidator->backoff((string) (time() + 9999)), 'capped');
        $this->assertSame(1, $invalidator->backoff(''), 'floored when no reset header');
        $this->assertSame(1, $invalidator->backoff((string) (time() - 100)), 'floored when reset is past');
    }

    public function test_clear_returns_false_on_a_non_200_response(): void
    {
        remove_filter('pre_http_request', [$this, 'capture'], 10);
        add_filter('pre_http_request', fn () => ['response' => ['code' => 403], 'body' => '{"msg":"nope"}']);

        $this->assertFalse((new FastlyCacheInvalidator)->clear(['/a/'], ['post:1']));
    }

    public function test_soft_invalidator_sends_the_soft_purge_header(): void
    {
        (new FastlySoftCacheInvalidator)->clear(['/a/'], ['post:1']);

        $this->assertCount(1, $this->requests);
        $this->assertSame('1', $this->requests[0]['args']['headers']['fastly-soft-purge'] ?? null);
    }
}
