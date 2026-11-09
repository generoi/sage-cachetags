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
        foreach (['cacheTags', 'boundedTags'] as $property) {
            $ref = new ReflectionProperty($cacheTags, $property);
            $ref->setAccessible(true);
            $ref->setValue($cacheTags, $property === 'boundedTags' ? null : []);
        }

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

            public array $removed = [];

            protected function emit(string $header, string $value): void
            {
                $this->emitted[] = "{$header}: {$value}";
            }

            protected function remove(string $header): void
            {
                $this->removed[] = $header;
            }
        };
    }

    public function test_placeholder_sends_the_tags_known_before_rendering(): void
    {
        $this->resetTags()->add(['site:1']);
        $action = $this->capturingAction();

        $action->addPlaceholderHttpHeader();

        $this->assertSame(['Cache-Tag: site:1'], $action->emitted);
    }

    public function test_placeholder_skips_a_non_cacheable_request(): void
    {
        $this->resetTags()->add(['site:1']);
        add_filter('cachetags/cacheable', '__return_false');
        $action = $this->capturingAction();

        $action->addPlaceholderHttpHeader();

        $this->assertSame([], $action->emitted);
    }

    public function test_footer_replaces_the_placeholder_with_the_full_tags(): void
    {
        $cacheTags = $this->resetTags();
        $cacheTags->add(['site:1']);
        $action = $this->capturingAction();

        $action->addPlaceholderHttpHeader();
        $cacheTags->add(['post:1']);
        $action->addHttpHeader();

        $this->assertSame(['Cache-Tag: site:1', 'Cache-Tag: site:1 post:1'], $action->emitted, 'header() replaces same-name headers');
        $this->assertSame([], $action->removed);
    }

    public function test_footer_removes_the_placeholder_when_the_render_made_it_uncacheable(): void
    {
        $this->resetTags()->add(['site:1']);
        $action = $this->capturingAction();

        $action->addPlaceholderHttpHeader();
        add_filter('cachetags/cacheable', '__return_false');
        $action->addHttpHeader();

        $this->assertSame(['Cache-Tag'], $action->removed);
    }

    public function test_footer_removes_the_header_when_there_are_no_tags(): void
    {
        $this->resetTags();
        $action = $this->capturingAction();

        $action->addHttpHeader();

        $this->assertSame(['Cache-Tag'], $action->removed);
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

    /**
     * Run $test against a CacheTags instance configured with $header, restoring
     * the shared singleton afterwards.
     */
    private function withHeader(string|array|null $header, callable $test): void
    {
        $prop = new ReflectionProperty(CacheTags::class, 'instance');
        $prop->setAccessible(true);
        $saved = $prop->getValue();
        $prop->setValue(null, null);

        try {
            $test(CacheTags::make($saved->store, false, $header));
        } finally {
            $prop->setValue(null, $saved);
        }
    }

    public function test_front_end_emits_every_configured_header_with_its_separator(): void
    {
        $this->withHeader(['Surrogate-Key' => ' ', 'Cache-Tag' => ','], function (CacheTags $cacheTags) {
            $cacheTags->add(['post:1', 'term:5']);
            $action = new class($cacheTags) extends HttpHeader
            {
                public array $emitted = [];

                protected function emit(string $header, string $value): void
                {
                    $this->emitted[] = "{$header}: {$value}";
                }
            };

            $action->addHttpHeader();

            $this->assertSame(['Surrogate-Key: post:1 term:5', 'Cache-Tag: post:1,term:5'], $action->emitted);
        });
    }

    public function test_rest_response_carries_every_configured_header_with_its_separator(): void
    {
        $this->withHeader(['Surrogate-Key' => ' ', 'Cache-Tag' => ','], function (CacheTags $cacheTags) {
            $cacheTags->add(['post:1', 'term:5']);

            $headers = (new HttpHeader($cacheTags))->restPostDispatch(
                new WP_REST_Response(['ok' => true]),
                null,
                new WP_REST_Request('GET', '/wp/v2/posts'),
            )->get_headers();

            $this->assertSame('post:1 term:5', $headers['Surrogate-Key'] ?? null);
            $this->assertSame('post:1,term:5', $headers['Cache-Tag'] ?? null);
        });
    }

    public function test_a_single_string_header_still_works(): void
    {
        $this->withHeader('Surrogate-Key', function (CacheTags $cacheTags) {
            $cacheTags->add(['post:1', 'term:5']);

            $headers = (new HttpHeader($cacheTags))->restPostDispatch(
                new WP_REST_Response(['ok' => true]),
                null,
                new WP_REST_Request('GET', '/wp/v2/posts'),
            )->get_headers();

            $this->assertSame(['Surrogate-Key' => 'post:1 term:5'], $headers);
        });
    }

    public function test_headers_without_a_separator_are_space_separated(): void
    {
        $this->withHeader(['Surrogate-Key', 'Cache-Tag'], function (CacheTags $cacheTags) {
            $cacheTags->add(['post:1', 'term:5']);

            $headers = (new HttpHeader($cacheTags))->restPostDispatch(
                new WP_REST_Response(['ok' => true]),
                null,
                new WP_REST_Request('GET', '/wp/v2/posts'),
            )->get_headers();

            $this->assertSame(['Surrogate-Key' => 'post:1 term:5', 'Cache-Tag' => 'post:1 term:5'], $headers);
        });
    }
}
