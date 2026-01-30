<?php

namespace Genero\Sage\CacheTags\Console;

use Genero\Sage\CacheTags\CacheTags;
use Roots\Acorn\Console\Commands\Command;

class FlushCommand extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'cachetags:flush';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Flush all caches';

    public function handle(): void
    {
        $result = $this->app->make(CacheTags::class)
            ->flush();

        if ($result) {
            $this->line('Flushed caches');
        } else {
            $this->error('Flush failed');
        }
    }
}
