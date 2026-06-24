<?php

/**
 * PHPUnit bootstrap for sage-cachetags.
 *
 * Loads the WordPress test framework (provided by wp-env / wp-phpunit) and
 * boots the package the way a standalone install would, with the REST API
 * action enabled so the integration suite can exercise it.
 */

use Genero\Sage\CacheTags\Actions\Core;
use Genero\Sage\CacheTags\Actions\HttpHeader;
use Genero\Sage\CacheTags\Actions\RestApi;
use Genero\Sage\CacheTags\Bootstrap;
use Genero\Sage\CacheTags\Concerns\CreatesDatabaseTable;
use Genero\Sage\CacheTags\Stores\WordpressDbStore;

require_once dirname(__DIR__).'/vendor/autoload.php';

// Give access to tests_add_filter().
require_once getenv('WP_PHPUNIT__DIR').'/includes/functions.php';

// Polylang needs PLL_ADMIN to initialize on a fresh DB without front-end query
// filtering (which would interfere with the rest of the suite).
if (! defined('PLL_ADMIN')) {
    define('PLL_ADMIN', true);
}

tests_add_filter('muplugins_loaded', function (): void {
    // wp-phpunit uses a fresh DB where no plugins are "activated"; require any
    // real plugins we integration-test so they initialize during WP boot. Glob
    // the path since wp-env names the dir after the zip (e.g. polylang vs
    // polylang.latest-stable).
    foreach (['woocommerce*/woocommerce.php', 'polylang*/polylang.php'] as $pattern) {
        foreach (glob(dirname(__DIR__, 2).'/'.$pattern) as $path) {
            require_once $path;
        }
    }

    (new Bootstrap)
        ->store(WordpressDbStore::class)
        ->httpHeader('Cache-Tag')
        ->actions([Core::class, HttpHeader::class, RestApi::class])
        ->bootstrap();

    // The activation hook does not run in the test environment, so create the
    // store table up front.
    (new class
    {
        use CreatesDatabaseTable;

        public function run(): void
        {
            $this->createTable();
        }
    })->run();
});

// Start up the WP testing environment.
require getenv('WP_PHPUNIT__DIR').'/includes/bootstrap.php';

// Configure Polylang languages in the fresh test DB so pll_* works.
if (function_exists('PLL') && PLL() && isset(PLL()->model)) {
    $model = PLL()->model;
    foreach ([
        ['name' => 'English', 'slug' => 'en', 'locale' => 'en_US', 'rtl' => 0, 'term_group' => 0, 'flag' => 'us'],
        ['name' => 'Finnish', 'slug' => 'fi', 'locale' => 'fi', 'rtl' => 0, 'term_group' => 1, 'flag' => 'fi'],
    ] as $language) {
        if (! $model->get_language($language['slug'])) {
            $model->add_language($language);
        }
    }
    $model->update_default_lang('en');
    $model->clean_languages_cache();
}
