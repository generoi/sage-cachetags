<?php

namespace Genero\Sage\CacheTags\WpCli;

use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Contracts\InspectableStore;
use WP_CLI;
use WP_CLI_Command;

/**
 * Inspect the cache-tag store.
 */
class StatusCommand extends WP_CLI_Command
{
    /**
     * Show cache-tag store statistics, or the tags a URL is stored under.
     *
     * ## OPTIONS
     *
     * [--top=<n>]
     * : How many of the widest-fan-out tags to list.
     * ---
     * default: 20
     * ---
     *
     * [--url=<url>]
     * : Instead of stats, list the tags this URL is stored under.
     *
     * ## EXAMPLES
     *
     *     $ wp cachetags status
     *     $ wp cachetags status --url=https://example.com/article/
     *
     * @param  string[]  $args
     * @param  array<string, string>  $assoc
     */
    public function __invoke($args, $assoc): void
    {
        $store = $this->store();

        if (isset($assoc['url'])) {
            $tags = $store->tagsForUrl($assoc['url']);
            $tags ? WP_CLI::log(implode(PHP_EOL, $tags)) : WP_CLI::warning('URL not in the store.');

            return;
        }

        $stats = $store->stats();
        WP_CLI::log(sprintf('Rows: %d  Tags: %d  URLs: %d', $stats['rows'], $stats['tags'], $stats['urls']));

        $top = $store->topTags((int) ($assoc['top'] ?? 20));
        if ($top) {
            WP_CLI::log('');
            WP_CLI::log('Widest-fan-out tags (a change to one purges this many URLs):');
            WP_CLI\Utils\format_items('table', $top, ['tag', 'urls']);
        }
    }

    private function store(): InspectableStore
    {
        $cacheTags = CacheTags::getInstance();
        if ($cacheTags === null) {
            WP_CLI::error('CacheTags has not been bootstrapped.');
        }

        if (! $cacheTags->store instanceof InspectableStore) {
            WP_CLI::error('The active store ('.get_class($cacheTags->store).') does not support inspection.');
        }

        return $cacheTags->store;
    }
}
