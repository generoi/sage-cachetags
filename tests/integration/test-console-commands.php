<?php

use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Console\ClearCommand;
use Genero\Sage\CacheTags\Console\DatabaseCommand;
use Genero\Sage\CacheTags\Console\FlushCommand;
use Genero\Sage\CacheTags\Invalidators\DebugCacheInvalidator;
use Genero\Sage\CacheTags\Stores\CacheTagStore;
use Illuminate\Console\Command;
use Illuminate\Container\Container;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Real integration against Acorn's (Illuminate) console — the commands run
 * through Command::run(), resolving CacheTags from a real container and writing
 * to a real output buffer.
 *
 * @covers \Genero\Sage\CacheTags\Console\FlushCommand
 * @covers \Genero\Sage\CacheTags\Console\ClearCommand
 * @covers \Genero\Sage\CacheTags\Console\DatabaseCommand
 */
class TestConsoleCommands extends WP_UnitTestCase
{
    private ?CacheTags $saved;

    private Container $container;

    public function set_up(): void
    {
        parent::set_up();
        $this->saved = $this->prop()->getValue();
        $this->prop()->setValue(null, null);

        $cacheTags = CacheTags::make(new CacheTagStore, false, 'Cache-Tag', [new DebugCacheInvalidator]);
        // Illuminate's Command::run() calls $laravel->runningUnitTests(); the plain
        // container doesn't have it, so extend it (Acorn's Application would in a
        // real boot).
        $this->container = new class extends Container
        {
            public function runningUnitTests(): bool
            {
                return true;
            }
        };
        $this->container->instance(CacheTags::class, $cacheTags);
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

    private function runCommand(Command $command, array $input = []): array
    {
        $command->setLaravel($this->container);
        $output = new BufferedOutput;
        $exitCode = $command->run(new ArrayInput($input), $output);

        return [$exitCode, $output->fetch()];
    }

    public function test_flush_command_flushes_and_reports(): void
    {
        [$exitCode, $output] = $this->runCommand(new FlushCommand);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Flushed caches', $output);
    }

    public function test_clear_command_parses_the_tags_argument_and_clears(): void
    {
        [$exitCode, $output] = $this->runCommand(new ClearCommand, ['tags' => 'post:1']);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Cleared cache tags', $output);
    }

    public function test_database_command_creates_the_table(): void
    {
        [$exitCode, $output] = $this->runCommand(new DatabaseCommand);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Created table', $output);
    }
}
