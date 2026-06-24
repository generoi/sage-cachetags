<?php

use Genero\Sage\CacheTags\Stores\CacheTagStore;
use Genero\Sage\CacheTags\Stores\TransientStore;

/**
 * @covers \Genero\Sage\CacheTags\Stores\TransientStore
 * @covers \Genero\Sage\CacheTags\Stores\CacheTagStore
 */
class TestTransientStore extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        delete_option('sage_cache_tags');
    }

    public function test_saves_and_reads_back_urls_per_tag(): void
    {
        $store = new TransientStore;
        $store->save(['post:1', 'term:2'], '/a/');
        $store->save(['post:1'], '/b/');

        $this->assertEqualSets(['/a/', '/b/'], $store->get(['post:1']));
        $this->assertSame(['/a/'], $store->get(['term:2']));
    }

    public function test_save_dedupes_a_repeated_url(): void
    {
        $store = new TransientStore;
        $store->save(['post:1'], '/a/');
        $store->save(['post:1'], '/a/');

        $this->assertSame(['/a/'], $store->get(['post:1']));
    }

    public function test_clear_removes_urls_and_prunes_emptied_tags(): void
    {
        $store = new TransientStore;
        $store->save(['post:1'], '/a/');
        $store->save(['post:1'], '/b/');

        $store->clear(['/a/'], ['post:1']);

        $this->assertSame(['/b/'], $store->get(['post:1']));
    }

    public function test_flush_empties_the_store(): void
    {
        $store = new TransientStore;
        $store->save(['post:1'], '/a/');

        $store->flush();

        $this->assertSame([], $store->get(['post:1']));
    }

    public function test_cachetag_store_is_a_header_only_passthrough(): void
    {
        $store = new CacheTagStore;

        $this->assertTrue($store->save(['post:1'], '/a/'));
        $this->assertSame(['post:1'], $store->get(['post:1']));
        $this->assertTrue($store->clear([], []));
        $this->assertTrue($store->flush());
    }
}
