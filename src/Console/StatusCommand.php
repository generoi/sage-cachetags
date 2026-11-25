<?php

namespace Genero\Sage\CacheTags\Console;

use Genero\Sage\CacheTags\Actions\KinstaTermMenuClears;
use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Contracts\InspectableStore;
use Genero\Sage\CacheTags\DeferredQueue;
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

        foreach ($this->app->make(CacheTags::class)->action(KinstaTermMenuClears::class)?->status() ?? [] as [$ok, $message]) {
            $ok ? $this->line($message) : $this->warn($message);
        }

        foreach ($this->app->make(CacheTags::class)->invalidators as $invalidator) {
            if (method_exists($invalidator, 'deferred')) {
                $this->deferredStatus(get_class($invalidator), $invalidator::deferred());
            }
        }

        return self::SUCCESS;
    }

    /**
     * Rate-limited purges wait for a cron retry; a queue whose retry is overdue
     * means cron isn't running and the edge is serving stale content.
     */
    protected function deferredStatus(string $invalidator, DeferredQueue $queue): void
    {
        $entries = $queue->all();
        if ($entries === []) {
            return;
        }

        $count = array_sum(array_map(fn ($items) => $items === true ? 1 : count($items), $entries));
        $next = wp_next_scheduled($queue->hook);
        $message = sprintf('%s: %d deferred purge(s), retry %s', class_basename($invalidator), $count, $next ? human_time_diff($next).($next < time() ? ' overdue' : ' from now') : 'not scheduled');

        $next && $next > time() - 5 * MINUTE_IN_SECONDS ? $this->line($message) : $this->warn($message);
    }
}
