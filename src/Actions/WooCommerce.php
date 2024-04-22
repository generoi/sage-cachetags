<?php

namespace Genero\Sage\CacheTags\Actions;

use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Contracts\Action;
use Genero\Sage\CacheTags\Tags\WooCommerceTags;

class WooCommerce implements Action
{
    protected CacheTags $cacheTags;

    public function __construct(CacheTags $cacheTags)
    {
        $this->cacheTags = $cacheTags;
    }

    public function bind(): void
    {
        \add_filter('template_redirect', [$this, 'addTemplateCacheTags']);
    }

    public function addTemplateCacheTags()
    {
        switch (true) {
            case function_exists('is_shop') && is_shop():
                $this->cacheTags->add([
                    ...WooCommerceTags::shop(),
                ]);
                break;
        }
    }
}
