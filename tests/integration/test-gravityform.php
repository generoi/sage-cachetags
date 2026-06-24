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

    private function resetTags(): CacheTags
    {
        $cacheTags = CacheTags::getInstance();
        $ref = new ReflectionProperty($cacheTags, 'cacheTags');
        $ref->setAccessible(true);
        $ref->setValue($cacheTags, []);

        return $cacheTags;
    }

    public function test_bind_registers_the_gravity_forms_hooks(): void
    {
        $action = new Gravityform(CacheTags::getInstance());
        $action->bind();

        $this->assertNotFalse(has_filter('gform_pre_render', [$action, 'addGravityformCacheTags']));
        $this->assertNotFalse(has_action('gform_after_save_form', [$action, 'onSaveForm']));
        $this->assertNotFalse(has_filter('cachetags/cacheable', [$action, 'isCacheable']));
    }

    public function test_a_non_array_form_is_passed_through_untouched(): void
    {
        // gform_pre_render can receive false (e.g. form not found).
        $this->assertFalse((new Gravityform(CacheTags::getInstance()))->addGravityformCacheTags(false));
    }

    public function test_rendering_tags_the_form_and_a_fileupload_adds_the_nonce_tag(): void
    {
        $cacheTags = $this->resetTags();

        $this->render([
            ['type' => 'fileupload', 'allowsPrepopulate' => false],
        ]);

        $tags = $cacheTags->get();
        $this->assertContains('gform:1', $tags);
        $this->assertContains('nonce', $tags, 'file uploads use a per-session nonce');
    }

    public function test_on_save_clears_an_existing_form_but_not_a_brand_new_one(): void
    {
        $cacheTags = CacheTags::getInstance();
        $purge = new ReflectionProperty($cacheTags, 'purgeTags');
        $purge->setAccessible(true);
        $action = new Gravityform($cacheTags);

        $purge->setValue($cacheTags, []);
        $action->onSaveForm(['id' => 7], false);
        $this->assertContains('gform:7', $purge->getValue($cacheTags));

        $purge->setValue($cacheTags, []);
        $action->onSaveForm(['id' => 7], true);
        $this->assertNotContains('gform:7', $purge->getValue($cacheTags), 'a new form has nothing cached yet');
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
