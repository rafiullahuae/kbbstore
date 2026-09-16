<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the Users & Roles and Theme screens — Lane DJ.
 *
 * ONE BLADE FILE CHANGED, and it is the console: admin/app.blade.php. Three
 * regions of it:
 *
 *   1. The fabricated Users table in the first <script> block is gone. It drew
 *      "Rafi / Owner / 2FA On", "Store Manager" and "Support Agent / Invited"
 *      from string literals and offered an Invite button that only raised a
 *      toast. It was dead — the real screen is assigned later, in the second
 *      block — but a runtime error anywhere in that second block before the
 *      assignment left it standing, and the owner then read three invented
 *      colleagues and a second factor this application does not have.
 *
 *   2. The real Users screen no longer turns a refusal into an empty list. A
 *      manager opening it used to be told the shop has no staff accounts at
 *      all; /admin-api/users is users.manage, which is owner-only, so what he
 *      actually got was a 403 that the catch swallowed. It prints the sentence
 *      EnforceAdminCapability sends instead.
 *
 *   3. The Theme screen's eight cards no longer promise a Phase 2 builder for
 *      seven screens that already exist. They are links now, and the three
 *      subjects with nothing behind them say so.
 *
 * WITHOUT THIS THE PACKAGE LANDS AND NOTHING ON SCREEN CHANGES. The server
 * renders Blade from storage/framework/views, so the old compiled console goes
 * on being served from a file that is no longer on disk. The symptom would be
 * the exact defect this package removes: the owner applies an update told to
 * fix the staff list, opens Users & Roles, and reads the same three invented
 * names — which is the report most likely to come back as "the update did not
 * work".
 *
 * The console is ONE compiled view: app.blade.php pulls its partials in with
 * @include, so the parent's cached compile carries all of them. This change is
 * in app.blade.php itself, so the parent is what has to go; the views are
 * cleared wholesale anyway.
 *
 * NO ROUTE CHANGED AND NO SCHEMA CHANGED. The four /admin-api/users endpoints
 * this screen calls were already registered and already mapped to users.manage
 * in AdminCapabilities — this lane added no route and no capability, because
 * both already existed. AdminController::users() changed only in that it now
 * names the five columns it reads rather than selecting the whole row, so the
 * password hash and remember_token are never loaded; the response shape is
 * identical. The route and package caches are dropped with the views because
 * the cost is nil and a half-cleared cache is harder to reason about than an
 * empty one.
 *
 * OPcache is dropped because AdminController.php did change in this package,
 * so unlike a views-only clear this one is load-bearing for the PHP side.
 *
 * Nothing here positions a column with an AFTER clause, the thing that made
 * nine earlier migrations silent no-ops on MySQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cleared = 0;

        foreach ([
            storage_path('framework/views/*.php'),
            base_path('bootstrap/cache/config.php'),
            base_path('bootstrap/cache/routes-*.php'),
            base_path('bootstrap/cache/services.php'),
            base_path('bootstrap/cache/packages.php'),
        ] as $pattern) {
            foreach (glob($pattern) ?: [] as $file) {
                if (is_file($file) && @unlink($file)) {
                    $cleared++;
                }
            }
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        if (app()->runningInConsole()) {
            echo "Cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void {}
};
