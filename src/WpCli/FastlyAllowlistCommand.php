<?php

namespace Genero\Sage\CacheTags\WpCli;

use Genero\Sage\CacheTags\Fastly\AllowlistDictionary;
use Genero\Sage\CacheTags\Fastly\QueryAllowlist;
use WP_CLI;
use WP_CLI_Command;

/**
 * Inspect and manage the Fastly query-param allowlist (edge cache-key
 * normalisation). See the README "Fastly query-param allowlist" section.
 */
class FastlyAllowlistCommand extends WP_CLI_Command
{
    /**
     * Print the query-param allowlist WordPress computes — review it before sync.
     *
     * ## EXAMPLES
     *
     *     $ wp cachetags fastly-allowlist preview
     *
     * @param  string[]  $args
     * @param  array<string, string>  $assoc
     */
    public function preview($args, $assoc): void
    {
        $params = QueryAllowlist::collect();

        WP_CLI::log(implode(PHP_EOL, $params));
        WP_CLI::log('');
        WP_CLI::log(sprintf('%d cache-significant params. Anything else is stripped at the edge.', count($params)));
        WP_CLI::log('Add missing params via the cachetags/fastly-allowed-query-params filter, then `sync`.');
    }

    /**
     * Show the dictionary's current value at Fastly and whether it's in sync.
     *
     * ## OPTIONS
     *
     * [--dictionary=<name>]
     * : Override the configured Edge Dictionary name.
     *
     * @param  string[]  $args
     * @param  array<string, string>  $assoc
     */
    public function status($args, $assoc): void
    {
        $dictionary = $this->dictionary($assoc);
        $params = QueryAllowlist::collect();
        $current = $dictionary->current();

        WP_CLI::log('Computed : '.implode(',', $params));

        if ($current === null) {
            WP_CLI::warning('No allowlist at Fastly yet — run `sync`. (If you have: check the dictionary exists and the token has write_dictionaries scope.)');

            return;
        }

        WP_CLI::log('At Fastly: '.$current);
        WP_CLI::log($current === implode(',', $params) ? 'In sync.' : 'OUT OF SYNC — run `sync`.');
    }

    /**
     * Push the computed allowlist to the Fastly Edge Dictionary.
     *
     * ## OPTIONS
     *
     * [--dictionary=<name>]
     * : Override the configured Edge Dictionary name.
     *
     * [--force]
     * : Push even when the dictionary already matches.
     *
     * ## EXAMPLES
     *
     *     $ wp cachetags fastly-allowlist sync
     *
     * @param  string[]  $args
     * @param  array<string, string>  $assoc
     */
    public function sync($args, $assoc): void
    {
        $dictionary = $this->dictionary($assoc);
        $params = QueryAllowlist::collect();

        if ($dictionary->exceedsLimit($params)) {
            WP_CLI::error(sprintf(
                'Allowlist too long (%d chars > %d) — too many attributes/facets for one dictionary item.',
                strlen(implode(',', $params)),
                AllowlistDictionary::MAX_VALUE_LENGTH
            ));
        }

        if (! isset($assoc['force']) && $dictionary->isSynced($params)) {
            WP_CLI::success('Already in sync; nothing to push.');

            return;
        }

        $dictionary->push($params)
            ? WP_CLI::success(sprintf('Synced %d params to Fastly.', count($params)))
            : WP_CLI::error('Push failed — check FASTLY_SERVICE_ID / FASTLY_API_KEY and the dictionary name.');
    }

    /**
     * @param  array<string, string>  $assoc
     */
    private function dictionary($assoc): AllowlistDictionary
    {
        // Standalone WP-CLI has no Acorn config(); Bootstrap publishes the
        // configured name through this filter so both contexts resolve it.
        $name = $assoc['dictionary'] ?? apply_filters('cachetags/fastly-allowlist-dictionary', null);
        if (! $name) {
            WP_CLI::error('No dictionary configured. Set "fastly-allowlist-dictionary" or pass --dictionary=<name>.');
        }

        $dictionary = new AllowlistDictionary($name);
        if (! $dictionary->isConfigured()) {
            WP_CLI::error('FASTLY_SERVICE_ID / FASTLY_API_KEY are not set.');
        }

        return $dictionary;
    }
}
