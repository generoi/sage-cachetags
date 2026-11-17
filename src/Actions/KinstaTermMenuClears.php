<?php

namespace Genero\Sage\CacheTags\Actions;

use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Contracts\Action;
use Kinsta\Cache_Purge;

/**
 * Remove kinsta-mu-plugins' whole-site cache clear on term and menu saves.
 *
 * kinsta-mu-plugins hooks Cache_Purge::purge_complete_caches() on edited_term,
 * delete_term and wp_update_nav_menu, which empties the page cache, the CDN and
 * the object cache. WordPress passes the term or menu id as its `$force`
 * argument, so the MyKinsta autopurge setting doesn't stop it, and Polylang
 * rewrites a term each time a translation is linked. The tag purges already
 * cover terms and menus, and the Kinsta invalidators post to Kinsta's endpoints
 * directly, so they keep working without these hooks.
 *
 * Opt-in (config `kinsta-remove-term-menu-clears`); Bootstrap binds it only
 * with a Kinsta invalidator. Kinsta's other full clears (site options, theme,
 * widgets, the admin bar) and its per-post purges stay.
 */
class KinstaTermMenuClears implements Action
{
    const HOOKS = ['edited_term', 'delete_term', 'wp_update_nav_menu'];

    /**
     * Hooks the Kinsta clear wasn't found on, or null before init has run.
     *
     * @var string[]|null
     */
    protected ?array $missing = null;

    protected bool $found = false;

    public function __construct(protected CacheTags $cacheTags) {}

    public function bind(): void
    {
        // Cache_Purge is built on init priority 5 (class-kmp.php).
        if (did_action('init')) {
            $this->remove();
        } else {
            add_action('init', [$this, 'remove'], 20);
        }
    }

    public function remove(): void
    {
        $this->missing = [];
        $purge = $GLOBALS['kinsta_muplugin']->kinsta_cache_purge ?? null;
        $this->found = $purge instanceof Cache_Purge;

        if (! $this->found) {
            // Not on Kinsta (a local install) is fine; kinsta-mu-plugins loaded
            // without the object means it changed and the clears may be back.
            if ($this->kinstaLoaded()) {
                $this->missing = self::HOOKS;
            }

            return;
        }

        foreach (self::HOOKS as $hook) {
            if (! remove_action($hook, [$purge, 'purge_complete_caches'])) {
                $this->missing[] = $hook;
            }
        }
    }

    /**
     * Lines for `cachetags status`: [ok, message].
     *
     * @return array<int, array{bool, string}>
     */
    public function status(): array
    {
        if ($this->missing === null) {
            return [];
        }

        if (! $this->found) {
            return $this->missing
                ? [[false, 'Kinsta full clears: kinsta-mu-plugins is loaded but $kinsta_muplugin->kinsta_cache_purge is no Kinsta\Cache_Purge, so nothing was removed. Its full clears on term and menu saves may be back.']]
                : [[true, 'Kinsta full clears: kinsta-mu-plugins isn\'t loaded, nothing to remove.']];
        }

        $lines = [];
        if ($removed = array_diff(self::HOOKS, $this->missing)) {
            $lines[] = [true, 'Kinsta full clears removed from: '.implode(', ', $removed).'.'];
        }
        foreach ($this->missing as $hook) {
            $lines[] = [false, "Kinsta full clears: Cache_Purge::purge_complete_caches wasn't hooked on {$hook}, so nothing was removed there. Kinsta may have moved it; its full clears on term and menu saves may be back."];
        }

        return $lines;
    }

    protected function kinstaLoaded(): bool
    {
        return isset($GLOBALS['kinsta_muplugin']) || defined('KINSTAMU_VERSION');
    }
}
