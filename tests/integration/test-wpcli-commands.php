<?php

use Genero\Sage\CacheTags\Bootstrap;
use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Contracts\SoftInvalidator;
use Genero\Sage\CacheTags\Invalidators\DebugCacheInvalidator;
use Genero\Sage\CacheTags\Invalidators\KinstaGroupCacheInvalidator;
use Genero\Sage\CacheTags\Stores\CacheTagStore;
use Genero\Sage\CacheTags\Stores\WordpressDbStore;
use Genero\Sage\CacheTags\WpCli\ClearCommand;
use Genero\Sage\CacheTags\WpCli\DatabaseCommand;
use Genero\Sage\CacheTags\WpCli\FlushCommand;
use Genero\Sage\CacheTags\WpCli\StatusCommand;
use Kinsta\Cache_Purge;

/**
 * Real integration against WP-CLI: the commands extend the real WP_CLI_Command
 * and call the real WP_CLI:: statics, routed through a capturing logger.
 *
 * @covers \Genero\Sage\CacheTags\WpCli\FlushCommand
 * @covers \Genero\Sage\CacheTags\WpCli\ClearCommand
 * @covers \Genero\Sage\CacheTags\WpCli\DatabaseCommand
 * @covers \Genero\Sage\CacheTags\WpCli\StatusCommand
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

    public function test_flush_command_hard_flushes_soft_invalidators(): void
    {
        $soft = new class implements SoftInvalidator
        {
            public array $calls = [];

            public function clear(array $urls, array $tags): bool
            {
                return true;
            }

            public function flush(): bool
            {
                $this->calls[] = 'flush';

                return true;
            }

            public function hardFlush(): bool
            {
                $this->calls[] = 'hardFlush';

                return true;
            }
        };
        $this->prop()->setValue(null, null);
        CacheTags::make(new CacheTagStore, false, 'Cache-Tag', [$soft, new DebugCacheInvalidator]);

        (new FlushCommand)();
        (new FlushCommand)([], ['hard' => true]);

        $this->assertSame(['flush', 'hardFlush'], $soft->calls);
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

    // WP-CLI takes --url as its global flag, so one URL is a positional arg.
    public function test_status_command_lists_the_tags_of_a_url_given_as_an_argument(): void
    {
        $this->prop()->setValue(null, null);
        $store = new WordpressDbStore;
        CacheTags::make($store, false, 'Cache-Tag', []);
        $store->save(['post:31', 'archive:post'], 'https://example.com/status-page/');

        (new StatusCommand)(['https://example.com/status-page/'], []);
        (new StatusCommand)(['https://example.com/not-stored/'], []);

        $listed = array_values(array_filter($this->logger->messages, fn ($message) => str_starts_with($message, 'info:')));
        $this->assertCount(1, $listed, 'the URL\'s tags, not store-wide stats');
        $this->assertEqualsCanonicalizing(['post:31', 'archive:post'], explode(PHP_EOL, substr($listed[0], strlen('info:'))));
        $this->assertContains('warning:URL not in the store.', $this->logger->messages);
    }

    public function test_status_command_warns_about_a_kinsta_hook_it_did_not_find(): void
    {
        require_once dirname(__DIR__).'/fixtures/kinsta-cache-purge.php';
        $this->prop()->setValue(null, null);
        $GLOBALS['kinsta_muplugin'] = (object) ['kinsta_cache_purge' => new Cache_Purge(['edited_term', 'delete_term'])];

        try {
            (new Bootstrap)->store(WordpressDbStore::class)->invalidators([KinstaGroupCacheInvalidator::class])
                ->actions([])->autoDetectActions(false)->baseTag(null)->pruneOlderThan(null)
                ->kinstaRemoveTermMenuClears()->bootstrap();

            (new StatusCommand)([], []);
        } finally {
            unset($GLOBALS['kinsta_muplugin']);
        }

        $this->assertContains('info:Kinsta full clears removed from: edited_term, delete_term.', $this->logger->messages);
        $warnings = array_filter($this->logger->messages, fn ($message) => str_starts_with($message, 'warning:') && str_contains($message, 'wp_update_nav_menu'));
        $this->assertCount(1, $warnings);
    }
}
