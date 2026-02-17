<?php

namespace Genero\Sage\CacheTags\Console;

use Genero\Sage\CacheTags\CacheTags;
use Roots\Acorn\Console\Commands\Command;
use Symfony\Component\Console\Input\InputArgument;

class ClearCommand extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'cachetags:clear';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear cache tags';

    protected $signature = 'cachetags:clear {tags}';

    public function handle(): int
    {
        $tags = $this->argument('tags') ?? [];
        $tags = is_array($tags) ? $tags : [$tags];

        $cacheTags = $this->app->make(CacheTags::class);
        $cacheTags->clear($tags);
        $result = $cacheTags->purgeQueued();

        if ($result) {
            $this->line('Cleared cache tags');

            return self::SUCCESS;
        }

        $this->error('Clear cache tags failed');

        return self::FAILURE;
    }
}
