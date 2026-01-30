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
     * ## EXAMPLES
     *
     *     # Flush all caches
     *     $ wp cachetags flush
     */
    public function __invoke(): void
    {
        $cacheTags = CacheTags::getInstance();
        if ($cacheTags === null) {
            WP_CLI::error('CacheTags has not been bootstrapped. Please ensure CacheTags is initialized before running this command.');
        }

        $result = $cacheTags->flush();

        if ($result) {
            WP_CLI::success('Flushed caches');
        } else {
            WP_CLI::error('Flush failed');
        }
    }
}
