<?php

use Genero\Sage\CacheTags\Stores\DeferredClearStore;
use Genero\Sage\CacheTags\Stores\WordpressDbStore;

/**
 * @covers \Genero\Sage\CacheTags\Stores\DeferredClearStore
 */
class TestDeferredClearStore extends WP_UnitTestCase
{
    public function test_clear_defers_to_a_scheduled_cron_instead_of_clearing_now(): void
    {
        $inner = new WordpressDbStore;
        $inner->save(['post:1'], '/a/');
        $store = new DeferredClearStore($inner, 60);

        $store->clear(['/a/'], ['post:1']);

        // Not cleared yet — deferred.
        $this->assertSame(['/a/'], $inner->get(['post:1']));
        $this->assertNotFalse(wp_next_scheduled(DeferredClearStore::CRON_HOOK));

        $pending = get_transient(DeferredClearStore::TRANSIENT_KEY);
        $this->assertSame(['/a/'], $pending['urls']);
        $this->assertSame(['post:1'], $pending['tags']);
    }

    public function test_processing_pending_clear_delegates_to_the_inner_store(): void
    {
        $inner = new WordpressDbStore;
        $inner->save(['post:1'], '/a/');
        $store = new DeferredClearStore($inner, 60);

        $store->clear(['/a/'], ['post:1']);
        $store->processPendingClear();

        $this->assertSame([], $inner->get(['post:1']));
        $this->assertFalse(get_transient(DeferredClearStore::TRANSIENT_KEY));
    }

    public function test_save_and_get_pass_through(): void
    {
        $store = new DeferredClearStore(new WordpressDbStore, 60);
        $store->save(['post:2'], '/b/');

        $this->assertSame(['/b/'], $store->get(['post:2']));
    }
}
