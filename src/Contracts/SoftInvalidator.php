<?php

namespace Genero\Sage\CacheTags\Contracts;

/**
 * An invalidator that marks cached content stale instead of removing it, and
 * can be told to remove it for one flush.
 */
interface SoftInvalidator extends Invalidator
{
    /** Flush as a hard purge: the next request is a MISS. */
    public function hardFlush(): bool;
}
