<?php

use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Invalidators\DebugCacheInvalidator;
use Genero\Sage\CacheTags\Stores\CacheTagStore;
use Genero\Sage\CacheTags\WpCli\ClearCommand;
use Genero\Sage\CacheTags\WpCli\DatabaseCommand;
use Genero\Sage\CacheTags\WpCli\FlushCommand;

/**
 * Real integration against WP-CLI: the commands extend the real WP_CLI_Command
 * and call the real WP_CLI:: statics, routed through a capturing logger.
 *
 * @covers \Genero\Sage\CacheTags\WpCli\FlushCommand
 * @covers \Genero\Sage\CacheTags\WpCli\ClearCommand
 * @covers \Genero\Sage\CacheTags\WpCli\DatabaseCommand
 */
class TestWpCliCommands extends WP_UnitTestCase
{
    private ?CacheTags $saved;

    private object $logger;

    public function set_up(): void
    {
        parent::set_up();
        $this->saved = $this->prop()->getValue();
        $this->prop()->setValue(null, null);
        CacheTags::make(new CacheTagStore, false, 'Cache-Tag', [new DebugCacheInvalidator]);

        $this->logger = new class
        {
            public array $messages = [];

            public function info($message)
            {
                $this->messages[] = "info:$message";
            }

            public function success($message)
            {
                $this->messages[] = "success:$message";
            }

            public function warning($message)
            {
                $this->messages[] = "warning:$message";
            }

            public function error($message)
            {
                $this->messages[] = "error:$message";
                throw new RuntimeException($message);
            }

            public function debug($message, $group = false) {}

            public function line($message = '') {}

            public function error_multi_line($message_lines) {}
        };
        WP_CLI::set_logger($this->logger);
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

        $this->assertContains('success:Flushed caches', $this->logger->messages);
    }

    public function test_clear_command_reports_success(): void
    {
        (new ClearCommand)(['post:1']);

        $this->assertContains('success:Cleared cache tags', $this->logger->messages);
    }

    public function test_database_command_creates_the_table(): void
    {
        (new DatabaseCommand)();

        $this->assertContains('success:Created table', $this->logger->messages);
    }
}
