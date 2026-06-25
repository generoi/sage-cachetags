<?php

namespace Genero\Sage\CacheTags\Contracts;

use DateTimeInterface;

/**
 * A store whose entries can be garbage-collected by age. Optional — the prune
 * command and cron degrade gracefully (no-op) when the active store doesn't
 * implement it (e.g. the in-memory TransientStore).
 *
 * "Age" is last-seen: a store implementing this must bump an entry's timestamp
 * whenever it's re-stored, so an actively-rendered URL is never pruned. The cutoff
 * must exceed the edge cache's max TTL, or a still-cached page could be pruned and
 * left unpurgeable.
 */
interface PrunableStore
{
    /**
     * Delete entries last stored before $olderThan, in batches of $batch to avoid
     * a long table lock. Returns the number of rows removed.
     */
    public function prune(DateTimeInterface $olderThan, int $batch = 1000): int;
}
