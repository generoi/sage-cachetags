<?php

namespace Genero\Sage\CacheTags\Tags;

class PolylangTags
{
    /**
     * Generate archive tags for all languages. Use this when the query
     * fetches posts across all languages (e.g. WP_Query with lang => '').
     *
     * @param  string|string[]  $postTypes
     * @return string[]
     */
    public static function archiveAllLanguages(string|array $postTypes): array
    {
        $postTypes = (array) $postTypes;

        if (! function_exists('pll_languages_list') || ! function_exists('pll_is_translated_post_type')) {
            return CoreTags::archive($postTypes);
        }

        $languages = pll_languages_list();
        $tags = [];

        foreach ($postTypes as $postType) {
            if (pll_is_translated_post_type($postType)) {
                foreach ($languages as $lang) {
                    $tags[] = "archive:{$postType}:{$lang}";
                }
            } else {
                $tags[] = "archive:{$postType}";
            }
        }

        return $tags;
    }

    /**
     * Return the current language as a cache tag.
     *
     * @return string[]
     */
    public static function language(): array
    {
        $lang = function_exists('pll_current_language') ? pll_current_language() : null;

        return $lang ? ["lang:{$lang}"] : [];
    }
}
