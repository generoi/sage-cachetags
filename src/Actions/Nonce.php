<?php

namespace Genero\Sage\CacheTags\Actions;

use Genero\Sage\CacheTags\CacheTags;
use Genero\Sage\CacheTags\Contracts\Action;
use Genero\Sage\CacheTags\NonceCron;

/**
 * Schedule the WP-Cron job that purges `nonce`-tagged pages every 12 hours, so a
 * cached page never serves an expired nonce (a form submit, AJAX action or
 * add-to-cart that then fails for everyone served that page).
 *
 * Enabled by default in the shipped config — it's a light twice-daily cron, and
 * any page tagged `nonce` (the Gravityform action does this for file-upload forms,
 * but a theme can tag its own) needs it without having to wire a cron by hand.
 * Remove it from the action set to opt out.
 */
class Nonce implements Action
{
    public function __construct(protected CacheTags $cacheTags) {}

    public function bind(): void
    {
        NonceCron::register();
    }
}
