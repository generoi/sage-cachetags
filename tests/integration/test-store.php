<?php

use Genero\Sage\CacheTags\Stores\TransientStore;
use Genero\Sage\CacheTags\Stores\WordpressDbStore;

/**
 * @covers \Genero\Sage\CacheTags\Stores\WordpressDbStore
 * @covers \Genero\Sage\CacheTags\Stores\TransientStore
 */
class TestStore extends WP_UnitTestCase
{
    /** @return iterable<string, array{0: callable}> */
    public function stores(): iterable
    {
        yield 'database' => [fn () => new WordpressDbStore];
        yield 'transient' => [fn () => new TransientStore];
    }

    /**
     * @dataProvider stores
     */
    public function test_save_get_and_clear_round_trip(callable $make): void
    {
        $store = $make();
        $store->save(['post:1', 'archive:post'], '/a/');
        $store->save(['post:1'], '/b/');

        $this->assertEqualsCanonicalizing(['/a/', '/b/'], $store->get(['post:1']));
        $this->assertSame(['/a/'], $store->get(['archive:post']));
        $this->assertSame([], $store->get(['post:999']));

        $store->clear(['/a/'], ['post:1', 'archive:post']);

        $this->assertSame(['/b/'], $store->get(['post:1']));
        $this->assertSame([], $store->get(['archive:post']));
    }

    /**
     * @dataProvider stores
     */
    public function test_save_is_idempotent(callable $make): void
    {
        $store = $make();
        $store->save(['post:1'], '/a/');
        $store->save(['post:1'], '/a/');

        $this->assertSame(['/a/'], $store->get(['post:1']));
    }

    /**
     * @dataProvider stores
     */
    public function test_flush_empties_the_store(callable $make): void
    {
        $store = $make();
        $store->save(['post:1'], '/a/');

        $store->flush();

        $this->assertSame([], $store->get(['post:1']));
    }

    public function test_save_with_no_tags_is_a_noop(): void
    {
        $store = new WordpressDbStore;

        $this->assertTrue($store->save([], '/a/'));
        $this->assertSame([], $store->get(['post:1']));
    }
}
