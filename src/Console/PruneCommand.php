<?php

namespace Genero\Sage\CacheTags\Console;

use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Util;
use Roots\Acorn\Console\Commands\Command;

class PruneCommand extends Command
{
    /**
     * @var string
     */
    protected $name = 'cachetags:prune';

    /**
     * @var string
     */
    protected $description = 'Garbage-collect stale entries from the cache-tag store';

    protected $signature = 'cachetags:prune
        {--older-than=30d : Age threshold — hours/days/weeks, e.g. 12h, 30d, 4w. Must exceed the edge TTL.}
        {--batch=1000 : Rows to delete per query}';

    public function handle(): int
    {
        $cacheTags = $this->app->make(CacheTags::class);

        $age = (string) $this->option('older-than');
        $cutoff = Util::cutoffFromAge($age);
        if ($cutoff === null) {
            $this->error("Invalid --older-than '{$age}'; use e.g. 12h, 30d, 4w.");

            return self::FAILURE;
        }

        $removed = $cacheTags->prune($cutoff, (int) $this->option('batch'));

        if ($removed === null) {
            $this->error('The active store ('.get_class($cacheTags->store).') does not support pruning.');

            return self::FAILURE;
        }

        $this->info(sprintf('Pruned %d store row(s) older than %s.', $removed, $age));

        return self::SUCCESS;
    }
}
