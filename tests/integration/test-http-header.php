<?php

use Genero\Sage\CacheTags\Actions\HttpHeader;
use Genero\Sage\CacheTags\CacheTags;

/**
 * The Cache-Tag header is emitted on cacheable REST responses (with tags) and
 * skipped otherwise.
 *
 * @covers \Genero\Sage\CacheTags\Actions\HttpHeader
 */
class TestHttpHeader extends WP_UnitTestCase
{
    private function resetTags(): CacheTags
    {
        $cacheTags = CacheTags::getInstance();
        $ref = new ReflectionProperty($cacheTags, 'cacheTags');
        $ref->setAccessible(true);
        $ref->setValue($cacheTags, []);

        return $cacheTags;
    }

    private function action(): HttpHeader
    {
        return new HttpHeader(CacheTags::getInstance());
    }

    private function dispatch(string $method, array $tags): WP_REST_Response
    {
        $this->resetTags()->add($tags);

        return $this->action()->restPostDispatch(
            new WP_REST_Response(['ok' => true]),
            null,
            new WP_REST_Request($method, '/wp/v2/posts'),
        );
    }

    public function test_sets_the_header_on_a_cacheable_response_with_tags(): void
    {
        $headers = $this->dispatch('GET', ['post:1'])->get_headers();

        $this->assertSame('post:1', $headers['Cache-Tag'] ?? null);
    }

    public function test_skips_a_non_cacheable_response(): void
    {
        // POST is not a cacheable method.
        $this->assertArrayNotHasKey('Cache-Tag', $this->dispatch('POST', ['post:1'])->get_headers());
    }

    public function test_skips_when_there_are_no_tags(): void
    {
        $this->assertArrayNotHasKey('Cache-Tag', $this->dispatch('GET', [])->get_headers());
    }

    public function test_passes_through_a_non_rest_response(): void
    {
        $this->assertSame('passthrough', $this->action()->restPostDispatch('passthrough'));
    }

    private function capturingAction(): HttpHeader
    {
        return new class(CacheTags::getInstance()) extends HttpHeader
        {
            public array $emitted = [];

            protected function emit(string $header, string $value): void
            {
                $this->emitted[] = "{$header}: {$value}";
            }
        };
    }

    public function test_front_end_emits_the_header_on_a_cacheable_request(): void
    {
        $this->resetTags()->add(['post:1']);
        $action = $this->capturingAction();

        $action->addHttpHeader();

        $this->assertSame(['Cache-Tag: post:1'], $action->emitted);
    }

    public function test_front_end_skips_a_non_cacheable_request(): void
    {
        $this->resetTags()->add(['post:1']);
        add_filter('cachetags/cacheable', '__return_false');
        $action = $this->capturingAction();

        $action->addHttpHeader();

        $this->assertSame([], $action->emitted);
    }

    public function test_front_end_skips_when_there_are_no_tags(): void
    {
        $this->resetTags();
        $action = $this->capturingAction();

        $action->addHttpHeader();

        $this->assertSame([], $action->emitted);
    }
}
