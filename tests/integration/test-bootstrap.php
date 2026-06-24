<?php

use Genero\Sage\CacheTags\Bootstrap;
use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Contracts\Store;
use Genero\Sage\CacheTags\Invalidators\DebugCacheInvalidator;
use Genero\Sage\CacheTags\NonceCron;
use Genero\Sage\CacheTags\Stores\DeferredClearStore;
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

    public function test_wraps_the_store_in_a_deferred_clear_store_when_a_delay_is_set(): void
    {
        $cacheTags = (new Bootstrap)
            ->store(TransientStore::class)
            ->storeClearDelay(60)
            ->actions([])
            ->disable()
            ->bootstrap();

        $this->assertInstanceOf(DeferredClearStore::class, $cacheTags->store);
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

    public function test_schedules_the_nonce_cron_when_enabled(): void
    {
        (new Bootstrap)->store(TransientStore::class)->actions([])->nonceCron(true)->bootstrap();

        $this->assertNotFalse(wp_next_scheduled(NonceCron::HOOK));
        NonceCron::unschedule();
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
}
