<?php

use Genero\Sage\CacheTags\Contracts\PrunableStore;
use Genero\Sage\CacheTags\Stores\TransientStore;
use Genero\Sage\CacheTags\Stores\WordpressDbStore;
use Genero\Sage\CacheTags\Util;

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

    public function test_inspect_reports_stats_top_tags_and_tags_for_a_url(): void
    {
        $store = new WordpressDbStore;
        $store->flush();
        $store->save(['post:1', 'archive:post'], '/a/');
        $store->save(['archive:post'], '/b/');

        $this->assertSame(['rows' => 3, 'tags' => 2, 'urls' => 2], $store->stats());

        // archive:post (2 urls) is wider than post:1 (1 url).
        $this->assertSame(
            [['tag' => 'archive:post', 'urls' => 2], ['tag' => 'post:1', 'urls' => 1]],
            $store->topTags(10)
        );

        $this->assertEqualsCanonicalizing(['post:1', 'archive:post'], $store->tagsForUrl('/a/'));
        $this->assertSame([], $store->tagsForUrl('/missing/'));
    }

    public function test_prune_removes_rows_older_than_the_cutoff(): void
    {
        global $wpdb;
        $store = new WordpressDbStore;
        $store->flush();
        $store->save(['post:1'], '/stale/');
        $store->save(['post:2'], '/fresh/');

        // Backdate the stale row's last-seen to 40 days ago.
        $wpdb->query("UPDATE {$wpdb->prefix}cache_tags SET created_at = (NOW() - INTERVAL 40 DAY) WHERE url = '/stale/'");

        $removed = $store->prune(new DateTimeImmutable('-30 days'));

        $this->assertSame(1, $removed, 'one stale row removed, the recent one kept');
        $this->assertSame([], $store->get(['post:1']));
        $this->assertSame(['/fresh/'], $store->get(['post:2']));
    }

    public function test_save_refreshes_last_seen_only_once_per_day(): void
    {
        global $wpdb;
        $store = new WordpressDbStore;
        $store->flush();
        $store->save(['post:1'], '/a/');

        $seenAt = fn () => $wpdb->get_var("SELECT created_at FROM {$wpdb->prefix}cache_tags WHERE url = '/a/'");
        $first = $seenAt();

        // A re-store within the day must NOT move the timestamp (no write churn).
        $store->save(['post:1'], '/a/');
        $this->assertSame($first, $seenAt(), 'unchanged within the refresh window');

        // Once it's older than a day, the next store refreshes it.
        $wpdb->query("UPDATE {$wpdb->prefix}cache_tags SET created_at = (NOW() - INTERVAL 2 DAY) WHERE url = '/a/'");
        $staleValue = $seenAt();
        $store->save(['post:1'], '/a/');
        $this->assertGreaterThan($staleValue, $seenAt(), 'refreshed to now once stale');
    }

    public function test_only_the_db_store_is_prunable(): void
    {
        $this->assertInstanceOf(PrunableStore::class, new WordpressDbStore);
        $this->assertNotInstanceOf(PrunableStore::class, new TransientStore);
    }

    public function test_cutoff_from_age_parses_hours_days_weeks(): void
    {
        $this->assertNull(Util::cutoffFromAge('soon'));
        $this->assertNull(Util::cutoffFromAge('30'));

        // 30d ago is ~30*24h before now (allow a second of slack).
        $delta = (new DateTimeImmutable)->getTimestamp() - Util::cutoffFromAge('30d')->getTimestamp();
        $this->assertEqualsWithDelta(30 * 86400, $delta, 5);

        $this->assertEqualsWithDelta(12 * 3600, (new DateTimeImmutable)->getTimestamp() - Util::cutoffFromAge('12h')->getTimestamp(), 5);
        $this->assertEqualsWithDelta(4 * 7 * 86400, (new DateTimeImmutable)->getTimestamp() - Util::cutoffFromAge('4w')->getTimestamp(), 5);
    }
}
