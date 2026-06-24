<?php

use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Concerns\BlockCacheTags;
use Genero\Sage\CacheTags\Concerns\ComposerCacheTags;
use Illuminate\Container\Container;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Engines\CompilerEngine;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Engines\PhpEngine;
use Illuminate\View\Factory;
use Illuminate\View\FileViewFinder;
use Roots\Acorn\View\Composer;

// Roots\app() delegates to the global app() helper, which a real Acorn/Laravel
// boot provides. Define the standard resolver so the traits run as they would
// in production.
if (! function_exists('app')) {
    function app($abstract = null, array $parameters = [])
    {
        $container = Container::getInstance();

        return is_null($abstract) ? $container : $container->make($abstract, $parameters);
    }
}

/**
 * A Sage view composer using ComposerCacheTags: when its Blade view is composed,
 * its cache tags are registered.
 */
class CacheTagComposerFixture extends Composer
{
    use ComposerCacheTags;

    protected static $views = ['cachetag-composer'];

    public function cacheTags(ViewContract $view): array
    {
        return ['post:42', 'term:7'];
    }
}

/**
 * Stand-in for the AcfComposer Block base (Log1x\AcfComposer\Block isn't a
 * dependency); BlockCacheTags only relies on a parent view($view, $with).
 */
abstract class FakeAcfBlockBase
{
    public function view($view, $with = [])
    {
        return ['view' => $view, 'with' => $with];
    }
}

class CacheTagBlockFixture extends FakeAcfBlockBase
{
    use BlockCacheTags;

    public function cacheTags(): array
    {
        return ['post:99'];
    }
}

/**
 * The Acorn integration traits add cache tags through the real Illuminate view /
 * Blade pipeline (ComposerCacheTags) and the block render path (BlockCacheTags).
 *
 * @covers \Genero\Sage\CacheTags\Concerns\ComposerCacheTags
 * @covers \Genero\Sage\CacheTags\Concerns\BlockCacheTags
 */
class TestAcornTraits extends WP_UnitTestCase
{
    private $previousContainer;

    public function set_up(): void
    {
        parent::set_up();
        if (! class_exists(Factory::class) || ! class_exists(Composer::class)) {
            $this->markTestSkipped('Acorn / Illuminate view is not installed.');
        }
        $this->previousContainer = Container::getInstance();
    }

    public function tear_down(): void
    {
        Container::setInstance($this->previousContainer);
        parent::tear_down();
    }

    /**
     * Reset the shared CacheTags buffer and bind it so Roots\app(CacheTags::class)
     * (i.e. the global app() container) resolves to it.
     */
    private function bindCacheTags(): CacheTags
    {
        $cacheTags = CacheTags::getInstance();
        $ref = new ReflectionProperty($cacheTags, 'cacheTags');
        $ref->setAccessible(true);
        $ref->setValue($cacheTags, []);

        $container = new Container;
        $container->instance(CacheTags::class, $cacheTags);
        Container::setInstance($container);

        return $cacheTags;
    }

    private function viewFactory(Container $container): Factory
    {
        $files = new Filesystem;
        $compiled = get_temp_dir().'cachetag-blade-compiled';
        if (! is_dir($compiled)) {
            mkdir($compiled, 0777, true);
        }

        $compiler = new BladeCompiler($files, $compiled);
        $resolver = new EngineResolver;
        $resolver->register('blade', fn () => new CompilerEngine($compiler, $files));
        $resolver->register('php', fn () => new PhpEngine($files));

        $finder = new FileViewFinder($files, [dirname(__DIR__).'/fixtures/views']);
        $factory = new Factory($resolver, $finder, new Dispatcher($container));
        $factory->setContainer($container);

        return $factory;
    }

    public function test_composer_trait_tags_when_its_blade_view_renders(): void
    {
        $cacheTags = $this->bindCacheTags();
        $container = Container::getInstance();
        $factory = $this->viewFactory($container);
        $factory->composer('cachetag-composer', CacheTagComposerFixture::class);

        $html = $factory->make('cachetag-composer', ['heading' => 'Cached page'])->render();

        $this->assertStringContainsString('Cached page', $html, 'the Blade view actually rendered');
        $this->assertContains('post:42', $cacheTags->get());
        $this->assertContains('term:7', $cacheTags->get());
    }

    public function test_block_trait_tags_and_delegates_to_the_parent_renderer(): void
    {
        $cacheTags = $this->bindCacheTags();

        $result = (new CacheTagBlockFixture)->view('blocks.test', ['foo' => 'bar']);

        $this->assertSame(['view' => 'blocks.test', 'with' => ['foo' => 'bar']], $result, 'parent::view ran');
        $this->assertContains('post:99', $cacheTags->get());
    }
}
