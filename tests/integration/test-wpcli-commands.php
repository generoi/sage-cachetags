<?php

use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Invalidators\DebugCacheInvalidator;
use Genero\Sage\CacheTags\Stores\CacheTagStore;
use Genero\Sage\CacheTags\WpCli\ClearCommand;
use Genero\Sage\CacheTags\WpCli\DatabaseCommand;
use Genero\Sage\CacheTags\WpCli\FlushCommand;

// The commands extend WP_CLI_Command and call WP_CLI:: statics, neither of which
// is loaded in the phpunit context. Stub the class (but NOT the WP_CLI constant,
// so nothing else thinks it's running under WP-CLI). error() throws so the
// failure path is observable.
if (! class_exists('WP_CLI_Command')) {
    class WP_CLI_Command {}
}
if (! class_exists('WP_CLI')) {
    class WP_CLI
    {
        public static function success($message)
        {
            $GLOBALS['__wpcli'][] = "success:$message";
        }

        public static function error($message)
        {
            $GLOBALS['__wpcli'][] = "error:$message";
            throw new RuntimeException($message);
        }

        public static function log($message)
        {
            $GLOBALS['__wpcli'][] = "log:$message";
        }

        public static function add_command($name, $command) {}
    }
}

/**
 * @covers \Genero\Sage\CacheTags\WpCli\FlushCommand
 * @covers \Genero\Sage\CacheTags\WpCli\ClearCommand
 * @covers \Genero\Sage\CacheTags\WpCli\DatabaseCommand
 */
class TestWpCliCommands extends WP_UnitTestCase
{
    private ?CacheTags $saved;

    public function set_up(): void
    {
        parent::set_up();
        $GLOBALS['__wpcli'] = [];
        $this->saved = $this->prop()->getValue();
        $this->prop()->setValue(null, null);
        CacheTags::make(new CacheTagStore, false, 'Cache-Tag', [new DebugCacheInvalidator]);
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

    public function test_flush_command_reports_success(): void
    {
        (new FlushCommand)();

        $this->assertSame(['success:Flushed caches'], $GLOBALS['__wpcli']);
    }

    public function test_clear_command_reports_success(): void
    {
        (new ClearCommand)(['post:1']);

        $this->assertSame(['success:Cleared cache tags'], $GLOBALS['__wpcli']);
    }

    public function test_database_command_creates_the_table(): void
    {
        (new DatabaseCommand)();

        $this->assertSame(['success:Created table'], $GLOBALS['__wpcli']);
    }
}
