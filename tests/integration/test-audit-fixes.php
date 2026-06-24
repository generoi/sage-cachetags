<?php

use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Stores\WordpressDbStore;

/**
 * Regression tests for the audit findings: store-layer correctness and the
 * header byte-budget trim.
 *
 * @covers \Genero\Sage\CacheTags\Stores\WordpressDbStore
 * @covers \Genero\Sage\CacheTags\CacheTags
 */
class TestAuditFixes extends WP_UnitTestCase
{
    private function store(): WordpressDbStore
    {
        return new WordpressDbStore;
    }

    // C2: clearing one tag must not drop a URL's sibling-tag mappings.
    public function test_clear_removes_only_the_purged_tag_not_siblings(): void
    {
        $store = $this->store();
        $store->save(['post:1', 'post:2'], 'https://example.com/a/');

        $store->clear(['https://example.com/a/'], ['post:1']);

        $this->assertSame([], $store->get(['post:1']), 'purged mapping removed');
        $this->assertSame(['https://example.com/a/'], $store->get(['post:2']), 'sibling mapping survives');
    }

    // Store finding: a 0-row delete / TRUNCATE must report success, not failure.
    public function test_clear_and_flush_report_success_on_no_op(): void
    {
        $store = $this->store();

        $this->assertTrue($store->clear(['https://example.com/gone/'], ['post:404']), 'no-op clear is success');

        $store->save(['post:1'], 'https://example.com/a/');
        $this->assertTrue($store->flush(), 'truncate is success');
        $this->assertSame([], $store->get(['post:1']), 'flush emptied the store');
    }

    // Store finding: get() must not return the same URL once per matching tag.
    public function test_get_returns_each_url_once(): void
    {
        $store = $this->store();
        $store->save(['post:1', 'post:2'], 'https://example.com/a/');

        $this->assertSame(['https://example.com/a/'], $store->get(['post:1', 'post:2']));
    }

    // C4: non-collapsible tags (comment:, user:, …) must still be trimmed to the
    // header budget so the provider never silently drops keys.
    public function test_header_budget_trims_non_collapsible_overflow(): void
    {
        $cacheTags = CacheTags::getInstance();
        $ref = new ReflectionProperty($cacheTags, 'cacheTags');
        $ref->setAccessible(true);
        $ref->setValue($cacheTags, array_map(fn ($i) => "comment:{$i}", range(1, 1000)));

        add_filter('cachetags/max-header-bytes', fn () => 200);

        $tags = $cacheTags->get();

        $this->assertLessThanOrEqual(200, strlen(implode(' ', $tags)), 'header fits the budget');
        $this->assertNotEmpty($tags);
    }
}
