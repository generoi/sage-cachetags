<?php

namespace Genero\Sage\CacheTags\Actions;

use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Contracts\Action;

/**
 * Serve cached pages to logged-in customers/subscribers.
 *
 * A logged-in user with no editing/management capability (a WooCommerce
 * `customer`, a `subscriber`, …) typically sees the same catalog/content pages
 * as an anonymous visitor, and WooCommerce hides their admin bar — so they can
 * be served the shared cached page. Per-user surfaces (cart, checkout, account,
 * forms) still bail via the `cachetags/cacheable` veto.
 *
 * Opt-in, because it is only safe when:
 *  - the theme renders no per-user markup server-side (mini-cart, account link,
 *    greeting hydrated client-side), and
 *  - the edge no longer passes (bypasses) these users' login cookie.
 *
 * Enable it alongside Core (and WooCommerce, which keeps cart/checkout/account
 * out of the cache).
 */
class CacheCustomers implements Action
{
    public function __construct(protected CacheTags $cacheTags) {}

    public function bind(): void
    {
        \add_filter('cachetags/cache-logged-in', [$this, 'cacheCustomers']);
    }

    public function cacheCustomers(bool $cacheable): bool
    {
        if ($cacheable) {
            return true;
        }

        return is_user_logged_in()
            && ! current_user_can('edit_posts')
            && ! current_user_can('manage_woocommerce');
    }
}
