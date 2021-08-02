<?php

namespace Genero\Sage\CacheTags\Actions;

use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Contracts\Action;
use Genero\Sage\CacheTags\Tags\GravityformTags;

class Gravityform implements Action
{
    protected CacheTags $cacheTags;

    public function __construct(CacheTags $cacheTags)
    {
        $this->cacheTags = $cacheTags;
    }

    public function bind(): void
    {
        \add_filter('gform_pre_render', [$this, 'addGravityformCacheTags']);
        \add_action('gform_after_save_form', [$this, 'onSaveForm'], 10, 2);
    }

    public function addGravityformCacheTags(array $form): array
    {
        $this->cacheTags->add([
            ...GravityformTags::forms($form['id']),
        ]);
        
        return $form;
    }

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
