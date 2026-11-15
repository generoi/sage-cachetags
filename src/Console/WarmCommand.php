<?php

namespace Genero\Sage\CacheTags\Console;

use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Contracts\ListableStore;
use Genero\Sage\CacheTags\Warmer;
use Roots\Acorn\Console\Commands\Command;

class WarmCommand extends Command
{
    /**
     * @var string
     */
    protected $name = 'cachetags:warm';

    /**
     * @var string
     */
    protected $description = 'Warm the page cache with the pages purges queued';

    protected $signature = 'cachetags:warm
        {--seconds=50 : How long this run may take}
        {--pause=0.2 : Pause between requests, in seconds}
        {--all : First queue every URL the store holds}';

    public function handle(): int
    {
        $cacheTags = $this->app->make(CacheTags::class);

        if ($this->option('all')) {
            if (! $cacheTags->store instanceof ListableStore) {
                $this->error('The active store ('.get_class($cacheTags->store).') cannot list its URLs.');

                return self::FAILURE;
            }
            Warmer::queue($cacheTags->store->urls(Warmer::MAX_QUEUE));
        }

        $result = Warmer::run((int) $this->option('seconds'), (float) $this->option('pause'));
        $summary = sprintf('Warmed %d page(s), %d failed, %d left.', $result['warmed'], $result['failed'], $result['left']);

        if ($result['stopped'] !== null) {
            $this->warn("{$summary} Stopped: {$result['stopped']}.");
        } else {
            $this->info($summary);
        }

        return self::SUCCESS;
    }
}
