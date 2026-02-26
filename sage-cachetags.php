<?php

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
        use \Genero\Sage\CacheTags\Concerns\CreatesDatabaseTable;

        public function run(): void
        {
            $this->createTable();
        }
    };

    if (is_multisite()) {
        foreach (get_sites() as $site) {
            switch_to_blog($site->blog_id);
            $activator->run();
            restore_current_blog();
        }
    } else {
        $activator->run();
    }
});
