<?php

namespace Kinsta;

/**
 * Stand-in for kinsta-mu-plugins' Cache_Purge (cache/class-cache-purge.php),
 * which hooks its whole-site clear on these hooks in its constructor.
 */
class Cache_Purge
{
    public int $completeClears = 0;

    /**
     * @param  string[]  $hooks
     */
    public function __construct(array $hooks = ['wp_update_nav_menu', 'edited_term', 'delete_term'])
    {
        foreach ($hooks as $hook) {
            add_action($hook, [$this, 'purge_complete_caches']);
        }
    }

    public function purge_complete_caches($force = false): void
    {
        $this->completeClears++;
    }
}
