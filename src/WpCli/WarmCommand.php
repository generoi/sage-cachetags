<?php

namespace Genero\Sage\CacheTags\WpCli;

use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Contracts\ListableStore;
use Genero\Sage\CacheTags\Warmer;
use WP_CLI;
use WP_CLI_Command;

/**
 * Warm the page cache with the pages purges queued (WarmInvalidator).
 */
class WarmCommand extends WP_CLI_Command
{
    /**
     * Request queued pages from the server itself, one at a time.
     *
     * Meant for the server's crontab, every minute. A run stops early when the
     * site answers 429 or 503, so warming never competes with real traffic.
     *
     * ## OPTIONS
     *
     * [--seconds=<n>]
     * : How long this run may take.
     * ---
     * default: 50
     * ---
     *
     * [--pause=<seconds>]
     * : Pause between requests.
     * ---
     * default: 0.2
     * ---
     *
     * [--all]
     * : First queue every URL the store holds, e.g. weekly before the cache expires.
     *
     * ## EXAMPLES
     *
     *     # crontab
     *     * * * * * cd /path/to/site/web && ../vendor/bin/wp cachetags warm --quiet
     *
     * @param  string[]  $args
     * @param  array<string, string>  $assoc
     */
    public function __invoke($args, $assoc): void
    {
        $cacheTags = CacheTags::getInstance();
        if ($cacheTags === null) {
            WP_CLI::error('CacheTags has not been bootstrapped.');
        }

        if (isset($assoc['all'])) {
            if (! $cacheTags->store instanceof ListableStore) {
                WP_CLI::error('The active store ('.get_class($cacheTags->store).') cannot list its URLs.');
            }
            Warmer::queue($cacheTags->store->urls(Warmer::MAX_QUEUE));
        }

        $result = Warmer::run((int) ($assoc['seconds'] ?? 50), (float) ($assoc['pause'] ?? 0.2));
        $summary = sprintf('Warmed %d page(s), %d failed, %d left.', $result['warmed'], $result['failed'], $result['left']);

        if ($result['stopped'] !== null) {
            WP_CLI::warning("{$summary} Stopped: {$result['stopped']}.");

            return;
        }
        WP_CLI::success($summary);
    }
}
