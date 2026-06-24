<?php

// The Console commands extend Roots\Acorn\Console\Commands\Command, which is only
// present when the suggested roots/acorn package is installed (it isn't in the
// test env). Stub the base with just what the commands use, so they're loadable.

namespace Roots\Acorn\Console\Commands {
    if (! class_exists(Command::class, false)) {
        class Command
        {
            const SUCCESS = 0;

            const FAILURE = 1;

            public $app;

            public array $arguments = [];

            public function line($message)
            {
                $GLOBALS['__console'][] = "line:$message";
            }

            public function error($message)
            {
                $GLOBALS['__console'][] = "error:$message";
            }

            public function argument($key)
            {
                return $this->arguments[$key] ?? null;
            }
        }
    }
}

namespace {

    use Genero\Sage\CacheTags\CacheTags;
    use Genero\Sage\CacheTags\Console\ClearCommand;
    use Genero\Sage\CacheTags\Console\DatabaseCommand;
    use Genero\Sage\CacheTags\Console\FlushCommand;
    use Genero\Sage\CacheTags\Invalidators\DebugCacheInvalidator;
    use Genero\Sage\CacheTags\Stores\CacheTagStore;

    /**
     * @covers \Genero\Sage\CacheTags\Console\FlushCommand
     * @covers \Genero\Sage\CacheTags\Console\ClearCommand
     * @covers \Genero\Sage\CacheTags\Console\DatabaseCommand
     */
    class TestConsoleCommands extends WP_UnitTestCase
    {
        private ?CacheTags $saved;

        private CacheTags $cacheTags;

        public function set_up(): void
        {
            parent::set_up();
            $GLOBALS['__console'] = [];
            $this->saved = $this->prop()->getValue();
            $this->prop()->setValue(null, null);
            $this->cacheTags = CacheTags::make(new CacheTagStore, false, 'Cache-Tag', [new DebugCacheInvalidator]);
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

        private function container(): object
        {
            return new class($this->cacheTags)
            {
                public function __construct(private $cacheTags) {}

                public function make($abstract)
                {
                    return $this->cacheTags;
                }
            };
        }

        public function test_flush_command_resolves_from_the_container_and_succeeds(): void
        {
            $command = new FlushCommand;
            $command->app = $this->container();

            $command->handle();

            $this->assertSame(['line:Flushed caches'], $GLOBALS['__console']);
        }

        public function test_clear_command_clears_the_given_tags(): void
        {
            $command = new ClearCommand;
            $command->app = $this->container();
            $command->arguments = ['tags' => ['post:1']];

            $this->assertSame(ClearCommand::SUCCESS, $command->handle());
            $this->assertSame(['line:Cleared cache tags'], $GLOBALS['__console']);
        }

        public function test_database_command_creates_the_table(): void
        {
            (new DatabaseCommand)->handle();

            $this->assertSame(['line:Created table'], $GLOBALS['__console']);
        }
    }
}
