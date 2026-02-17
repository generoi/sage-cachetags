<?php

namespace Genero\Sage\CacheTags;

/**
 * WP-Cron scheduler for purging nonce-tagged cache every 12 hours.
 * Use when forms with file uploads (which use nonces) are cached.
 */
class NonceCron
{
    public const HOOK = 'cachetags_purge_nonce';

    public static function register(): void
    {
        add_action(self::HOOK, [self::class, 'purge']);

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
        wp_schedule_event(time(), 'twicedaily', self::HOOK);
    }

    public static function unschedule(): void
    {
        wp_clear_scheduled_hook(self::HOOK);
    }

    /**
     * Cron callback: purge cache for pages tagged with 'nonce'.
     */
    public static function purge(): void
    {
        $cacheTags = CacheTags::getInstance();
        if ($cacheTags === null) {
            return;
        }
        $cacheTags->clear(['nonce']);
        $cacheTags->purgeQueued();
    }
}
