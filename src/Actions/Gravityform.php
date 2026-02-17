<?php

namespace Genero\Sage\CacheTags\Actions;

use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Contracts\Action;
use Genero\Sage\CacheTags\Tags\GravityformTags;

class Gravityform implements Action
{
    public function __construct(protected CacheTags $cacheTags) {}

    public function bind(): void
    {
        \add_filter('gform_pre_render', [$this, 'addGravityformCacheTags']);
        \add_action('gform_after_save_form', [$this, 'onSaveForm'], 10, 2);
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
        }

        return $form;
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
