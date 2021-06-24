<?php

namespace Genero\Sage\CacheTags\Console;

use Roots\Acorn\Console\Commands\Command;
use WP_Site;

class DatabaseCommand extends Command
{
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

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        if (\is_multisite()) {
            collect(\get_sites())
                ->each(function (WP_Site $site) {
                    \switch_to_blog($site->blog_id);
                    $this->createTable();
                    $this->line("Created table on site {$site->blog_id}");
                    \restore_current_blog();
                });
        } else {
            $this->line('Created table');
            $this->createTable();
        }
    }

    public function createTable()
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        global $wpdb;
        $charsetCollate = $wpdb->get_charset_collate();

        \dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cache_tags (
            tag varchar(191) NOT NULL,
            url varchar(191) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (tag, url)
        ) $charsetCollate");
    }
}
