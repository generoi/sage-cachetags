<?php

use Genero\Sage\CacheTags\NonceCron;

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

    public function test_unschedule_clears_the_event(): void
    {
        NonceCron::schedule();
        NonceCron::unschedule();

        $this->assertFalse(wp_next_scheduled(NonceCron::HOOK));
    }
}
