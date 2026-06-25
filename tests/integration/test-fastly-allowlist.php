<?php

use Genero\Sage\CacheTags\Fastly\AllowlistDictionary;
use Genero\Sage\CacheTags\Fastly\QueryAllowlist;

/**
 * @covers \Genero\Sage\CacheTags\Fastly\QueryAllowlist
 * @covers \Genero\Sage\CacheTags\Fastly\AllowlistDictionary
 */
class TestFastlyAllowlist extends WP_UnitTestCase
{
    public function test_collect_includes_core_params_sorted_and_unique(): void
    {
        $params = QueryAllowlist::collect();

        foreach (['orderby', 'paged', 's'] as $param) {
            $this->assertContains($param, $params);
        }

        $sorted = $params;
        sort($sorted);
        $this->assertSame($sorted, $params, 'sorted');
        $this->assertSame(array_values(array_unique($params)), $params, 'de-duplicated');
    }

    public function test_filter_can_add_and_remove_params(): void
    {
        add_filter(QueryAllowlist::FILTER, fn ($params) => array_merge(array_diff($params, ['order']), ['my_facet', 'my_facet']));

        $params = QueryAllowlist::collect();

        $this->assertContains('my_facet', $params);
        $this->assertNotContains('order', $params);
        $this->assertSame(1, count(array_keys($params, 'my_facet', true)), 'still de-duplicated');
    }

    public function test_push_upserts_the_comma_joined_allowlist(): void
    {
        $dictionary = new class('mydict') extends AllowlistDictionary
        {
            /** @var array<string, mixed> */
            public array $patched = [];

            public function isConfigured(): bool
            {
                return true;
            }

            protected function dictionaryId(): ?string
            {
                return 'abc123';
            }

            protected function apiPatch(string $path, array $body): bool
            {
                $this->patched = ['path' => $path, 'body' => $body];

                return true;
            }
        };

        $this->assertTrue($dictionary->push(['orderby', 'paged']));
        $this->assertStringContainsString('/dictionary/abc123/items', $dictionary->patched['path']);

        $item = $dictionary->patched['body']['items'][0];
        $this->assertSame('upsert', $item['op']);
        $this->assertSame('params', $item['item_key']);
        $this->assertSame('orderby,paged', $item['item_value']);
    }

    public function test_is_synced_compares_current_value_to_the_joined_list(): void
    {
        $dictionary = new class('mydict') extends AllowlistDictionary
        {
            public function isConfigured(): bool
            {
                return true;
            }

            protected function dictionaryId(): ?string
            {
                return 'abc';
            }

            protected function apiGet(string $path): ?array
            {
                return ['item_value' => 'orderby,paged'];
            }
        };

        $this->assertTrue($dictionary->isSynced(['orderby', 'paged']));
        $this->assertFalse($dictionary->isSynced(['orderby']));
    }

    public function test_push_fails_when_the_dictionary_cannot_be_resolved(): void
    {
        // Not configured (no FASTLY_* env) → dictionaryId() is null → no API call.
        $this->assertFalse((new AllowlistDictionary('missing'))->push(['orderby']));
    }
}
