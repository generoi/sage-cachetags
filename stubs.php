<?php

/**
 * Stub file for Intelephense to recognize WordPress constants.
 * These constants are defined in wp-config.php or environment configuration.
 */
if (! defined('WP_DEBUG')) {
    define('WP_DEBUG', false);
}

if (! defined('KINSTAMU_DISABLE_AUTOPURGE')) {
    define('KINSTAMU_DISABLE_AUTOPURGE', false);
}

if (! defined('KINSTAMU_CACHE_PURGE_TIMEOUT')) {
    define('KINSTAMU_CACHE_PURGE_TIMEOUT', 5);
}

if (! function_exists('wpsc_delete_url_cache')) {
    function wpsc_delete_url_cache($url)
    {
        return true;
    }
}

if (! function_exists('wpsc_delete_files')) {
    function wpsc_delete_files($dir)
    {
        return true;
    }
}

if (! function_exists('get_supercache_dir')) {
    function get_supercache_dir()
    {
        return true;
    }
}

if (! function_exists('sg_cachepress_purge_cache')) {
    function sg_cachepress_purge_cache($url = false)
    {
        return true;
    }
}

if (! function_exists('rocket_clean_files')) {
    /**
     * @param  string|string[]  $urls
     */
    function rocket_clean_files($urls, $filesystem = null, $run_actions = true)
    {
        return true;
    }
}

if (! function_exists('rocket_clean_domain')) {
    function rocket_clean_domain($lang = '')
    {
        return true;
    }
}

if (! function_exists('env')) {
    function env($key)
    {
        return false;
    }
}
