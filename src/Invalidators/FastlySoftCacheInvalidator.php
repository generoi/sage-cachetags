<?php

namespace Genero\Sage\CacheTags\Invalidators;

class FastlySoftCacheInvalidator extends FastlyCacheInvalidator
{
    protected function buildRequest($payload = null): array
    {
        $args = parent::buildRequest($payload);
        $args['headers']['fastly-soft-purge'] = '1';
        return $args;
    }
}
