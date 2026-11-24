<?php

use Genero\Sage\CacheTags\Stores\WordpressDbStore;

/**
 * @covers \Genero\Sage\CacheTags\Stores\WordpressDbStore
 */
class TestStoreDeadlock extends WP_UnitTestCase
{
    /**
     * A store whose first $failures writes fail with $error, like a deadlock
     * victim InnoDB rolled back; later writes run for real.
     */
    private function store(int $failures, string $error = 'Deadlock found when trying to get lock; try restarting transaction'): WordpressDbStore
    {
        return new class($failures, $error) extends WordpressDbStore
        {
            /** @var string[] */
            public array $queries = [];

            public function __construct(private int $failures, private string $error) {}

            protected function query(string $sql)
            {
                global $wpdb;
                $this->queries[] = $sql;

                if ($this->failures-- > 0) {
                    $wpdb->last_error = $this->error;

                    return false;
                }

                return parent::query($sql);
            }
        };
    }

    public function set_up(): void
    {
        parent::set_up();
        (new WordpressDbStore)->flush();
    }

    public function test_a_deadlocked_save_is_retried_and_stores_the_tags(): void
    {
        $store = $this->store(1);

        $this->assertTrue($store->save(['post:1'], '/a/'));
        $this->assertCount(2, $store->queries, 'one retry');
        $this->assertSame(['/a/'], $store->get(['post:1']));
    }

    public function test_a_save_that_keeps_deadlocking_gives_up(): void
    {
        $store = $this->store(10);

        $this->assertFalse($store->save(['post:1'], '/a/'));
        $this->assertCount(1 + WordpressDbStore::DEADLOCK_RETRIES, $store->queries);
        $this->assertSame([], $store->get(['post:1']));
    }

    public function test_other_errors_are_not_retried(): void
    {
        $store = $this->store(1, "Table 'wp_cache_tags' doesn't exist");

        $this->assertFalse($store->save(['post:1'], '/a/'));
        $this->assertCount(1, $store->queries);
    }

    public function test_tags_are_written_in_a_stable_order(): void
    {
        $store = $this->store(0);
        $store->save(['site:1:post:2', 'site:1', 'archive:post'], '/a/');

        $sql = $store->queries[0];
        $this->assertLessThan(strpos($sql, "'site:1'"), strpos($sql, "'archive:post'"));
        $this->assertLessThan(strpos($sql, "'site:1:post:2'"), strpos($sql, "'site:1'"));
    }
}
