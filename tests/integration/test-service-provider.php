<?php

use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\CacheTagsServiceProvider;
use Genero\Sage\CacheTags\Invalidators\DebugCacheInvalidator;
use Genero\Sage\CacheTags\Stores\TransientStore;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;

/**
 * The Acorn service provider wires CacheTags from config, mirroring the
 * standalone Bootstrap.
 *
 * @covers \Genero\Sage\CacheTags\CacheTagsServiceProvider
 */
class TestServiceProvider extends WP_UnitTestCase
{
    private ?CacheTags $saved;

    public function set_up(): void
    {
        parent::set_up();
        if (! class_exists(Container::class)) {
            $this->markTestSkipped('Acorn/Illuminate is not installed in this environment.');
        }
        $this->saved = $this->prop()->getValue();
        $this->prop()->setValue(null, null);
    }

    public function tear_down(): void
    {
        $this->prop()->setValue(null, $this->saved);
        parent::tear_down();
    }

    private function prop(): ReflectionProperty
    {
        $prop = new ReflectionProperty(CacheTags::class, 'instance');
        $prop->setAccessible(true);

        return $prop;
    }

    public function test_register_builds_cachetags_from_config(): void
    {
        $app = new Container;
        $app->instance('config', new Repository(['cachetags' => [
            'store' => TransientStore::class,
            'invalidator' => [DebugCacheInvalidator::class],
            'action' => [],
            'disable' => true,
        ]]));

        (new CacheTagsServiceProvider($app))->register();

        $cacheTags = $app->make(CacheTags::class);
        $this->assertInstanceOf(CacheTags::class, $cacheTags);
        $this->assertInstanceOf(TransientStore::class, $cacheTags->store);
        $this->assertCount(1, $cacheTags->invalidators);
    }
}
