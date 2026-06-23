<?php

use Genero\Sage\CacheTags\Invalidators\FastlyCacheInvalidator;

/**
 * @covers \Genero\Sage\CacheTags\Invalidators\FastlyCacheInvalidator
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

    public function test_clear_returns_false_on_a_non_200_response(): void
    {
        remove_filter('pre_http_request', [$this, 'capture'], 10);
        add_filter('pre_http_request', fn () => ['response' => ['code' => 403], 'body' => '{"msg":"nope"}']);

        $this->assertFalse((new FastlyCacheInvalidator)->clear(['/a/'], ['post:1']));
    }
}
