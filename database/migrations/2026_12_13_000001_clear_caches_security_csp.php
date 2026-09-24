<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the content-security policy, report-only.
 *
 * A NEW ROUTE. routes/security-csp.php adds `POST /api/csp-report`, which is
 * the address the policy header tells every browser to post a violation to, and
 * the router dispatches against bootstrap/cache/routes-*.php rather than
 * against the source. Without this the endpoint answers 404 on a server whose
 * route table was compiled before the package landed — and a 404 here is
 * silent, because nothing on the shop reads the answer. Every package on this
 * project that adds a route ships one of these; see CLAUDE.md.
 *
 * A NEW MIDDLEWARE REGISTRATION. AppServiceProvider::boot() appends
 * App\Services\Security\CspHeaders to the `web` group, and a compiled
 * bootstrap/cache/services.php is read before the provider's source.
 *
 * ONE CHANGED BLADE, compiled into storage/framework/views and keyed by PATH
 * rather than by contents: the freshness check is a filemtime compare and an
 * unzip's timestamps are not reliably newer than what is already on disk. A
 * stale copy here is a Security screen with no policy card on it.
 *
 * What changed:
 *
 *   app/Services/Security/ContentSecurityPolicy.php
 *                                   the policy itself, written from what this
 *                                   shop actually serves — Google Fonts, the
 *                                   Vite bundle, the three analytics loaders
 *                                   App\Services\Analytics emits, and Stripe's
 *                                   three hosts. The header name is a constant
 *                                   and it is the REPORT-ONLY one.
 *
 *   app/Services/Security/CspHeaders.php
 *                                   puts that header on a storefront HTML
 *                                   response, and nothing else. Registered in
 *                                   AppServiceProvider, not in bootstrap/,
 *                                   because bootstrap/ cannot ship.
 *
 *   app/Services/Security/CspViolations.php
 *   app/Http/Controllers/CspReportController.php
 *   routes/security-csp.php         where a browser posts a violation. Public,
 *                                   because there is no authenticated version
 *                                   of it to build; throttled, allowlisted,
 *                                   clipped, collapsed onto one row per
 *                                   violation per page per window, and bounded
 *                                   by a row ceiling of its own.
 *
 *   app/Services/SecurityModule.php the four policy settings, the policy half
 *                                   of the report, and `no_actor` — a row a
 *                                   stranger's browser caused must never carry
 *                                   the owner's name in the "by" column.
 *
 *   resources/views/admin/partials/security-screen.blade.php
 *                                   Store -> Security grows a "Content
 *                                   security policy" card and a fifth tab.
 *
 * FOUR NEW SETTINGS AND NOT ONE OF THEM MOVES ANYTHING. Stated plainly because
 * the two rounds before this one each had a departure to call out, and this one
 * has none:
 *
 *   `sec_csp_on` ships OFF. This shop sends no content-security-policy header
 *   today, so applying this package changes no response byte anywhere. It is
 *   also off for a reason the screen prints beside the switch: while it is on,
 *   every page view also costs a handful of violation POSTs from the visitor's
 *   browser, because this shop's pages carry 24 inline <script> blocks, 124
 *   inline on* handlers, 29 inline <style> blocks and 210 inline style
 *   attributes, and the policy does not pretend otherwise. That is a real cost
 *   on a shared plan and it is the owner's to spend when he wants the
 *   measurement. docs/LC-SECURITY-MODULE.md carries the list it produces.
 *
 *   `sec_csp_mode` ('report'), `sec_csp_window` (3600s) and `sec_csp_rows`
 *   (200) are new controls for new behaviour and move nothing that existed.
 *
 * `sec_csp_mode` HAS EXACTLY ONE OPTION, the same seam `sec_integrity_action`
 * is, and the stronger half is structural: the string
 * `Content-Security-Policy` — without `-Report-Only` after it — does not occur
 * anywhere under app/, and tests/Feature/SecurityCspTest.php strips comments
 * and searches the tree for it. There is no setting value and no hand-rolled
 * POST that makes this application emit the enforcing header.
 *
 * NOTHING IS BLOCKED BY THIS PACKAGE. Phase 18's order is audit trail and
 * reporting screen -> integrity checking in report-only mode -> CSP
 * report-only -> the request gate in observe mode -> enforcement one rule at a
 * time. This is the third step and only the third step.
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
            echo "Cleared {$cleared} compiled files; Store -> Security grows a content-security\n"
                ."policy card. The policy is REPORT-ONLY and ships switched off, so no response\n"
                ."header changes until you turn it on. It refuses nothing either way.\n";
        }
    }

    public function down(): void {}
};
