<?php

use Genero\Sage\CacheTags\DeferredQueue;

/**
 * @covers \Genero\Sage\CacheTags\DeferredQueue
 */
class TestDeferredQueue extends WP_UnitTestCase
{
    private DeferredQueue $queue;

    public function set_up(): void
    {
        parent::set_up();
        $this->queue = new DeferredQueue('test');
        $this->queue->clear();
    }

    public function tear_down(): void
    {
        $this->queue->clear();
        parent::tear_down();
    }

    public function test_pushes_coalesce_per_key_and_schedule_one_retry(): void
    {
        $this->queue->push(['a' => ['post:1', 'post:2']]);
        $this->queue->push(['a' => ['post:2', 'post:3'], 'b' => ['https://example.com/']]);

        $this->assertSame([
            'a' => ['post:1', 'post:2', 'post:3'],
            'b' => ['https://example.com/'],
        ], $this->queue->all());
        $this->assertNotFalse(wp_next_scheduled($this->queue->hook));
    }

    public function test_purge_everything_supersedes_a_keys_list(): void
    {
        $this->queue->push(['a' => ['post:1']]);
        $this->queue->push(['a' => true]);
        $this->queue->push(['a' => ['post:2']]);

        $this->assertSame(['a' => true], $this->queue->all());
    }

    public function test_take_empties_the_queue(): void
    {
        $this->queue->push(['a' => ['post:1']]);

        $this->assertSame(['a' => ['post:1']], $this->queue->take());
        $this->assertSame([], $this->queue->all());
    }

    public function test_queues_with_different_names_are_separate(): void
    {
        // A name that extends this one must not see its rows.
        $other = new DeferredQueue('test_other');
        $this->queue->push(['a' => ['post:1']]);
        $other->push(['a' => ['post:9']]);

        $this->assertSame(['a' => ['post:1']], $this->queue->take());
        $this->assertSame(['a' => ['post:9']], $other->take());
        $other->clear();
    }

    public function test_clear_drops_entries_and_the_scheduled_retry(): void
    {
        $this->queue->push(['a' => ['post:1']]);

        $this->queue->clear();

        $this->assertSame([], $this->queue->all());
        $this->assertFalse(wp_next_scheduled($this->queue->hook));
    }
}
