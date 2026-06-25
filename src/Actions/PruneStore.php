<?php

namespace Genero\Sage\CacheTags\Actions;

use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Contracts\Action;
use Genero\Sage\CacheTags\PruneCron;

/**
 * Schedule the daily store garbage collection (prune cache_tags rows not
 * re-rendered within `age`), so the store doesn't accumulate URLs that never
 * re-render — query-string / bot / campaign variants, dead permalinks.
 *
 * On by default; wired by Bootstrap with the configured age. Set the base age to
 * null (config `prune-older-than`) to opt out. The age MUST exceed the edge
 * cache's max TTL — pruning a URL still cached at the edge leaves an object that
 * can no longer be purged by tag.
 */
class PruneStore implements Action
{
    public function __construct(protected CacheTags $cacheTags, protected string $age = '30d') {}

    public function bind(): void
    {
        PruneCron::register($this->age);
    }
}
