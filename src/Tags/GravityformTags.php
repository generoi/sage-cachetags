<?php

namespace Genero\Sage\CacheTags\Tags;

use Genero\Sage\CacheTags\Tag;

class GravityformTags
{
    /**
     * Return cache tags for one or multiple gravityforms.
     *
     * @see https://docs.gravityforms.com/form-object/
     *
     * @param  mixed  $forms
     * @return Tag[]
     */
    public static function forms($forms = null): array
    {
        if (is_numeric($forms) || isset($forms['id'])) {
            $forms = [$forms];
        }

        if (is_array($forms)) {
            return array_map(
                fn ($form) => Tag::form((int) (isset($form['id']) ? $form['id'] : $form)),
                $forms
            );
        }

        return [];
    }
}
