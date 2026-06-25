<?php

namespace Genero\Sage\CacheTags;

/**
 * WP-Cron scheduler for store garbage collection — deletes cache_tags rows not
 * re-rendered within the configured age once a day. Owned by the PruneStore
 * action; the age travels in from config so a change takes effect next request.
 */
class PruneCron
{
    public const HOOK = 'cachetags_prune_store';

    public static function register(string $age): void
    {
        add_action(self::HOOK, function () use ($age) {
            self::prune($age);
        });

        if (did_action('init')) {
            self::schedule();
        } else {
            add_action('init', [self::class, 'schedule']);
        }
    }

    public static function schedule(): void
    {
        if (wp_next_scheduled(self::HOOK)) {
            return;
        }
        wp_schedule_event(time(), 'daily', self::HOOK);
    }

    public static function unschedule(): void
    {
        wp_clear_scheduled_hook(self::HOOK);
    }

    /**
     * Cron callback: prune store entries last seen before `age` ago.
     */
    public static function prune(string $age): void
    {
        $cacheTags = CacheTags::getInstance();
        $cutoff = Util::cutoffFromAge($age);

        if ($cacheTags === null || $cutoff === null) {
            return;
        }

        $cacheTags->prune($cutoff);
    }
}
