<?php

namespace Genero\Sage\CacheTags\WpCli;

use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Util;
use WP_CLI;
use WP_CLI_Command;

/**
 * Garbage-collect stale entries from the cache-tag store.
 */
class PruneCommand extends WP_CLI_Command
{
    /**
     * Delete store entries not re-stored within the given age.
     *
     * Entries refresh their timestamp on every render, so this removes only URLs
     * that haven't rendered in the window — typically query-string / bot /
     * campaign variants that accumulate forever otherwise. SAFE ONLY when the age
     * exceeds your edge cache's max TTL: pruning a URL still cached at the edge
     * leaves an object you can no longer purge by tag.
     *
     * ## OPTIONS
     *
     * [--older-than=<age>]
     * : Age threshold — hours/days/weeks, e.g. 12h, 30d, 4w.
     * ---
     * default: 30d
     * ---
     *
     * [--batch=<n>]
     * : Rows to delete per query (keeps the lock short on large stores).
     * ---
     * default: 1000
     * ---
     *
     * ## EXAMPLES
     *
     *     $ wp cachetags prune --older-than=30d
     *     Success: Pruned 4123 store row(s) older than 30d.
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

        $age = $assoc['older-than'] ?? '30d';
        $cutoff = Util::cutoffFromAge($age);
        if ($cutoff === null) {
            WP_CLI::error("Invalid --older-than '{$age}'; use e.g. 12h, 30d, 4w.");
        }

        $removed = $cacheTags->prune($cutoff, (int) ($assoc['batch'] ?? 1000));

        if ($removed === null) {
            WP_CLI::error('The active store ('.get_class($cacheTags->store).') does not support pruning.');
        }

        WP_CLI::success(sprintf('Pruned %d store row(s) older than %s.', $removed, $age));
    }
}
