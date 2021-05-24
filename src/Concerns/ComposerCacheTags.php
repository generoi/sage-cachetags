<?php

namespace Genero\Sage\CacheTags\Concerns;

use Genero\Sage\CacheTags\CacheTags;
use Illuminate\Contracts\View\View;

use function Roots\app;

trait ComposerCacheTags
{
    public function cacheTags(): array
    {
        return [];
    }

    public function compose(View $view)
    {
        parent::compose($view);

        app(CacheTags::class)->add($this->cacheTags());
    }
}
