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

tests_add_filter('muplugins_loaded', function (): void {
    // wp-phpunit uses a fresh DB where no plugins are "activated"; require any
    // real plugins we integration-test so they initialize during WP boot.
    $woocommerce = dirname(__DIR__, 2).'/woocommerce/woocommerce.php';
    if (file_exists($woocommerce)) {
        require_once $woocommerce;
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
