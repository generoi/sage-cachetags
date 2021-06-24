<?php

namespace Genero\Sage\CacheTags;

use Genero\Sage\CacheTags\Contracts\Action;

class Actions
{
    /**
     * @var Action[] $actions
     */
    protected array $actions;

    public function __construct(Action ...$actions)
    {
        $this->actions = $actions;
    }

    public function bind(): void
    {
        collect($this->actions)
            ->each(fn ($action) => $action->bind());
    }
}
