<?php

namespace Genero\Sage\CacheTags\WpCli;

use Genero\Sage\CacheTags\CacheTags;
use WP_CLI;
use WP_CLI_Command;

/**
 * WP-CLI command to flush all caches.
 */
class FlushCommand extends WP_CLI_Command
{
    /**
     * Flush all caches.
     *
     * ## OPTIONS
     *
     * [--hard]
     * : Purge with soft invalidators too, instead of marking content stale.
     *
     * ## EXAMPLES
     *
     *     # Flush all caches
     *     $ wp cachetags flush
     *
     *     # Flush so that nothing stale is served while the cache refills
     *     $ wp cachetags flush --hard
     *
     * @param  string[]  $args
     * @param  array<string, string>  $assoc
     */
    public function __invoke($args = [], $assoc = []): void
    {
        $cacheTags = CacheTags::getInstance();
        if ($cacheTags === null) {
            WP_CLI::error('CacheTags has not been bootstrapped. Please ensure CacheTags is initialized before running this command.');
        }

        $result = $cacheTags->flush((bool) ($assoc['hard'] ?? false));

        if ($result) {
            WP_CLI::success('Flushed caches');
        } else {
            WP_CLI::error('Flush failed');
        }
    }
}
