<?php

namespace Genero\Sage\CacheTags\Console;

use Genero\Sage\CacheTags\CacheTags;
use Roots\Acorn\Console\Commands\Command;

class FlushCommand extends Command
{
    /**
     * The console command signature.
     *
     * @var string
     */
    protected $signature = 'cachetags:flush {--hard : Purge with soft invalidators too, instead of marking content stale}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Flush all caches';

    public function handle(): void
    {
        $result = $this->app->make(CacheTags::class)
            ->flush((bool) $this->option('hard'));

        if ($result) {
            $this->line('Flushed caches');
        } else {
            $this->error('Flush failed');
        }
    }
}
