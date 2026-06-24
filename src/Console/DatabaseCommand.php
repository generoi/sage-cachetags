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
            foreach (\get_sites(['fields' => 'ids', 'number' => 0]) as $blogId) {
                \switch_to_blog($blogId);
                try {
                    $this->createTable();
                    $this->line("Created table on site {$blogId}");
                } catch (\Throwable $e) {
                    // Don't let one bad subsite abort provisioning the rest.
                    $this->line("Skipped site {$blogId}: {$e->getMessage()}");
                } finally {
                    \restore_current_blog();
                }
            }
        } else {
            $this->createTable();
            $this->line('Created table');
        }
    }
}
