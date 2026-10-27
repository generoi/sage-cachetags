<?php

namespace Genero\Sage\CacheTags;

/**
 * A WP-cron retry queue for purges an invalidator couldn't send now (e.g. a
 * rate-limited API). Entries are keyed — a zone, an endpoint, 'urls' — and hold
 * a list of tags/URLs, or true for "purge everything", which supersedes the
 * list for that key. Pushes coalesce per key and one cron event sends them.
 *
 * Each push() writes its own option row, so concurrent requests never
 * overwrite each other's entries (no read-modify-write); take() deletes exactly
 * the rows it read, so a push that lands meanwhile survives for the next run.
 *
 * The owning invalidator hooks its retry onto hook() and calls take() there:
 *
 *     add_action($queue->hook, [static::class, 'runDeferred']);
 */
class DeferredQueue
{
    public readonly string $optionPrefix;

    public readonly string $hook;

    /**
     * @param  string  $name  Unique per invalidator, e.g. 'cloudflare'; no ':'.
     * @param  int  $delay  Seconds until the retry runs.
     */
    public function __construct(string $name, protected int $delay = 60)
    {
        // ':' ends the name, so 'kinsta' never matches 'kinsta_group' rows.
        $this->optionPrefix = "cachetags_deferred_{$name}:";
        $this->hook = "cachetags/deferred/{$name}";
    }

    /**
     * Add entries and make sure a retry is scheduled.
     *
     * @param  array<string, string[]|true>  $entries
     */
    public function push(array $entries): void
    {
        if ($entries === []) {
            return;
        }

        add_option($this->optionPrefix.wp_generate_uuid4(), static::merge([$entries]), '', false);

        if (! wp_next_scheduled($this->hook)) {
            wp_schedule_single_event(time() + $this->delay, $this->hook);
        }
    }

    /**
     * Remove and return everything queued, coalesced per key.
     *
     * @return array<string, string[]|true>
     */
    public function take(): array
    {
        $rows = $this->rows();
        foreach (array_keys($rows) as $name) {
            delete_option($name);
        }

        return static::merge($rows);
    }

    /**
     * Everything queued, coalesced per key, without removing it.
     *
     * @return array<string, string[]|true>
     */
    public function all(): array
    {
        return static::merge($this->rows());
    }

    /** Drop everything queued and the scheduled retry. */
    public function clear(): void
    {
        foreach (array_keys($this->rows()) as $name) {
            delete_option($name);
        }
        wp_clear_scheduled_hook($this->hook);
    }

    /**
     * Read straight from the table: one row per push.
     *
     * @return array<string, array<string, string[]|true>> option name => entries
     */
    protected function rows(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id",
            $wpdb->esc_like($this->optionPrefix).'%'
        ));

        $entries = [];
        foreach ($rows as $row) {
            $entries[$row->option_name] = (array) maybe_unserialize($row->option_value);
        }

        return $entries;
    }

    /**
     * @param  iterable<array<string, string[]|true>>  $batches
     * @return array<string, string[]|true>
     */
    public static function merge(iterable $batches): array
    {
        $merged = [];
        foreach ($batches as $batch) {
            foreach ($batch as $key => $items) {
                $queued = $merged[$key] ?? [];
                $merged[$key] = $items === true || $queued === true
                    ? true
                    : array_values(array_unique([...$queued, ...$items]));
            }
        }

        return $merged;
    }
}
