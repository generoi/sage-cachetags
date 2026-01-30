<?php

namespace Genero\Sage\CacheTags\Console;

use Genero\Sage\CacheTags\Concerns\CreatesDatabaseTable;
use Roots\Acorn\Console\Commands\Command;

class DatabaseCommand extends Command
{
    use CreatesDatabaseTable;

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'cachetags:database';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scaffold the database store table';

    public function handle(): void
    {
        if (\is_multisite()) {
            foreach (\get_sites() as $site) {
                \switch_to_blog($site->blog_id);
                $this->createTable();
                $this->line("Created table on site {$site->blog_id}");
                \restore_current_blog();
            }
        } else {
            $this->createTable();
            $this->line('Created table');
        }
    }
}
