<?php

use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Contracts\ListableStore;
use Genero\Sage\CacheTags\Invalidators\WarmInvalidator;
use Genero\Sage\CacheTags\Stores\WordpressDbStore;
use Genero\Sage\CacheTags\Warmer;
use Genero\Sage\CacheTags\WpCli\WarmCommand;

/**
 * The warmer against the real HTTP API, with requests answered by
 * pre_http_request instead of the network.
 *
 * @covers \Genero\Sage\CacheTags\Warmer
 * @covers \Genero\Sage\CacheTags\Invalidators\WarmInvalidator
 * @covers \Genero\Sage\CacheTags\WpCli\WarmCommand
 * @covers \Genero\Sage\CacheTags\Stores\WordpressDbStore::urls
 */
class TestWarmer extends WP_UnitTestCase
{
    private int $now = 1_800_000_000;

    /** @var list<array{url: string, args: array}> */
    private array $requests = [];

    /** @var array<string, int> URL => status code to answer */
    private array $codes = [];

    /** @var (callable(string):void)|null */
    private $during = null;

    private ?CacheTags $saved;

    public function set_up(): void
    {
        parent::set_up();
        // The store's flush is a TRUNCATE, which commits the test transaction,
        // so a queue can outlive its test's rollback.
        delete_option(Warmer::OPTION);
        add_filter('pre_http_request', [$this, 'answer'], 10, 3);
        $prop = new ReflectionProperty(CacheTags::class, 'instance');
        $prop->setAccessible(true);
        $this->saved = $prop->getValue();
    }

    public function tear_down(): void
    {
        remove_filter('pre_http_request', [$this, 'answer'], 10);
        delete_option(Warmer::OPTION);
        Warmer::$skip = [];
        $prop = new ReflectionProperty(CacheTags::class, 'instance');
        $prop->setAccessible(true);
        $prop->setValue(null, $this->saved);
        parent::tear_down();
    }

    public function answer($pre, array $args, string $url): array
    {
        $this->requests[] = ['url' => $url, 'args' => $args];
        if ($this->during) {
            ($this->during)($url);
        }

        return ['headers' => [], 'body' => '', 'response' => ['code' => $this->codes[$url] ?? 200, 'message' => ''], 'cookies' => [], 'filename' => null];
    }

    /** Queue $urls long enough ago that they're due. */
    private function queueDue(array $urls): void
    {
        Warmer::queue($urls, $this->now - Warmer::SETTLE - 1);
    }

    private function warm(): array
    {
        return Warmer::run(60, 0, $this->now);
    }

    private function requested(): array
    {
        return array_column($this->requests, 'url');
    }

    public function test_only_plain_pages_are_queued(): void
    {
        Warmer::$skip = ['#^/search/#'];
        add_filter(Warmer::FILTER_SKIP, fn ($skip, $url) => $skip || str_contains($url, '/private/'), 10, 2);

        $this->queueDue([
            'https://example.com/recipes/pasta/',
            'https://example.com/?s=pasta',
            'https://example.com/recipes/?_brand=mutti',
            'https://example.com/wp-json/wp/v2/posts',
            'https://example.com/wp/wp-admin/edit.php',
            'https://example.com/wp/wp-login.php',
            'https://example.com/search/pasta/',
            'https://example.com/private/page/',
        ]);

        $this->assertSame(1, Warmer::count());
        $this->warm();
        $this->assertSame(['https://example.com/recipes/pasta/'], $this->requested());
    }

    public function test_a_purged_page_waits_for_its_purge_to_settle(): void
    {
        Warmer::queue(['https://example.com/fresh/'], $this->now - 5);
        $this->queueDue(['https://example.com/settled/']);

        $result = $this->warm();

        $this->assertSame(['https://example.com/settled/'], $this->requested());
        $this->assertSame(['warmed' => 1, 'failed' => 0, 'left' => 1, 'stopped' => null], $result);
    }

    public function test_it_requests_as_an_anonymous_visitor_with_the_filtered_headers(): void
    {
        add_filter(Warmer::FILTER_HEADERS, fn ($headers) => $headers + ['X-Test' => 'warm']);
        $this->queueDue(['https://example.com/a/']);

        $this->warm();

        $args = $this->requests[0]['args'];
        $this->assertSame('warm', $args['headers']['X-Test']);
        $this->assertSame([], $args['cookies']);
        $this->assertSame(0, $args['redirection']);
        $this->assertStringContainsString('sage-cachetags warmer', $args['headers']['User-Agent']);
    }

    /** @return iterable<string, array{0: int}> */
    public function busyCodes(): iterable
    {
        yield '429' => [429];
        yield '503' => [503];
    }

    /**
     * @dataProvider busyCodes
     */
    public function test_a_busy_site_stops_the_run_and_keeps_the_rest_queued(int $code): void
    {
        $this->queueDue(['https://example.com/a/', 'https://example.com/b/', 'https://example.com/c/']);
        $this->codes['https://example.com/b/'] = $code;

        $result = $this->warm();

        $this->assertSame(['https://example.com/a/', 'https://example.com/b/'], $this->requested());
        $this->assertSame(['warmed' => 1, 'failed' => 0, 'left' => 2, 'stopped' => "the site answered {$code}"], $result);
    }

    public function test_a_failing_page_is_counted_and_not_retried(): void
    {
        $this->queueDue(['https://example.com/gone/']);
        $this->codes['https://example.com/gone/'] = 500;

        $this->assertSame(['warmed' => 0, 'failed' => 1, 'left' => 0, 'stopped' => null], $this->warm());
    }

    public function test_pages_queued_during_a_run_stay_queued(): void
    {
        $this->queueDue(['https://example.com/a/']);
        $this->during = function () {
            $this->during = null;
            Warmer::queue(['https://example.com/new/'], $this->now);
        };

        $this->assertSame(1, $this->warm()['left']);
        $this->assertSame(1, Warmer::count());
    }

    public function test_the_queue_keeps_the_newest_urls_up_to_its_cap(): void
    {
        $urls = array_map(fn ($i) => "https://example.com/p{$i}/", range(1, Warmer::MAX_QUEUE + 5));
        $this->queueDue($urls);

        $this->assertSame(Warmer::MAX_QUEUE, Warmer::count());
    }

    public function test_the_invalidator_queues_purged_urls_and_on_a_flush_every_stored_one(): void
    {
        $store = new WordpressDbStore;
        $this->assertInstanceOf(ListableStore::class, $store);
        $store->save(['post:1'], 'https://example.com/one/');
        $store->save(['post:2'], 'https://example.com/two/');
        $prop = new ReflectionProperty(CacheTags::class, 'instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
        $cacheTags = CacheTags::make($store, false, 'Cache-Tag', [new WarmInvalidator]);

        (new WarmInvalidator)->clear(['https://example.com/purged/'], ['post:3']);
        $this->assertSame(1, Warmer::count());

        $this->assertTrue($cacheTags->flush());
        $this->assertSame(3, Warmer::count());
        $this->assertSame([], $store->urls(10), 'the store is still flushed');
    }

    public function test_the_command_warms_the_queue(): void
    {
        $prop = new ReflectionProperty(CacheTags::class, 'instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
        $store = new WordpressDbStore;
        $store->save(['post:1'], 'https://example.com/stored/');
        CacheTags::make($store, false, 'Cache-Tag', []);
        $logger = new class
        {
            public array $messages = [];

            public function success($message)
            {
                $this->messages[] = "success:$message";
            }

            public function warning($message)
            {
                $this->messages[] = "warning:$message";
            }

            public function error($message)
            {
                throw new RuntimeException($message);
            }

            public function info($message) {}

            public function debug($message, $group = false) {}

            public function line($message = '') {}
        };
        WP_CLI::set_logger($logger);

        // --all queues it now, so it isn't due yet: nothing is requested.
        (new WarmCommand)([], ['all' => true, 'pause' => '0']);

        $this->assertSame([], $this->requested());
        $this->assertSame(['success:Warmed 0 page(s), 0 failed, 1 left.'], $logger->messages);
    }
}
