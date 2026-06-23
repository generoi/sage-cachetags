<?php

use Genero\Sage\CacheTags\Actions\Gravityform;
use Genero\Sage\CacheTags\CacheTags;

/**
 * @covers \Genero\Sage\CacheTags\Actions\Gravityform
 */
class TestGravityform extends WP_UnitTestCase
{
    private array $get;

    public function set_up(): void
    {
        parent::set_up();
        $this->get = $_GET;
    }

    public function tear_down(): void
    {
        $_GET = $this->get;
        parent::tear_down();
    }

    private function render(array $fields): Gravityform
    {
        $action = new Gravityform(CacheTags::getInstance());
        $action->addGravityformCacheTags(['id' => 1, 'fields' => $fields]);

        return $action;
    }

    public function test_prepopulated_form_request_is_non_cacheable(): void
    {
        $action = $this->render([
            ['type' => 'text', 'allowsPrepopulate' => true, 'inputName' => 'ref'],
        ]);

        // Blank form (no matching query param) stays cacheable.
        $this->assertTrue($action->isCacheable(true));

        // Once the prepopulate param is present, the page is per-visitor.
        $_GET['ref'] = 'campaign';
        $this->assertFalse($action->isCacheable(true));
    }

    public function test_form_without_prepopulate_does_not_affect_cacheability(): void
    {
        $action = $this->render([
            ['type' => 'text', 'allowsPrepopulate' => false, 'inputName' => 'ref'],
        ]);

        $_GET['ref'] = 'campaign';
        $this->assertTrue($action->isCacheable(true));
    }

    public function test_multi_input_field_prepopulate_params_are_collected(): void
    {
        $action = $this->render([
            ['type' => 'name', 'allowsPrepopulate' => true, 'inputs' => [
                ['id' => '1.3', 'name' => 'first_name'],
                ['id' => '1.6', 'name' => 'last_name'],
            ]],
        ]);

        $_GET['last_name'] = 'Doe';
        $this->assertFalse($action->isCacheable(true));
    }
}
