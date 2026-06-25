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

    public function test_rejects_names_that_arent_cache_key_safe(): void
    {
        // A comma would split the dictionary value; whitespace/control breaks the
        // VCL filter. Such names are dropped, valid ones kept.
        add_filter(QueryAllowlist::FILTER, fn ($p) => array_merge($p, ['ok_name', 'has,comma', 'has space', "ctrl\n"]));

        $params = QueryAllowlist::collect();

        $this->assertContains('ok_name', $params);
        $this->assertNotContains('has,comma', $params);
        $this->assertNotContains('has space', $params);
        foreach ($params as $param) {
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9_\-]+$/', $param);
        }
    }

    public function test_woocommerce_params_use_unprefixed_attribute_slug(): void
    {
        $params = QueryAllowlist::wooCommerceParams([
            (object) ['attribute_name' => 'color'],
            (object) ['attribute_name' => 'size'],
        ]);

        $this->assertContains('filter_color', $params);
        $this->assertContains('query_type_color', $params);
        $this->assertContains('filter_size', $params);
        $this->assertContains('post_type', $params, 'product search vs blog search');
        $this->assertContains('filter_stock_status', $params);
    }

    public function test_facetwp_params_are_underscore_prefixed(): void
    {
        $params = QueryAllowlist::facetParams([
            ['name' => 'flavors'],
            ['name' => 'brand'],
            ['label' => 'no name — skipped'],
        ]);

        // FacetWP reads ?_flavors=…, not ?flavors=… — the prefix is the whole point.
        $this->assertContains('_flavors', $params);
        $this->assertContains('_brand', $params);
        $this->assertNotContains('flavors', $params);
        $this->assertContains('_paged', $params);
        $this->assertContains('_sort', $params);
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
        $this->assertSame(parse_url(home_url(), PHP_URL_HOST), $item['item_key'], 'keyed per host');
        $this->assertSame('orderby,paged', $item['item_value']);
    }

    public function test_exceeds_limit_guards_the_8000_char_value_cap(): void
    {
        $dictionary = new AllowlistDictionary('d');

        $this->assertFalse($dictionary->exceedsLimit(['orderby', 'paged']));
        // ~9000 chars of comma-joined names.
        $this->assertTrue($dictionary->exceedsLimit(array_map(fn ($i) => "param_{$i}", range(1, 1000))));
    }

    public function test_collect_includes_custom_registered_query_vars(): void
    {
        // A theme/plugin registering a public query var should be allowlisted, not
        // silently stripped at the edge.
        add_filter('query_vars', fn ($vars) => array_merge($vars, ['my_theme_filter']));

        $this->assertContains('my_theme_filter', QueryAllowlist::collect());
        $this->assertContains('cpage', QueryAllowlist::collect(), 'comment paging');
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

    public function test_resolves_dictionary_id_via_the_active_version(): void
    {
        $dictionary = new class('shop_allowlist') extends AllowlistDictionary
        {
            /** @var string[] */
            public array $gets = [];

            public string $patchedPath = '';

            public function isConfigured(): bool
            {
                return true;
            }

            protected function serviceId(): string
            {
                return 'svc1';
            }

            protected function apiGet(string $path): ?array
            {
                $this->gets[] = $path;

                if (str_contains($path, '/version/active')) {
                    return ['number' => 7];
                }
                if (str_contains($path, '/dictionary/shop_allowlist')) {
                    return ['id' => 'dictABC'];
                }

                return null;
            }

            protected function apiPatch(string $path, array $body): bool
            {
                $this->patchedPath = $path;

                return true;
            }
        };

        $this->assertTrue($dictionary->push(['orderby']));
        $this->assertContains('/service/svc1/version/active', $dictionary->gets);
        $this->assertContains('/service/svc1/version/7/dictionary/shop_allowlist', $dictionary->gets);
        $this->assertSame('/service/svc1/dictionary/dictABC/items', $dictionary->patchedPath);
    }

    public function test_push_fails_when_the_dictionary_cannot_be_resolved(): void
    {
        // Not configured (no FASTLY_* env) → dictionaryId() is null → no API call.
        $this->assertFalse((new AllowlistDictionary('missing'))->push(['orderby']));
    }
}
