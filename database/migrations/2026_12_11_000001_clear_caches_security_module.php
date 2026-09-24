<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the security module's report screen.
 *
 * A NEW ROUTE FILE. routes/security-admin.php is required from routes/web.php
 * by the integrator, and the router dispatches against
 * bootstrap/cache/routes-*.php rather than against the source. Without this,
 * Store → Security draws a screen whose every request answers 404 — which the
 * screen says out loud rather than drawing an empty panel, but which is still
 * a dead screen.
 *
 * ONE CHANGED BLADE, compiled into storage/framework/views and keyed by PATH
 * rather than by contents: the freshness check is a filemtime compare, and an
 * unzip's timestamps are not reliably newer than what is already on disk. A
 * stale copy here is an admin console with no Security row in its sidebar.
 *
 * What changed:
 *
 *   database/migrations/2026_12_11_000000_create_audit_events.php
 *                                   the `audit_events` table: actor, IP, what
 *                                   changed, and the before and after.
 *
 *   app/Models/AuditEvent.php       the row. No toApi() and no API surface,
 *                                   deliberately — every row names an
 *                                   operator's email, their role and an
 *                                   address.
 *
 *   app/Services/SecurityModule.php the schema, the hooks and the report. It
 *                                   registers model events, two auth events
 *                                   and one listener on RequestHandled. NO
 *                                   MIDDLEWARE: Phase 18's order is report
 *                                   before enforce, and nothing in this
 *                                   package can refuse, delay or alter a
 *                                   request.
 *
 *   app/Providers/AppServiceProvider.php
 *                                   one line, SecurityModule::listen(), beside
 *                                   the two ::listen() calls already there.
 *                                   In a provider and not in bootstrap/app.php
 *                                   because bootstrap/ is on
 *                                   BuildPackage::NEVER_SHIP and can never
 *                                   reach the live server in a package.
 *
 *   app/Http/Controllers/Admin/SecurityController.php
 *   routes/security-admin.php
 *   app/Support/AdminCapabilities.php
 *   resources/views/admin/partials/security-screen.blade.php
 *   resources/views/admin/app.blade.php
 *                                   Store → Security, behind the new
 *                                   owner-only `security.view` capability.
 *
 *   app/Http/Controllers/Admin/AdminAuthController.php
 *                                   one call, where the login throttle already
 *                                   decided to turn somebody away — that
 *                                   throttle answers 302, not 429, so nothing
 *                                   else can see it. The behaviour on either
 *                                   side of the call is unchanged.
 *
 * THREE NEW SETTINGS SHIP ON: `sec_audit_on`, `sec_signin_on` and `sec_rl_on`.
 * That is the one deliberate departure from "a new setting ships at the value
 * the page already has", and it is called out here rather than buried: the
 * owner asked for the trail in as many words (Phase 18, items 6 and 7), and a
 * trail that ships off records nothing until somebody finds the switch — which
 * makes the first incident after this package as unreconstructable as the last
 * one. It refuses no request, changes no storefront page and moves no existing
 * setting; it writes rows to a new table that only the new screen reads.
 *
 * NO SETTING ROWS ARE WRITTEN by this migration. SecurityModule carries every
 * default, and an absent row and a row holding the default are the same thing.
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
            echo "Cleared {$cleared} compiled files; the shop now keeps an administrative\n"
                ."audit trail and reports on it under Store -> Security. Nothing is blocked.\n";
        }
    }

    public function down(): void {}
};
