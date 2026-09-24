<?php

return [

    /*
    |---------------------------------------------------------------------------
    | Base path
    |---------------------------------------------------------------------------
    |
    | Empty in production, where the store owns the domain root and every URL in
    | the URL Contract must resolve exactly as it does today (D-43).
    |
    | On staging the app lives in a subdirectory — easywebsol.com/kbb-upgrade/ —
    | so every internal link needs that prefix. Setting it here rather than
    | hard-coding paths means the same build runs in both places, and going live
    | is a one-line .env change rather than a search-and-replace through Blade.
    |
    | Set KBB_BASE_PATH=/kbb-upgrade on staging. Leave it unset in production.
    |
    */
    'base_path' => rtrim((string) env('KBB_BASE_PATH', ''), '/'),

    /*
    |---------------------------------------------------------------------------
    | Media root
    |---------------------------------------------------------------------------
    |
    | Kept as /wp-content/uploads so no image URL shared on Facebook, Instagram,
    | WhatsApp or in a past email ever breaks (D-45). Looks odd in a Laravel app;
    | that is the point.
    |
    */
    'media_root' => '/wp-content/uploads',

    /*
    |---------------------------------------------------------------------------
    | Updater
    |---------------------------------------------------------------------------
    */

    // Bumped by each update package. Stops updates being applied out of order.
    'version' => env('KBB_VERSION', '1.0.0'),

    // With this set, only packages signed with this exact secret will install —
    // so even someone with an admin password cannot push arbitrary PHP through
    // the update screen. Leave empty to accept unsigned packages (not advised).
    'update_secret' => env('KBB_UPDATE_SECRET', ''),

    // The updater calls /_kbb-health?token=... over HTTP after writing files, to
    // confirm the site still boots. Without it, automatic rollback loses its
    // main trigger.
    'health_token' => env('KBB_HEALTH_TOKEN', ''),

    /*
     * Whether that check RENDERS the storefront or only pings the database.
     *
     * On, and on deliberately -- this is the one setting in this patch that
     * does not ship at the value the server already behaves at. Until
     * 2.60.266 the check ran `SELECT 1` and nothing else, which is why
     * 2.60.260 was kept while it was 500ing every product page. Leaving the
     * new behaviour off by default would have shipped the fix and not the
     * protection.
     *
     * The switch exists because the check now decides whether an update is
     * kept, and there is one situation where that is the wrong trade: a shop
     * already broken for an unrelated reason cannot install the package that
     * repairs it, because the health check fails on damage the package was
     * never responsible for. Set KBB_HEALTH_DEEP=false in .env over SSH, apply
     * the package, set it back. It is an escape hatch for an emergency, not a
     * setting to leave off.
     */
    'health_deep' => (bool) env('KBB_HEALTH_DEEP', true),

];
