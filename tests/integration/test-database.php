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

    public function test_table_has_surrogate_primary_key(): void
    {
        global $wpdb;

        $primary = $wpdb->get_results("SHOW INDEX FROM {$wpdb->prefix}cache_tags WHERE Key_name = 'PRIMARY'");
        $this->assertSame('id', $primary[0]->Column_name ?? null, 'id is the clustered primary key');

        $unique = $wpdb->get_results("SHOW INDEX FROM {$wpdb->prefix}cache_tags WHERE Key_name = 'tag_url'");
        $this->assertNotEmpty($unique, '(tag, url) is kept unique');
    }

    // createTable provisions a missing table and is a safe no-op on an existing
    // one. (rebuildTable's drop+recreate is exercised by the bootstrap, which
    // builds the table the surrogate-key assertion above checks — real DROPs
    // inside a test break the harness's transaction isolation.)
    public function test_upgrade_is_idempotent_on_an_existing_table(): void
    {
        global $wpdb;
        $table = "{$wpdb->prefix}cache_tags";

        $this->migrator()->upgradeTable();

        $this->assertSame($table, $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)), 'existing table left in place');
    }

    /**
     * @group multisite
     */
    public function test_creates_the_table_for_a_new_multisite_subsite(): void
    {
        if (! is_multisite()) {
            $this->markTestSkipped('Requires multisite (run via the multisite CI job).');
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
