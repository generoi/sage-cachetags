<?php

use Genero\Sage\CacheTags\Bootstrap;
use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Console\ClearCommand;
use Genero\Sage\CacheTags\Console\DatabaseCommand;
use Genero\Sage\CacheTags\Console\FlushCommand;
use Genero\Sage\CacheTags\Console\StatusCommand;
use Genero\Sage\CacheTags\Invalidators\CloudflareCacheInvalidator;
use Genero\Sage\CacheTags\Invalidators\DebugCacheInvalidator;
use Genero\Sage\CacheTags\Invalidators\KinstaGroupCacheInvalidator;
use Genero\Sage\CacheTags\Stores\CacheTagStore;
use Genero\Sage\CacheTags\Stores\WordpressDbStore;
use Illuminate\Console\Command;
use Illuminate\Container\Container;
use Kinsta\Cache_Purge;
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
 * @covers \Genero\Sage\CacheTags\Console\StatusCommand
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

    // Under `wp acorn` WP-CLI takes --url as its global flag, so one URL is a
    // positional argument; --url still works where it reaches the command.
    public function test_status_command_lists_the_tags_of_a_url(): void
    {
        $this->prop()->setValue(null, null);
        $store = new WordpressDbStore;
        $this->container->instance(CacheTags::class, CacheTags::make($store, false, 'Cache-Tag', []));
        $store->save(['post:32', 'archive:post'], 'https://example.com/status-page/');

        foreach ([['url' => 'https://example.com/status-page/'], ['--url' => 'https://example.com/status-page/']] as $input) {
            [$exitCode, $output] = $this->runCommand(new StatusCommand, $input);

            $this->assertSame(Command::SUCCESS, $exitCode);
            $this->assertStringContainsString('post:32', $output);
            $this->assertStringContainsString('archive:post', $output);
            $this->assertStringNotContainsString('Rows:', $output, 'the URL\'s tags, not store-wide stats');
        }

        [, $output] = $this->runCommand(new StatusCommand, ['url' => 'https://example.com/not-stored/']);
        $this->assertStringContainsString('URL not in the store.', $output);
    }

    public function test_status_command_warns_about_a_kinsta_hook_it_did_not_find(): void
    {
        require_once dirname(__DIR__).'/fixtures/kinsta-cache-purge.php';
        $this->prop()->setValue(null, null);
        $GLOBALS['kinsta_muplugin'] = (object) ['kinsta_cache_purge' => new Cache_Purge(['edited_term', 'delete_term'])];

        try {
            $this->container->instance(CacheTags::class, (new Bootstrap)
                ->store(WordpressDbStore::class)->invalidators([KinstaGroupCacheInvalidator::class])
                ->actions([])->autoDetectActions(false)->baseTag(null)->pruneOlderThan(null)
                ->kinstaRemoveTermMenuClears()->bootstrap());

            [$exitCode, $output] = $this->runCommand(new StatusCommand);
        } finally {
            unset($GLOBALS['kinsta_muplugin']);
        }

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Kinsta full clears removed from: edited_term, delete_term.', $output);
        $this->assertStringContainsString("wasn't hooked on wp_update_nav_menu", $output);
    }

    public function test_status_command_reports_deferred_purges_and_an_overdue_retry(): void
    {
        $this->prop()->setValue(null, null);
        $this->container->instance(CacheTags::class, CacheTags::make(new WordpressDbStore, false, 'Cache-Tag', [new CloudflareCacheInvalidator]));
        $queue = CloudflareCacheInvalidator::deferred();
        $queue->push(['zone-a' => ['post:1', 'post:2']]);

        try {
            [, $output] = $this->runCommand(new StatusCommand);
            $this->assertStringContainsString('CloudflareCacheInvalidator: 2 deferred purge(s), retry', $output);
            $this->assertStringContainsString('from now', $output);

            wp_clear_scheduled_hook($queue->hook);
            wp_schedule_single_event(time() - HOUR_IN_SECONDS, $queue->hook);
            [, $output] = $this->runCommand(new StatusCommand);
            $this->assertStringContainsString('overdue', $output, 'cron is not running the retry');
        } finally {
            $queue->clear();
        }
    }
}
