<?php

namespace Genero\Sage\CacheTags\WpCli;

use Genero\Sage\CacheTags\CacheTags;
use WP_CLI;
use WP_CLI_Command;

/**
 * WP-CLI command to flush all caches.
 */
class ClearCommand extends WP_CLI_Command
{
    /**
     * Clear cache tags.
     *
     * ## EXAMPLES
     *
     *     # Clear cache tags
     *     $ wp cachetags clear cache-tag1 cache-tag2
     */
    public function __invoke(array $args = []): void
    {
        $cacheTags = CacheTags::getInstance();
        if ($cacheTags === null) {
            WP_CLI::error('CacheTags has not been bootstrapped. Please ensure CacheTags is initialized before running this command.');
        }

        $cacheTags->clear($args);
        $result = $cacheTags->purgeQueued();

        if ($result) {
            WP_CLI::success('Cleared cache tags');
        } else {
            WP_CLI::error('Clear cache tags failed');
        }
    }
}
