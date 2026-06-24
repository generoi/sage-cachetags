<?php

use Genero\Sage\CacheTags\Concerns\CreatesDatabaseTable;

/**
 * Plugin Name:  Sage Cache Tags
 * Plugin URI:   https://genero.fi
 * Description:  A plugin to cache tags for Sage.
 * Version:      2.1.0
 * Author:       Genero
 * Author URI:   https://genero.fi/
 * License:      MIT License
 */
if (file_exists(__DIR__.'/vendor/autoload.php')) {
    require_once __DIR__.'/vendor/autoload.php';
}

register_activation_hook(__FILE__, function (): void {
    $activator = new class
    {
        use CreatesDatabaseTable;

        public function run(): void
        {
            $this->createTable();
        }
    };

    if (is_multisite()) {
        // number => 0: get_sites() defaults to 100, which would leave every
        // subsite past the first 100 without a table (silent stale forever).
        foreach (get_sites(['number' => 0]) as $site) {
            switch_to_blog($site->blog_id);
            $activator->run();
            restore_current_blog();
        }
    } else {
        $activator->run();
    }
});
