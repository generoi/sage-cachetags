<?php

namespace Genero\Sage\CacheTags\WpCli;

use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Concerns\CreatesDatabaseTable;
use WP_CLI;
use WP_CLI_Command;

/**
 * WP-CLI command to scaffold the database store table.
 */
class DatabaseCommand extends WP_CLI_Command
{
    use CreatesDatabaseTable;

    /**
     * Scaffold the database store table.
     *
     * ## OPTIONS
     *
     * [--rebuild]
     * : Drop and recreate the table (migrating its schema) and flush the cache.
     * The store is a rebuildable cache, so this avoids a slow locking ALTER; the
     * flush clears the edge so nothing is left stale while the store refills.
     *
     * ## EXAMPLES
     *
     *     # Create the cache tags table where missing
     *     $ wp cachetags database
     *
     *     # Migrate to the latest schema (drop + recreate + flush)
     *     $ wp cachetags database --rebuild
     *
     * @param  string[]  $args
     * @param  array<string, string>  $assoc
     */
    public function __invoke($args = [], $assoc = []): void
    {
        $rebuild = (bool) ($assoc['rebuild'] ?? false);

        if (is_multisite()) {
            foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $blogId) {
                switch_to_blog($blogId);
                try {
                    $this->provision($rebuild, $blogId);
                } catch (\Throwable $e) {
                    // Don't let one bad subsite abort provisioning the rest.
                    WP_CLI::warning("Skipped site {$blogId}: {$e->getMessage()}");
                } finally {
                    restore_current_blog();
                }
            }
        } else {
            $this->provision($rebuild);
        }

        if ($rebuild && ($cacheTags = CacheTags::getInstance()) !== null) {
            $cacheTags->flush();
            WP_CLI::success('Flushed caches (the rebuilt store starts empty)');
        }
    }

    private function provision(bool $rebuild, ?int $blogId = null): void
    {
        $rebuild ? $this->rebuildTable() : $this->createTable();

        $action = $rebuild ? 'Rebuilt' : 'Created';
        WP_CLI::success($blogId ? "{$action} table on site {$blogId}" : "{$action} table");
    }
}
