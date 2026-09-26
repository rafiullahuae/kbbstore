<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the SEO back office — Lane S7.
 *
 * A NEW ROUTE FILE. routes/seo-back-office.php is required from routes/web.php
 * by the integrator, and the router dispatches against bootstrap/cache/routes-*.php
 * rather than against the source. Without this, every preview on four screens
 * answers 404 — the snippet draws "Could not draw the preview" and the Overview
 * tab says it could not read the shop, which is at least honest and is still
 * four dead screens.
 *
 * FIVE CHANGED BLADES, compiled into storage/framework/views and keyed by PATH
 * rather than by contents: the freshness check is a filemtime compare, and an
 * unzip's timestamps are not reliably newer than what is already on disk. A
 * stale copy here is an admin console with no Overview tab and no preview
 * anywhere, from a package that reported success.
 *
 * What changed:
 *
 *   app/Http/Controllers/Admin/SeoPreviewApiController.php
 *                                   "What will Google see for this page?",
 *                                   answered by App\Support\Seo — the class the
 *                                   page itself renders with — and by reading
 *                                   the live page for a box the owner has left
 *                                   empty. Writes nothing.
 *   app/Http/Controllers/Admin/SeoTasksApiController.php
 *                                   what is waiting on the owner, computed from
 *                                   the shop's state, plus App\Support\SeoAudit's
 *                                   findings ranked by what each one costs.
 *                                   Reads only.
 *   routes/seo-back-office.php      the two routes.
 *   app/Support/AdminCapabilities.php
 *                                   one rule each: store.settings for the
 *                                   preview (the capability that already guards
 *                                   the endpoint which SAVES what it previews),
 *                                   system.diagnostics for the overview (the
 *                                   capability that already guards the audit it
 *                                   re-presents).
 *
 *   resources/views/admin/partials/seo-back-office.blade.php
 *                                   window.kbbSeoPreview() and the Overview tab.
 *   resources/views/admin/app.blade.php
 *                                   the Overview subtab, its dispatch line, the
 *                                   include, and the homepage preview's mount.
 *   resources/views/admin/partials/category-tree-screen.blade.php
 *   resources/views/admin/partials/brands-editor-screen.blade.php
 *   resources/views/admin/partials/post-editor-screen.blade.php
 *                                   one mount point and one guarded call each.
 *
 * ── NOTHING ON THE STOREFRONT MOVES, AND NO SETTING CHANGES ─────────────────
 *
 * No storefront route, no template, no setting, no default and no module toggle.
 * Not one field on any of the four screens changes what it stores: the whole of
 * what is added to those screens is a read-only picture of a value somebody else
 * saves. SeoBackOfficePayloadTest replays the payload of all three endpoints
 * behind them, recorded off the parent revision BEFORE a line of this was
 * written, and compares the whole decoded body outright; SeoRowPreviewScreenTest
 * pins each editor's save payload id for id.
 *
 * NO SETTING ROWS ARE WRITTEN by this migration, and no setting is added
 * anywhere: there is nothing on these screens for a slider to move.
 *
 * ONE VISIBLE CHANGE, NAMED RATHER THAN BURIED: Store → SEO & Meta gains a sixth
 * subtab, "Overview", FIRST in the strip. It is NOT the tab the screen opens on —
 * `seoTab` still defaults to 'settings', so clicking SEO & Meta shows what it
 * showed yesterday. Making Overview the landing tab is a one-word change and it
 * is the owner's to ask for.
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
            echo "Cleared {$cleared} compiled files; every SEO title and description box\n"
                ."now shows what Google will print, before you save -- on Catalog -> Categories,\n"
                ."Catalog -> Brands, Content -> Blog Posts and Store -> SEO & Meta. A new\n"
                ."Overview tab on Store -> SEO & Meta lists what is waiting on you. No setting\n"
                ."changed and nothing on the storefront moved.\n";
        }
    }

    public function down(): void {}
};
