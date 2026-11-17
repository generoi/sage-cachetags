<?php

use Genero\Sage\CacheTags\Actions\KinstaTermMenuClears;
use Genero\Sage\CacheTags\Bootstrap;
use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Invalidators\DebugCacheInvalidator;
use Genero\Sage\CacheTags\Invalidators\KinstaGroupCacheInvalidator;
use Genero\Sage\CacheTags\Stores\TransientStore;
use Kinsta\Cache_Purge;

require_once dirname(__DIR__).'/fixtures/kinsta-cache-purge.php';

/**
 * kinsta-mu-plugins is faked by a Cache_Purge that hooks its whole-site clear
 * the way the real one does, held where the real one is
 * ($kinsta_muplugin->kinsta_cache_purge).
 *
 * @covers \Genero\Sage\CacheTags\Actions\KinstaTermMenuClears
 */
class TestKinstaTermMenuClears extends WP_UnitTestCase
{
    private ?CacheTags $saved;

    public function set_up(): void
    {
        parent::set_up();
        $this->saved = $this->instanceProp()->getValue();
        $this->instanceProp()->setValue(null, null);
    }

    public function tear_down(): void
    {
        unset($GLOBALS['kinsta_muplugin']);
        $this->instanceProp()->setValue(null, $this->saved);
        parent::tear_down();
    }

    private function instanceProp(): ReflectionProperty
    {
        $prop = new ReflectionProperty(CacheTags::class, 'instance');
        $prop->setAccessible(true);

        return $prop;
    }

    private function kinsta(?Cache_Purge $purge): void
    {
        $GLOBALS['kinsta_muplugin'] = (object) ['kinsta_cache_purge' => $purge];
    }

    private function bootstrap(bool $enable = true, string $invalidator = KinstaGroupCacheInvalidator::class): CacheTags
    {
        return (new Bootstrap)
            ->store(TransientStore::class)
            ->invalidators([$invalidator])
            ->actions([])
            ->autoDetectActions(false)
            ->baseTag(null)
            ->pruneOlderThan(null)
            ->kinstaRemoveTermMenuClears($enable)
            ->bootstrap();
    }

    public function test_term_and_menu_saves_no_longer_clear_the_whole_site(): void
    {
        $this->kinsta($purge = new Cache_Purge);
        $this->bootstrap();

        $term = self::factory()->term->create(['taxonomy' => 'category']);
        wp_update_term($term, 'category', ['name' => 'Renamed']);
        wp_delete_term($term, 'category');
        wp_update_nav_menu_object(wp_create_nav_menu('Main'), ['menu-name' => 'Renamed menu']);

        $this->assertSame(0, $purge->completeClears);
        foreach (KinstaTermMenuClears::HOOKS as $hook) {
            $this->assertFalse(has_action($hook, [$purge, 'purge_complete_caches']), $hook);
        }
    }

    public function test_the_tag_purges_on_those_hooks_stay(): void
    {
        $this->kinsta(new Cache_Purge);
        $other = fn () => null;
        add_action('edited_term', $other);
        $this->bootstrap();

        $this->assertNotFalse(has_action('edited_term', $other));
    }

    public function test_off_by_default(): void
    {
        $this->kinsta($purge = new Cache_Purge);
        $cacheTags = (new Bootstrap)
            ->store(TransientStore::class)
            ->invalidators([KinstaGroupCacheInvalidator::class])
            ->actions([])
            ->autoDetectActions(false)
            ->bootstrap();

        $this->assertFalse($cacheTags->hasAction(KinstaTermMenuClears::class));
        wp_update_term(self::factory()->term->create(['taxonomy' => 'category']), 'category', ['name' => 'Renamed']);
        $this->assertSame(1, $purge->completeClears, 'Kinsta clears the whole site');
    }

    // Without a Kinsta invalidator nothing purges by tag on Kinsta, so its
    // clears are all there is.
    public function test_needs_a_kinsta_invalidator(): void
    {
        $this->kinsta($purge = new Cache_Purge);
        $cacheTags = $this->bootstrap(true, DebugCacheInvalidator::class);

        $this->assertFalse($cacheTags->hasAction(KinstaTermMenuClears::class));
        $this->assertNotFalse(has_action('edited_term', [$purge, 'purge_complete_caches']));
    }

    // Acorn boots before init, and Cache_Purge is built on init priority 5.
    public function test_waits_for_init_when_bootstrapped_early(): void
    {
        global $wp_actions;
        $fired = $wp_actions['init'] ?? null;
        unset($wp_actions['init']);

        try {
            $cacheTags = $this->bootstrap();
            $action = $cacheTags->action(KinstaTermMenuClears::class);
            $this->assertSame(20, has_action('init', [$action, 'remove']));
            $this->assertSame([], $action->status(), 'nothing to report before init');

            $this->kinsta($purge = new Cache_Purge);
            $action->remove();

            $this->assertFalse(has_action('edited_term', [$purge, 'purge_complete_caches']));
        } finally {
            if ($fired !== null) {
                $wp_actions['init'] = $fired;
            }
        }
    }

    public function test_status_lists_the_removed_hooks(): void
    {
        $this->kinsta(new Cache_Purge);
        $status = $this->bootstrap()->action(KinstaTermMenuClears::class)->status();

        $this->assertSame([[true, 'Kinsta full clears removed from: edited_term, delete_term, wp_update_nav_menu.']], $status);
    }

    // The hooks sit in Kinsta's legacy class with a @todo to move them.
    public function test_status_warns_about_a_hook_kinsta_no_longer_uses(): void
    {
        $this->kinsta(new Cache_Purge(['edited_term', 'delete_term']));
        $status = $this->bootstrap()->action(KinstaTermMenuClears::class)->status();

        $this->assertTrue($status[0][0]);
        $this->assertStringContainsString('edited_term, delete_term.', $status[0][1]);
        $this->assertFalse($status[1][0]);
        $this->assertStringContainsString('wp_update_nav_menu', $status[1][1]);
        $this->assertStringContainsString('may be back', $status[1][1]);
        $this->assertCount(2, $status);
    }

    public function test_status_warns_when_kinsta_has_no_cache_purge(): void
    {
        $this->kinsta(null);
        $status = $this->bootstrap()->action(KinstaTermMenuClears::class)->status();

        $this->assertCount(1, $status);
        $this->assertFalse($status[0][0]);
        $this->assertStringContainsString('may be back', $status[0][1]);
    }

    // A local install without kinsta-mu-plugins has nothing to remove.
    public function test_status_without_kinsta_mu_plugins_is_no_warning(): void
    {
        $status = $this->bootstrap()->action(KinstaTermMenuClears::class)->status();

        $this->assertCount(1, $status);
        $this->assertTrue($status[0][0]);
    }
}
