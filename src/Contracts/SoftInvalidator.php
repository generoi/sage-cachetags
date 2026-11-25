<?php

namespace Genero\Sage\CacheTags\Contracts;

/**
 * An invalidator that marks cached content stale instead of removing it, and
 * can be told to remove it for one flush or clear.
 */
interface SoftInvalidator extends Invalidator
{
    /** Flush as a hard purge: the next request is a MISS. */
    public function hardFlush(): bool;

    /** Clear as a hard purge: the next request for these is a MISS. */
    public function hardClear(array $urls, array $tags): bool;
}
