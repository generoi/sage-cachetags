<?php

namespace Genero\Sage\CacheTags\Actions;

use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Contracts\Action;
use Genero\Sage\CacheTags\NonceCron;
use Genero\Sage\CacheTags\Tags\GravityformTags;

class Gravityform implements Action
{
    /**
     * Field parameter names that prepopulate dynamically from the query string,
     * collected as forms render.
     *
     * @var string[]
     */
    protected array $prepopulateParams = [];

    public function __construct(protected CacheTags $cacheTags) {}

    public function bind(): void
    {
        \add_filter('gform_pre_render', [$this, 'addGravityformCacheTags']);
        \add_action('gform_after_save_form', [$this, 'onSaveForm'], 10, 2);
        \add_filter('cachetags/cacheable', [$this, 'isCacheable']);

        // File-upload forms tag their page 'nonce' (see addGravityformCacheTags);
        // schedule the cron that purges those pages before the nonce expires.
        NonceCron::register();
    }

    /**
     * @param  array<string, mixed>|bool  $form
     * @return array<string, mixed>|bool
     */
    public function addGravityformCacheTags(array|bool $form): array|bool
    {
        if (! is_array($form)) {
            return $form;
        }

        $this->cacheTags->add([
            ...GravityformTags::forms($form['id']),
        ]);

        foreach ($form['fields'] as $field) {
            // 2.9.24+ uses nonce for file uploads
            if ($field['type'] === 'fileupload') {
                $this->cacheTags->add(['nonce']);
            }

            $this->collectPrepopulateParams($field);
        }

        return $form;
    }

    /**
     * A form prepopulated from the query string renders per-visitor content
     * (often PII like email/name, or unbounded campaign values). Rather than
     * key the cache on — and store — those values, the page is non-cacheable
     * whenever a prepopulate parameter is present in the request.
     */
    public function isCacheable(bool $cacheable): bool
    {
        if (! $cacheable || empty($this->prepopulateParams)) {
            return $cacheable;
        }

        return empty(array_intersect_key($_GET, array_flip($this->prepopulateParams)));
    }

    /**
     * @param  array<string, mixed>  $field  A GF_Field (ArrayAccess)
     */
    protected function collectPrepopulateParams($field): void
    {
        if (empty($field['allowsPrepopulate'])) {
            return;
        }

        if (! empty($field['inputName'])) {
            $this->prepopulateParams[] = $field['inputName'];
        }

        // Multi-input fields (name, address, …) prepopulate per sub-input.
        foreach ((array) ($field['inputs'] ?? []) as $input) {
            if (! empty($input['name'])) {
                $this->prepopulateParams[] = $input['name'];
            }
        }
    }

    /**
     * @param  array<string, mixed>  $form
     */
    public function onSaveForm(array $form, bool $isNew): void
    {
        if ($isNew) {
            return;
        }

        $this->cacheTags->clear([
            ...GravityformTags::forms($form['id']),
        ]);
    }
}
