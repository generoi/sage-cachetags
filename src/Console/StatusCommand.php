<?php

namespace Genero\Sage\CacheTags\Console;

use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Contracts\InspectableStore;
use Roots\Acorn\Console\Commands\Command;

class StatusCommand extends Command
{
    /**
     * @var string
     */
    protected $name = 'cachetags:status';

    /**
     * @var string
     */
    protected $description = 'Inspect the cache-tag store';

    /**
     * The URL is a positional argument: under `wp acorn` WP-CLI takes `--url` as
     * its own global flag, so the option never reaches the command. The option
     * stays for other ways of running it.
     */
    protected $signature = 'cachetags:status {url? : List the tags this URL is stored under} {--top=20 : How many widest-fan-out tags to list} {--url= : Same as the url argument}';

    public function handle(): int
    {
        $store = $this->app->make(CacheTags::class)->store;

        if (! $store instanceof InspectableStore) {
            $this->error('The active store ('.get_class($store).') does not support inspection.');

            return self::FAILURE;
        }

        if ($url = $this->argument('url') ?: $this->option('url')) {
            $tags = $store->tagsForUrl($url);
            $tags ? $this->line(implode(PHP_EOL, $tags)) : $this->warn('URL not in the store.');

            return self::SUCCESS;
        }

        $stats = $store->stats();
        $this->line(sprintf('Rows: %d  Tags: %d  URLs: %d', $stats['rows'], $stats['tags'], $stats['urls']));

        $top = $store->topTags((int) $this->option('top'));
        if ($top) {
            $this->newLine();
            $this->table(['tag', 'urls'], array_map(fn ($row) => [$row['tag'], $row['urls']], $top));
        }

        return self::SUCCESS;
    }
}
