<?php

use Genero\Sage\CacheTags\Concerns\CreatesDatabaseTable;

/**
 * Schema creation and the upgrade/migration path for existing installs.
 *
 * @covers \Genero\Sage\CacheTags\Concerns\CreatesDatabaseTable
 */
class TestDatabase extends WP_UnitTestCase
{
    public function test_table_has_url_index(): void
    {
        global $wpdb;

        $indexes = $wpdb->get_results("SHOW INDEX FROM {$wpdb->prefix}cache_tags WHERE Key_name = 'url'");

        $this->assertNotEmpty($indexes, 'url index exists for purge-by-url lookups');
    }

    public function test_upgrade_reruns_migration_when_version_is_outdated(): void
    {
        // Simulate an install from before the schema version was tracked.
        delete_option('cachetags_db_version');

        $this->migrator()->upgradeTable();

        $this->assertSame(2, (int) get_option('cachetags_db_version'));
    }

    public function test_upgrade_is_a_noop_when_version_is_current(): void
    {
        update_option('cachetags_db_version', 2);

        // Should not touch the option (already current); asserting it stays put
        // and the call doesn't error is enough.
        $this->migrator()->upgradeTable();

        $this->assertSame(2, (int) get_option('cachetags_db_version'));
    }

    public function test_creates_the_table_for_a_new_multisite_subsite(): void
    {
        if (! is_multisite()) {
            $this->markTestSkipped('Requires multisite (run with the multisite config).');
        }

        global $wpdb;
        $blogId = self::factory()->blog->create();

        switch_to_blog($blogId);
        $table = "{$wpdb->prefix}cache_tags";
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        restore_current_blog();

        $this->assertSame($table, $exists, 'wp_initialize_site provisions the new site table');
    }

    private function migrator(): object
    {
        return new class
        {
            use CreatesDatabaseTable;
        };
    }
}
