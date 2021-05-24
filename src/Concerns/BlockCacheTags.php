<?php

namespace Genero\Sage\CacheTags\Concerns;

use Genero\Sage\CacheTags\CacheTags;

use function Roots\app;

trait BlockCacheTags
{
    public function cacheTags(): array
    {
        return [];
    }

    public function view($view, $with = [])
    {
        $result = parent::view($view, $with);

        app(CacheTags::class)->add($this->cacheTags());

        return $result;
    }
}
