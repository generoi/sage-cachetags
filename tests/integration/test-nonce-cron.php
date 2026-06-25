<?php

use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\NonceCron;
use Genero\Sage\CacheTags\Tag;

/**
 * @covers \Genero\Sage\CacheTags\NonceCron
 */
class TestNonceCron extends WP_UnitTestCase
{
    public function tear_down(): void
    {
        NonceCron::unschedule();
        parent::tear_down();
    }

    public function test_schedule_registers_a_recurring_event_once(): void
    {
        NonceCron::schedule();
        $first = wp_next_scheduled(NonceCron::HOOK);

        $this->assertNotFalse($first);
        $this->assertSame('twicedaily', wp_get_schedule(NonceCron::HOOK));

        NonceCron::schedule();
        $this->assertSame($first, wp_next_scheduled(NonceCron::HOOK), 'does not double-schedule');
    }

    public function test_purge_queues_the_nonce_tag_and_runs_the_queue(): void
    {
        $cacheTags = CacheTags::getInstance();
        $prop = new ReflectionProperty($cacheTags, 'purgeTags');
        $prop->setAccessible(true);
        $prop->setValue($cacheTags, []);

        NonceCron::purge();

        $this->assertContains('nonce', Tag::toStrings($prop->getValue($cacheTags)));
    }

    public function test_unschedule_clears_the_event(): void
    {
        NonceCron::schedule();
        NonceCron::unschedule();

        $this->assertFalse(wp_next_scheduled(NonceCron::HOOK));
    }
}
