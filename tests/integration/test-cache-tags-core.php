<?php

use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Contracts\Action;
use Genero\Sage\CacheTags\Contracts\Invalidator;
use Genero\Sage\CacheTags\Contracts\Store;

class RecordingStore implements Store
{
    public array $saved = [];

    public array $cleared = [];

    public bool $flushed = false;

    public bool $clearResult = true;

    public bool $flushResult = true;

    public array $urlsFor = [];

    public function save(array $tags, string $url): bool
    {
        $this->saved[] = [$url, $tags];

        return true;
    }

    public function get(array $tags): array
    {
        return $this->urlsFor;
    }

    public function clear(array $urls, array $tags): bool
    {
        $this->cleared[] = [$urls, $tags];

        return $this->clearResult;
    }

    public function flush(): bool
    {
        $this->flushed = true;

        return $this->flushResult;
    }
}

class RecordingInvalidator implements Invalidator
{
    public array $clearedWith = [];

    public bool $flushed = false;

    public bool $clearResult = true;

    public bool $flushResult = true;

    public function clear(array $urls, array $tags): bool
    {
        $this->clearedWith[] = [$urls, $tags];

        return $this->clearResult;
    }

    public function flush(): bool
    {
        $this->flushed = true;

        return $this->flushResult;
    }
}

class RecordingAction implements Action
{
    public bool $bound = false;

    public function bind(): void
    {
        $this->bound = true;
    }
}

/**
 * The orchestration in CacheTags: save gating, and the invalidator-then-store
 * ordering for purge/flush (store is only touched when invalidators succeed).
 *
 * @covers \Genero\Sage\CacheTags\CacheTags
 */
class TestCacheTagsCore extends WP_UnitTestCase
{
    private $savedInstance;

    private bool $swapped = false;

    public function tear_down(): void
    {
        if ($this->swapped) {
            $this->instanceProp()->setValue(null, $this->savedInstance);
            $this->swapped = false;
        }
        parent::tear_down();
    }

    private function instanceProp(): ReflectionProperty
    {
        $prop = new ReflectionProperty(CacheTags::class, 'instance');
        $prop->setAccessible(true);

        return $prop;
    }

    private function make(Store $store, array $invalidators): CacheTags
    {
        $prop = $this->instanceProp();
        $this->savedInstance = $prop->getValue();
        $this->swapped = true;
        $prop->setValue(null, null);

        return CacheTags::make($store, false, 'Cache-Tag', $invalidators);
    }

    public function test_purge_queued_runs_invalidators_then_clears_the_store(): void
    {
        $store = new RecordingStore;
        $store->urlsFor = ['https://example.com/a/'];
        $invalidator = new RecordingInvalidator;
        $cacheTags = $this->make($store, [$invalidator]);

        $cacheTags->clear(['post:1']);

        $this->assertTrue($cacheTags->purgeQueued());
        $this->assertSame([[['https://example.com/a/'], ['post:1']]], $invalidator->clearedWith);
        $this->assertNotEmpty($store->cleared, 'store cleared after success');
    }

    public function test_purge_queued_does_not_clear_the_store_when_an_invalidator_fails(): void
    {
        $store = new RecordingStore;
        $store->urlsFor = ['https://example.com/a/'];
        $invalidator = new RecordingInvalidator;
        $invalidator->clearResult = false;
        $cacheTags = $this->make($store, [$invalidator]);

        $cacheTags->clear(['post:1']);

        $this->assertFalse($cacheTags->purgeQueued());
        $this->assertEmpty($store->cleared, 'store left intact when a purge fails');
    }

    public function test_purge_queued_is_a_noop_without_tags_or_urls(): void
    {
        $store = new RecordingStore;
        $invalidator = new RecordingInvalidator;
        $cacheTags = $this->make($store, [$invalidator]);

        $this->assertTrue($cacheTags->purgeQueued(), 'no queued tags');
        $this->assertEmpty($invalidator->clearedWith);

        $cacheTags->clear(['post:1']);
        $store->urlsFor = [];
        $this->assertTrue($cacheTags->purgeQueued(), 'no matching urls');
        $this->assertEmpty($invalidator->clearedWith);
    }

    public function test_flush_runs_invalidators_then_the_store(): void
    {
        $store = new RecordingStore;
        $invalidator = new RecordingInvalidator;
        $cacheTags = $this->make($store, [$invalidator]);

        $this->assertTrue($cacheTags->flush());
        $this->assertTrue($invalidator->flushed);
        $this->assertTrue($store->flushed);
    }

    public function test_flush_does_not_flush_the_store_when_an_invalidator_fails(): void
    {
        $store = new RecordingStore;
        $invalidator = new RecordingInvalidator;
        $invalidator->flushResult = false;
        $cacheTags = $this->make($store, [$invalidator]);

        $this->assertFalse($cacheTags->flush());
        $this->assertFalse($store->flushed, 'store not flushed when a flush fails');
    }

    public function test_save_stores_tags_for_a_front_end_url_but_skips_admin(): void
    {
        $store = new RecordingStore;
        $cacheTags = $this->make($store, []);

        $cacheTags->add(['post:1']);
        $cacheTags->save('https://example.com/page/');
        $this->assertSame('https://example.com/page/', $store->saved[0][0] ?? null);

        $store->saved = [];
        $cacheTags->save(admin_url('edit.php'));
        $this->assertEmpty($store->saved, 'admin urls are not stored');
    }

    public function test_bind_action_tracks_it_for_has_action(): void
    {
        $cacheTags = $this->make(new RecordingStore, []);
        $action = new RecordingAction;

        $cacheTags->bindAction($action);

        $this->assertTrue($action->bound, 'bind() was called');
        $this->assertTrue($cacheTags->hasAction($action));
        $this->assertTrue($cacheTags->hasAction(RecordingAction::class));
        $this->assertFalse($cacheTags->hasAction('No\\Such\\Action'));
    }
}
