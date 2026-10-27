<?php

use Genero\Sage\CacheTags\Invalidators\CloudflareCacheInvalidator;
use WpOrg\Requests\Response;

/**
 * @covers \Genero\Sage\CacheTags\Invalidators\CloudflareCacheInvalidator
 */
class TestCloudflareInvalidator extends WP_UnitTestCase
{
    const OK = [200, '{"success":true,"errors":[],"messages":[],"result":{"id":"x"}}'];

    const RATE_LIMITED = [429, '{"success":false,"errors":[{"code":1134,"message":"rate limit reached"}]}', ['retry-after' => '3']];

    public function set_up(): void
    {
        parent::set_up();
        putenv('CLOUDFLARE_API_TOKEN=secret-token');
        putenv('CLOUDFLARE_ZONE_IDS=zone-a, zone-b');
        $this->clearDeferred();
    }

    public function tear_down(): void
    {
        putenv('CLOUDFLARE_API_TOKEN');
        putenv('CLOUDFLARE_ZONE_IDS');
        $this->clearDeferred();
        parent::tear_down();
    }

    private function clearDeferred(): void
    {
        CloudflareCacheInvalidator::deferred()->clear();
    }

    /** Seed one deferral row, as defer() would write it. */
    private function seedDeferred(array $queue): void
    {
        CloudflareCacheInvalidator::deferred()->push($queue);
    }

    /**
     * An invalidator whose HTTP layer answers each request with $respond(request,
     * call number) → [status, body, headers?], recording what was sent.
     */
    private function invalidator(callable $respond): CloudflareCacheInvalidator
    {
        return new class($respond) extends CloudflareCacheInvalidator
        {
            public array $sent = [];

            public int $pauses = 0;

            /** Behave like WP-CLI/cron by default; false for a web request. */
            public bool $canWait = true;

            /** @var string[] zones whose purge failure was logged */
            public array $failures = [];

            /** @var callable */
            private $respond;

            public function __construct(callable $respond)
            {
                parent::__construct();
                $this->respond = $respond;
            }

            protected function requestMultiple(array $requests): array
            {
                return array_map(function (array $request) {
                    $this->sent[] = $request;
                    [$status, $body, $headers] = ($this->respond)($request, count($this->sent)) + [2 => []];

                    $response = new Response;
                    $response->status_code = $status;
                    $response->body = $body;
                    foreach ($headers as $name => $value) {
                        $response->headers[$name] = $value;
                    }

                    return $response;
                }, $requests);
            }

            protected function pauseBeforeRetry(int $seconds): void
            {
                $this->pauses++; // don't actually sleep in tests
            }

            protected function canWait(): bool
            {
                return $this->canWait;
            }

            protected function logPurgeFailure(string $zone, mixed $response): void
            {
                $this->failures[] = $zone;
            }

            /** @return array<string, array<string, mixed>> zone => decoded payloads */
            public function payloadsByZone(): array
            {
                $byZone = [];
                foreach ($this->sent as $request) {
                    preg_match('#/zones/([^/]+)/purge_cache$#', $request['url'], $match);
                    $byZone[$match[1]][] = json_decode($request['data'], true);
                }

                return $byZone;
            }
        };
    }

    public function test_clear_purges_the_tags_in_every_zone_with_a_bearer_token(): void
    {
        $invalidator = $this->invalidator(fn () => self::OK);

        $this->assertTrue($invalidator->clear(['https://example.com/a/'], ['post:1', 'term:5']));

        $this->assertSame([
            'zone-a' => [['tags' => ['post:1', 'term:5']]],
            'zone-b' => [['tags' => ['post:1', 'term:5']]],
        ], $invalidator->payloadsByZone());
        $this->assertSame('https://api.cloudflare.com/client/v4/zones/zone-a/purge_cache', $invalidator->sent[0]['url']);
        $this->assertSame('Bearer secret-token', $invalidator->sent[0]['headers']['Authorization']);
        $this->assertStringNotContainsString('example.com', $invalidator->sent[0]['data'], 'purges by tag, not URL');
    }

    public function test_clear_chunks_tags_at_100_per_request_per_zone(): void
    {
        $invalidator = $this->invalidator(fn () => self::OK);
        $tags = array_map(fn ($i) => "post:{$i}", range(1, 250));

        $this->assertTrue($invalidator->clear([], $tags));

        foreach ($invalidator->payloadsByZone() as $payloads) {
            $this->assertSame([100, 100, 50], array_map(fn ($p) => count($p['tags']), $payloads));
        }
        $this->assertCount(6, $invalidator->sent, '3 chunks × 2 zones');
    }

    public function test_flush_purges_everything_in_every_zone(): void
    {
        $invalidator = $this->invalidator(fn () => self::OK);

        $this->assertTrue($invalidator->flush());

        $this->assertSame([
            'zone-a' => [['purge_everything' => true]],
            'zone-b' => [['purge_everything' => true]],
        ], $invalidator->payloadsByZone());
    }

    public function test_a_200_without_success_true_is_a_failure(): void
    {
        $invalidator = $this->invalidator(fn () => [200, '{"success":false,"errors":[{"code":1000}]}']);

        $this->assertFalse($invalidator->clear([], ['post:1']));
    }

    public function test_a_failing_zone_does_not_stop_the_others(): void
    {
        $invalidator = $this->invalidator(
            fn ($request) => str_contains($request['url'], 'zone-a') ? [403, '{"success":false}'] : self::OK
        );

        $this->assertFalse($invalidator->clear([], ['post:1']));
        $this->assertCount(2, $invalidator->sent, 'zone-b was still purged');
    }

    public function test_without_configuration_nothing_is_sent_and_it_fails(): void
    {
        putenv('CLOUDFLARE_ZONE_IDS');
        $invalidator = $this->invalidator(fn () => self::OK);

        $this->assertFalse($invalidator->clear([], ['post:1']));
        $this->assertSame([], $invalidator->sent);
    }

    public function test_a_429_is_retried_then_succeeds(): void
    {
        // The first round (both zones) is rate-limited, the retry goes through.
        $invalidator = $this->invalidator(fn ($request, $n) => $n <= 2 ? self::RATE_LIMITED : self::OK);

        $this->assertTrue($invalidator->clear([], ['post:1']));
        $this->assertCount(4, $invalidator->sent);
        $this->assertSame(1, $invalidator->pauses);
        $this->assertSame([], CloudflareCacheInvalidator::deferred()->all(), 'nothing deferred');
    }

    public function test_a_web_request_defers_a_429_without_waiting(): void
    {
        $invalidator = $this->invalidator(fn ($request, $n) => $n <= 2 ? self::RATE_LIMITED : self::OK);
        $invalidator->canWait = false;

        $this->assertTrue($invalidator->clear([], ['post:1']));

        $this->assertSame(0, $invalidator->pauses, 'never sleeps');
        $this->assertCount(2, $invalidator->sent, 'no in-place retry');
        $this->assertSame(['zone-a' => ['post:1'], 'zone-b' => ['post:1']], CloudflareCacheInvalidator::deferred()->all());
        $this->assertNotFalse(wp_next_scheduled(CloudflareCacheInvalidator::deferred()->hook));
    }

    public function test_outside_cli_and_cron_the_invalidator_cannot_wait(): void
    {
        $canWait = (new ReflectionMethod(CloudflareCacheInvalidator::class, 'canWait'));

        $this->assertFalse($canWait->invoke(new CloudflareCacheInvalidator));

        add_filter('wp_doing_cron', '__return_true');
        $this->assertTrue($canWait->invoke(new CloudflareCacheInvalidator));
        remove_filter('wp_doing_cron', '__return_true');
    }

    public function test_an_exhausted_rate_limit_defers_the_tags_to_one_cron_retry(): void
    {
        $invalidator = $this->invalidator(
            fn ($request) => str_contains($request['url'], 'zone-a') ? self::RATE_LIMITED : self::OK
        );

        // Deferred, not lost — so the purge counts as handled.
        $this->assertTrue($invalidator->clear([], ['post:1', 'term:5']));
        $this->assertSame(CloudflareCacheInvalidator::MAX_PURGE_RETRIES, $invalidator->pauses);

        // A second rate-limited purge coalesces into the same queue and event.
        $this->assertTrue($invalidator->clear([], ['post:1', 'post:2']));

        $this->assertSame(
            ['zone-a' => ['post:1', 'term:5', 'post:2']],
            CloudflareCacheInvalidator::deferred()->all(),
            'only the rate-limited zone is queued, deduped'
        );
        $this->assertNotFalse(wp_next_scheduled(CloudflareCacheInvalidator::deferred()->hook));
        $this->assertCount(1, array_filter(
            _get_cron_array(),
            fn ($events) => isset($events[CloudflareCacheInvalidator::deferred()->hook])
        ), 'a single retry event');
    }

    public function test_once_the_budget_is_spent_later_windows_are_deferred_unsent(): void
    {
        $invalidator = $this->invalidator(fn () => self::RATE_LIMITED);
        // 2 zones × 6 chunks = 12 requests → windows of 10 + 2.
        $tags = array_map(fn ($i) => "post:{$i}", range(1, 600));

        $this->assertTrue($invalidator->clear([], $tags));

        $this->assertCount(10 * (CloudflareCacheInvalidator::MAX_PURGE_RETRIES + 1), $invalidator->sent, 'second window never sent');
        $queue = CloudflareCacheInvalidator::deferred()->all();
        $this->assertCount(600, $queue['zone-a']);
        $this->assertCount(600, $queue['zone-b']);
    }

    public function test_a_deferred_purge_everything_supersedes_queued_tags(): void
    {
        $invalidator = $this->invalidator(fn () => self::RATE_LIMITED);

        $invalidator->clear([], ['post:1']);
        $invalidator->flush();

        $this->assertSame(['zone-a' => true, 'zone-b' => true], CloudflareCacheInvalidator::deferred()->all());
    }

    public function test_each_deferral_is_its_own_row_so_concurrent_requests_cannot_overwrite_each_other(): void
    {
        $invalidator = $this->invalidator(fn () => self::RATE_LIMITED);
        $invalidator->canWait = false;

        $invalidator->clear([], ['post:1']);
        $invalidator->clear([], ['post:2']);

        global $wpdb;
        $rows = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like(CloudflareCacheInvalidator::deferred()->optionPrefix).'%'
        ));
        $this->assertSame(2, $rows, 'appended, never read-modify-written');
        $this->assertSame(['zone-a' => ['post:1', 'post:2'], 'zone-b' => ['post:1', 'post:2']], CloudflareCacheInvalidator::deferred()->all());
    }

    public function test_retry_deferred_keeps_a_deferral_written_while_it_runs(): void
    {
        $this->seedDeferred(['zone-a' => ['post:1']]);
        // Another request defers while the retry's purge is in flight.
        $invalidator = $this->invalidator(function () {
            $this->seedDeferred(['zone-b' => ['post:9']]);

            return self::OK;
        });

        $this->assertTrue($invalidator->retryDeferred());

        $this->assertSame(['zone-b' => ['post:9']], CloudflareCacheInvalidator::deferred()->all());
    }

    public function test_a_non_429_failure_is_logged(): void
    {
        $invalidator = $this->invalidator(
            fn ($request) => str_contains($request['url'], 'zone-a') ? [403, '{"success":false,"errors":[{"code":10000,"message":"Authentication error"}]}'] : self::OK
        );

        $this->assertFalse($invalidator->clear([], ['post:1']));

        $this->assertSame(['zone-a'], $invalidator->failures);
    }

    public function test_retry_deferred_sends_the_queue_and_empties_it(): void
    {
        $this->seedDeferred(['zone-a' => ['post:1', 'term:5'], 'zone-b' => true]);
        $invalidator = $this->invalidator(fn () => self::OK);

        $this->assertTrue($invalidator->retryDeferred());

        $this->assertSame([
            'zone-a' => [['tags' => ['post:1', 'term:5']]],
            'zone-b' => [['purge_everything' => true]],
        ], $invalidator->payloadsByZone());
        $this->assertSame([], CloudflareCacheInvalidator::deferred()->all());
    }

    public function test_retry_deferred_requeues_what_is_still_rate_limited(): void
    {
        $this->seedDeferred(['zone-a' => ['post:1']]);
        $invalidator = $this->invalidator(fn () => self::RATE_LIMITED);

        $invalidator->retryDeferred();

        $this->assertSame(['zone-a' => ['post:1']], CloudflareCacheInvalidator::deferred()->all());
        $this->assertNotFalse(wp_next_scheduled(CloudflareCacheInvalidator::deferred()->hook));
    }

    public function test_the_cron_hook_is_registered(): void
    {
        new CloudflareCacheInvalidator;

        $this->assertNotFalse(has_action(CloudflareCacheInvalidator::deferred()->hook, [CloudflareCacheInvalidator::class, 'runDeferred']));
    }

    public function test_backoff_uses_retry_after_then_exponential_capped(): void
    {
        $invalidator = new class extends CloudflareCacheInvalidator
        {
            public function backoff(string $retryAfter, int $attempt): int
            {
                return $this->backoffSeconds($retryAfter, $attempt);
            }
        };

        $this->assertSame(3, $invalidator->backoff('3', 0));
        $this->assertSame(CloudflareCacheInvalidator::MAX_BACKOFF_SECONDS, $invalidator->backoff('300', 0), 'capped');
        $this->assertSame(1, $invalidator->backoff('', 0));
        $this->assertSame(2, $invalidator->backoff('', 1));
    }
}
