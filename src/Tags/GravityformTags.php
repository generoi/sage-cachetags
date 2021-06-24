<?php

namespace Genero\Sage\CacheTags\Tags;

class GravityformTags
{
    /**
     * Return cache tags for one or multiple gravityforms.
     *
     * @see https://docs.gravityforms.com/form-object/
     *
     * @param mixed $forms
     */
    public static function forms($forms = null): array
    {
        if (is_numeric($forms) || isset($forms['id'])) {
            $forms = [$forms];
        }

        if (is_array($forms)) {
            return collect($forms)
                // Pluck the IDs if it's a list of objects.
                ->map(fn ($form) => isset($form['id']) ? $form['id'] : $form)
                ->map(fn ($formId) => ["gform:$formId"])
                ->flatten()
                ->all();
        }

        return [];
    }
}
