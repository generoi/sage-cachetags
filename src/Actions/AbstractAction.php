<?php

namespace Genero\Sage\CacheTags\Actions;

use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Contracts\Action;

abstract class AbstractAction implements Action
{
    public function __construct(protected CacheTags $cacheTags) {}
}
