<?php

namespace Genero\Sage\CacheTags\Stores;

use Genero\Sage\CacheTags\Contracts\Store;

class DeferredClearStore implements Store
{
    const CRON_HOOK = 'cachetags_deferred_store_clear';

    const TRANSIENT_KEY = 'cachetags_pending_clear';

    public function __construct(
        protected Store $store,
        protected int $delay = 60,
    ) {}

    public function register(): void
    {
        add_action(self::CRON_HOOK, [$this, 'processPendingClear']);
    }

    public function save(array $tags, string $url): bool
    {
        return $this->store->save($tags, $url);
    }

    public function get(array $tags): array
    {
        return $this->store->get($tags);
    }

    public function clear(array $urls, array $tags): bool
    {
        $pending = get_transient(self::TRANSIENT_KEY) ?: ['urls' => [], 'tags' => []];
        $pending['urls'] = array_values(array_unique([...$pending['urls'], ...$urls]));
        $pending['tags'] = array_values(array_unique([...$pending['tags'], ...$tags]));
        set_transient(self::TRANSIENT_KEY, $pending, $this->delay * 5);

        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
        wp_schedule_single_event(time() + $this->delay, self::CRON_HOOK);

        return true;
    }

    public function flush(): bool
    {
        return $this->store->flush();
    }

    public function processPendingClear(): void
    {
        $pending = get_transient(self::TRANSIENT_KEY);
        if (! empty($pending['urls'])) {
            $this->store->clear($pending['urls'], $pending['tags'] ?? []);
        }
        delete_transient(self::TRANSIENT_KEY);
    }
}
