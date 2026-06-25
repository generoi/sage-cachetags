<?php

use Genero\Sage\CacheTags\Actions\BaseTag;
use Genero\Sage\CacheTags\Actions\Core;
use Genero\Sage\CacheTags\Actions\Gravityform;
use Genero\Sage\CacheTags\Actions\Nonce;
use Genero\Sage\CacheTags\Actions\Polylang;
use Genero\Sage\CacheTags\Actions\PruneStore;
use Genero\Sage\CacheTags\Actions\WooCommerce;
use Genero\Sage\CacheTags\Bootstrap;
use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Contracts\Store;
use Genero\Sage\CacheTags\Invalidators\DebugCacheInvalidator;
use Genero\Sage\CacheTags\NonceCron;
use Genero\Sage\CacheTags\PruneCron;
use Genero\Sage\CacheTags\Stores\TransientStore;

/**
 * @covers \Genero\Sage\CacheTags\Bootstrap
 */
class TestBootstrap extends WP_UnitTestCase
{
    private ?CacheTags $saved;

    public function set_up(): void
    {
        parent::set_up();
        // Reset the singleton so bootstrap() builds a fresh instance, and keep
        // the real one to restore afterwards.
        $this->saved = $this->instanceProp()->getValue();
        $this->instanceProp()->setValue(null, null);
    }

    public function tear_down(): void
    {
        $this->instanceProp()->setValue(null, $this->saved);
        parent::tear_down();
    }

    private function instanceProp(): ReflectionProperty
    {
        $prop = new ReflectionProperty(CacheTags::class, 'instance');
        $prop->setAccessible(true);

        return $prop;
    }

    public function test_resolves_store_and_invalidators_from_class_names(): void
    {
        $cacheTags = (new Bootstrap)
            ->store(TransientStore::class)
            ->invalidators([DebugCacheInvalidator::class])
            ->actions([])
            ->disable()
            ->bootstrap();

        $this->assertInstanceOf(TransientStore::class, $cacheTags->store);
        $this->assertCount(1, $cacheTags->invalidators);
        $this->assertInstanceOf(DebugCacheInvalidator::class, $cacheTags->invalidators[0]);
    }

    public function test_accepts_already_instantiated_dependencies(): void
    {
        $store = new TransientStore;

        $cacheTags = (new Bootstrap)
            ->store($store)
            ->invalidators([new DebugCacheInvalidator])
            ->actions([])
            ->disable()
            ->bootstrap();

        $this->assertSame($store, $cacheTags->store);
    }

    public function test_rejects_a_dependency_not_implementing_its_contract(): void
    {
        $bootstrap = new Bootstrap;
        $resolve = new ReflectionMethod($bootstrap, 'resolve');
        $resolve->setAccessible(true);

        $this->expectException(InvalidArgumentException::class);
        $resolve->invoke($bootstrap, new stdClass, Store::class);
    }

    public function test_registers_the_save_and_purge_hooks_when_enabled(): void
    {
        $bootstrap = new Bootstrap;
        $bootstrap->store(TransientStore::class)->actions([])->bootstrap();

        $this->assertNotFalse(has_action('wp_footer', [$bootstrap, 'saveCacheTags']));
        $this->assertNotFalse(has_filter('rest_post_dispatch', [$bootstrap, 'saveCacheTagsRest']));
        $this->assertNotFalse(has_action('shutdown', [$bootstrap, 'purgeCacheTags']));
    }

    public function test_nonce_action_schedules_the_cron(): void
    {
        NonceCron::unschedule();
        $cacheTags = (new Bootstrap)->store(TransientStore::class)->actions([])->disable()->bootstrap();

        // The action owns the cron — binding it schedules the purge.
        (new Nonce($cacheTags))->bind();

        $this->assertNotFalse(wp_next_scheduled(NonceCron::HOOK));
        NonceCron::unschedule();
    }

    public function test_prune_store_action_is_bound_by_default_and_schedules_the_cron(): void
    {
        PruneCron::unschedule();

        // Default prune-older-than ('30d') binds PruneStore, which schedules GC.
        $cacheTags = (new Bootstrap)->store(TransientStore::class)->actions([])->autoDetectActions(false)->bootstrap();

        $this->assertTrue($cacheTags->hasAction(PruneStore::class), 'GC on by default');
        $this->assertNotFalse(wp_next_scheduled(PruneCron::HOOK));
        PruneCron::unschedule();
    }

    public function test_prune_can_be_disabled_and_unschedules_the_cron(): void
    {
        PruneCron::register('30d'); // pretend a schedule already exists
        $this->assertNotFalse(wp_next_scheduled(PruneCron::HOOK));

        $cacheTags = (new Bootstrap)
            ->store(TransientStore::class)->actions([])->autoDetectActions(false)
            ->pruneOlderThan(null)
            ->bootstrap();

        $this->assertFalse($cacheTags->hasAction(PruneStore::class), 'opted out');
        $this->assertFalse(wp_next_scheduled(PruneCron::HOOK), 'orphaned cron cleaned up');
    }

    public function test_fastly_allowlist_dictionary_is_exposed_via_filter_when_configured(): void
    {
        (new Bootstrap)->store(TransientStore::class)->actions([])->disable()
            ->fastlyAllowlistDictionary('shop_allowlist')->bootstrap();

        $this->assertSame('shop_allowlist', apply_filters('cachetags/fastly-allowlist-dictionary', null));
        remove_all_filters('cachetags/fastly-allowlist-dictionary');
    }

    public function test_save_cache_tags_stores_a_cacheable_front_end_url(): void
    {
        $store = new TransientStore;
        $bootstrap = new Bootstrap;
        $bootstrap->store($store)->actions([])->bootstrap();
        CacheTags::getInstance()->add(['post:4242']);

        $bootstrap->saveCacheTags();

        $this->assertNotEmpty($store->get(['post:4242']), 'the url was stored under its tag');
    }

    public function test_save_cache_tags_signals_do_not_cache_when_not_cacheable(): void
    {
        $bootstrap = new Bootstrap;
        $bootstrap->store(TransientStore::class)->actions([])->bootstrap();
        add_filter('cachetags/cacheable', '__return_false');

        $bootstrap->saveCacheTags();

        remove_filter('cachetags/cacheable', '__return_false');
        $this->assertTrue(defined('DONOTCACHEPAGE'), 'page caches get an actionable bypass signal');
    }

    public function test_rest_url_keeps_registered_and_response_params_and_drops_the_rest(): void
    {
        $bootstrap = new Bootstrap;
        $bootstrap->store(TransientStore::class)->actions([])->disable()->bootstrap();
        $restUrl = new ReflectionMethod($bootstrap, 'restUrl');
        $restUrl->setAccessible(true);

        $request = new WP_REST_Request('GET', '/wp/v2/posts');
        $request->set_query_params(['per_page' => '5', 'page' => '2', 'foo' => 'bar', '_fields' => 'id', '_wpnonce' => 'abc']);
        $request->set_attributes(['args' => ['page' => [], 'per_page' => []]]);

        $url = $restUrl->invoke($bootstrap, $request);

        $this->assertStringContainsString('page=2', $url);
        $this->assertStringContainsString('per_page=5', $url);
        $this->assertStringContainsString('_fields=id', $url, 'response-shaping params are kept');
        $this->assertStringNotContainsString('foo', $url, 'unregistered params are dropped');
        $this->assertStringNotContainsString('_wpnonce', $url, 'ignored params are dropped');
        // ksort: page sorts before per_page.
        $this->assertLessThan(strpos($url, 'per_page'), strpos($url, 'page=2'));
    }

    public function test_rest_url_without_params_has_no_query_string(): void
    {
        $bootstrap = new Bootstrap;
        $bootstrap->store(TransientStore::class)->actions([])->disable()->bootstrap();
        $restUrl = new ReflectionMethod($bootstrap, 'restUrl');
        $restUrl->setAccessible(true);

        $url = $restUrl->invoke($bootstrap, new WP_REST_Request('GET', '/wp/v2/posts'));

        $this->assertStringNotContainsString('?', $url);
    }

    // A query string that would overflow the url column (varchar 191) must fall
    // back to the bare route, not get silently truncated (which would never
    // match what the edge cached).
    public function test_rest_url_falls_back_to_the_route_when_over_the_length_limit(): void
    {
        $bootstrap = new Bootstrap;
        $bootstrap->store(TransientStore::class)->actions([])->disable()->bootstrap();
        $restUrl = new ReflectionMethod($bootstrap, 'restUrl');
        $restUrl->setAccessible(true);

        $request = new WP_REST_Request('GET', '/wp/v2/posts');
        $request->set_query_params(['search' => str_repeat('x', 250)]);
        $request->set_attributes(['args' => ['search' => []]]);

        $url = $restUrl->invoke($bootstrap, $request);

        $this->assertStringNotContainsString('search=', $url, 'overflowing query string dropped');
        $this->assertStringNotContainsString('?', $url, 'falls back to the bare route');
    }

    public function test_fluent_setters_propagate_to_the_instance(): void
    {
        $cacheTags = (new Bootstrap)
            ->debug(true)
            ->httpHeader('Surrogate-Key')
            ->actions([])
            ->store(TransientStore::class)
            ->disable()
            ->bootstrap();

        $this->assertTrue($cacheTags->debug);
        $this->assertSame('Surrogate-Key', $cacheTags->httpHeader);
    }

    public function test_base_tag_action_is_bound_by_default_and_disabled_with_null(): void
    {
        $withBase = (new Bootstrap)->store(TransientStore::class)->actions([])->autoDetectActions(false)->bootstrap();
        $this->assertTrue($withBase->hasAction(BaseTag::class), 'base tag bound by default');

        $this->instanceProp()->setValue(null, null);

        $without = (new Bootstrap)->store(TransientStore::class)->actions([])->autoDetectActions(false)->baseTag(null)->bootstrap();
        $this->assertFalse($without->hasAction(BaseTag::class), 'baseTag(null) binds nothing');
    }

    public function test_disabled_bootstrap_binds_nothing_and_leaves_no_nonce_cron(): void
    {
        NonceCron::unschedule();

        // Disabled must be fully inert — including not scheduling the Nonce cron,
        // whose deferred init hook would otherwise survive the synchronous cleanup.
        $cacheTags = (new Bootstrap)
            ->store(TransientStore::class)
            ->actions([Core::class, Nonce::class])
            ->disable()
            ->bootstrap();

        $this->assertFalse($cacheTags->hasAction(Nonce::class), 'no actions bound when disabled');
        $this->assertFalse(wp_next_scheduled(NonceCron::HOOK), 'and no cron scheduled');
    }

    public function test_base_tag_action_tags_every_page(): void
    {
        $cacheTags = (new Bootstrap)->store(TransientStore::class)->actions([])->disable()->bootstrap();

        $action = new BaseTag($cacheTags, 'page');
        $action->bind();
        $this->assertNotFalse(has_action('template_redirect', [$action, 'addBaseTag']));
        $this->assertNotFalse(has_action('rest_api_init', [$action, 'addBaseTag']));

        $action->addBaseTag();
        $this->assertContains('page', $cacheTags->get());
    }

    // WooCommerce + Polylang are loaded in the test env, so with detection on
    // they are auto-appended (the safety footgun fix).
    public function test_auto_enables_active_integration_plugins(): void
    {
        $bootstrap = (new Bootstrap)->autoDetectActions(true);
        $withDetected = new ReflectionMethod(Bootstrap::class, 'withDetectedActions');
        $withDetected->setAccessible(true);
        $result = $withDetected->invoke($bootstrap, [Core::class]);

        $this->assertContains(WooCommerce::class, $result);
        $this->assertContains(Polylang::class, $result);
        // Gravity Forms isn't loaded in the test env, so its detection key (also
        // wired) stays absent — never falsely appended.
        $this->assertNotContains(Gravityform::class, $result);
    }

    public function test_nonce_cron_is_unscheduled_when_the_action_is_removed(): void
    {
        // Pretend a stale schedule exists, then bootstrap without the Nonce action
        // (opt-out) — Bootstrap cleans up the orphaned cron.
        NonceCron::register();
        $this->assertNotFalse(wp_next_scheduled(NonceCron::HOOK));

        (new Bootstrap)->store(TransientStore::class)->actions([])->autoDetectActions(false)->bootstrap();

        $this->assertFalse(wp_next_scheduled(NonceCron::HOOK));
    }

    public function test_auto_detect_actions_can_be_disabled(): void
    {
        $bootstrap = (new Bootstrap)->autoDetectActions(false);
        $withDetected = new ReflectionMethod(Bootstrap::class, 'withDetectedActions');
        $withDetected->setAccessible(true);

        $this->assertSame([Core::class], $withDetected->invoke($bootstrap, [Core::class]));
    }

    public function test_a_detected_action_is_not_duplicated_when_already_listed(): void
    {
        $bootstrap = (new Bootstrap)->autoDetectActions(true);
        $withDetected = new ReflectionMethod(Bootstrap::class, 'withDetectedActions');
        $withDetected->setAccessible(true);
        $result = $withDetected->invoke($bootstrap, [WooCommerce::class]);

        $this->assertSame(1, count(array_filter($result, fn ($action) => $action === WooCommerce::class)));
    }
}
