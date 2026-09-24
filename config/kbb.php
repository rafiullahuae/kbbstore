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

    /*
     * DEPRECATED — the symmetric scheme Ed25519 replaces.
     *
     * With this set, only packages signed with this exact secret install. That
     * is the right shape while the only person building and applying packages
     * is the owner, and the wrong shape the moment a second install exists:
     * verifying needs the same secret that signs, so every customer would hold
     * in a readable file the key to forge a package this updater accepts.
     *
     * It is unset on every install, and `UpdatePackage::checkSignature()` keeps
     * its branch only so a shop that DID set it behaves the day this lands
     * exactly as it behaved the day before. Nothing should set it again — use
     * `update_public_keys` below. Remove the branch once no install has it.
     */
    'update_secret' => env('KBB_UPDATE_SECRET', ''),

    /*
    |---------------------------------------------------------------------------
    | Package signing — Ed25519
    |---------------------------------------------------------------------------
    |
    | `update_signing` is the policy:
    |
    |   permissive  a signature that is present is verified and a bad one is
    |               refused; a package with no signature is accepted.
    |   required    a package with no signature is refused too.
    |
    | PERMISSIVE HERE IS DELIBERATE AND IS WHAT MAKES THIS PATCH INERT. Every
    | package this project has built carries `"signature": ""`, so exactly the
    | same packages install the day before and the day after. Moving to
    | `required` is a separate, later, numbered step — and it must not be taken
    | until a SIGNED package has already been applied successfully, because that
    | apply is the only proof the shop's public key matches the build machine's
    | private one. docs/PACKAGE-SIGNING.md is the sequence; do not improvise it.
    |
    | Anything other than the two words above resolves to permissive, in
    | SigningMode::current(). A typo must not be able to lock a shop out of the
    | updater that would fix the typo.
    |
    | `update_public_keys` is base64 Ed25519 PUBLIC keys — 32 bytes each, safe
    | to read, safe to ship, safe to publish. The private half lives on the
    | build machine and nowhere else; `php artisan kbb:signing-key` makes the
    | pair and prints the line to paste here. A list, so a key can be rotated by
    | trusting both for one release. EMPTY until the owner generates his: an
    | empty list trusts nothing, so a signed package is REFUSED rather than
    | waved through, and an unsigned one is accepted exactly as today.
    |
    | KBB_UPDATE_PUBLIC_KEYS (comma-separated) is additive and is a convenience
    | for a staging box, not the channel that matters: .env is not read at all
    | while the config cache exists, so the shipped list below is what a shop
    | can be relied on to hold.
    |
    */
    'update_signing' => env('KBB_UPDATE_SIGNING', 'permissive'),

    'update_public_keys' => array_values(array_filter(array_merge(
        [
            // Paste the output of `php artisan kbb:signing-key` here, one
            // base64 public key per line, and commit it. Nothing secret is in
            // this list by construction.
        ],
        array_map('trim', explode(',', (string) env('KBB_UPDATE_PUBLIC_KEYS', '')))
    ))),

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
