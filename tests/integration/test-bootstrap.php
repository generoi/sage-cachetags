<?php

use Genero\Sage\CacheTags\Bootstrap;
use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Contracts\Store;
use Genero\Sage\CacheTags\Invalidators\DebugCacheInvalidator;
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
}
