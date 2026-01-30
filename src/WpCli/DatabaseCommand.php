<?php

namespace Genero\Sage\CacheTags\WpCli;

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
     * ## EXAMPLES
     *
     *     # Create the cache tags table
     *     $ wp cachetags database
     */
    public function __invoke(): void
    {
        if (is_multisite()) {
            foreach (get_sites() as $site) {
                switch_to_blog($site->blog_id);
                $this->createTable();
                WP_CLI::success("Created table on site {$site->blog_id}");
                restore_current_blog();
            }
        } else {
            $this->createTable();
            WP_CLI::success('Created table');
        }
    }
}
