<?php

namespace Genero\Sage\CacheTags\Console;

use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Concerns\CreatesDatabaseTable;
use Roots\Acorn\Console\Commands\Command;

class DatabaseCommand extends Command
{
    use CreatesDatabaseTable;

    /**
     * @var string
     */
    protected $description = 'Scaffold the database store table';

    protected $signature = 'cachetags:database {--rebuild : Drop, recreate (migrate schema) and flush the cache}';

    public function handle(): void
    {
        $rebuild = (bool) $this->option('rebuild');

        if (\is_multisite()) {
            foreach (\get_sites(['fields' => 'ids', 'number' => 0]) as $blogId) {
                \switch_to_blog($blogId);
                try {
                    $this->provision($rebuild, $blogId);
                } catch (\Throwable $e) {
                    // Don't let one bad subsite abort provisioning the rest.
                    $this->line("Skipped site {$blogId}: {$e->getMessage()}");
                } finally {
                    \restore_current_blog();
                }
            }
        } else {
            $this->provision($rebuild);
        }

        if ($rebuild) {
            $this->app->make(CacheTags::class)->flush();
            $this->line('Flushed caches (the rebuilt store starts empty)');
        }
    }

    private function provision(bool $rebuild, ?int $blogId = null): void
    {
        $rebuild ? $this->rebuildTable() : $this->createTable();

        $action = $rebuild ? 'Rebuilt' : 'Created';
        $this->line($blogId ? "{$action} table on site {$blogId}" : "{$action} table");
    }
}
