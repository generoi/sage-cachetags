<?php

namespace Genero\Sage\CacheTags\Tags;

use Genero\Sage\CacheTags\Util;

class GravityformTags
{
    /**
     * Return cache tags for one or multiple gravityforms.
     *
     * @see https://docs.gravityforms.com/form-object/
     *
     * @param  mixed  $forms
     * @return string[]
     */
    public static function forms($forms = null): array
    {
        if (is_numeric($forms) || isset($forms['id'])) {
            $forms = [$forms];
        }

        if (is_array($forms)) {
            $tags = array_map(
                fn ($form) => [sprintf('gform:%d', isset($form['id']) ? $form['id'] : $form)],
                $forms
            );

            return Util::flatten($tags);
        }

        return [];
    }
}
