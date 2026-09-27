<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the content page editor. (Lane S9)
 *
 * FOUR NEW ROUTES. `GET /admin-api/page-editor-bootstrap`, `GET
 * /admin-api/page-editor-list`, `GET /admin-api/page-editor-load/{id}` and
 * `POST /admin-api/page-editor-save/{id}` arrive in
 * routes/page-editor-admin.php. The router dispatches against
 * bootstrap/cache/routes-*.php, so until that file is gone every one of them
 * answers 404 — and the failure is the quiet kind this project has paid for
 * before: the editor renders in full, the owner rewrites the returns policy and
 * types its Arabic, and Save 404s having thrown all of it away.
 *
 * A NEW ADMIN VIEW PARTIAL. resources/views/admin/partials/page-editor-screen.blade.php
 * is @included by admin/app.blade.php. A compiled Blade view is only recompiled
 * when the file it came from is newer than it, and an update package copies files
 * with whatever timestamps the archive carries — so the compiled copy can win and
 * Pages → User pages would go on drawing the read-only table with the
 * "Page editor arrives with the CMS in Phase 11" button over a console that has
 * the real screen in it. Clearing storage/framework/views is why this migration
 * globs it.
 *
 * NOTHING IS PUBLISHED AND NOTHING MOVES. This package adds no page, changes no
 * page and writes no setting. `pages` is left exactly as it was found: every one
 * of the seven content pages keeps its title, its body, its status and its
 * address, `seo` stays null on every row, and the storefront renders
 * byte-for-byte what it rendered before — StorefrontEnglishUnchangedTest is the
 * instrument that says so. The sanitiser the editor applies runs on the way IN,
 * so a page nobody re-saves is untouched.
 *
 * The only visible change is in the admin: an Edit button on a screen that
 * already listed the pages, and a form behind it.
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
            echo "Cleared {$cleared} compiled files. Pages -> User pages can now EDIT a\n"
                ."content page: its title, its body, its Arabic, its status, and the five search-engine\n"
                ."fields (page title, meta description, canonical, social image, noindex) with a live\n"
                ."Google preview above them. No page is created or changed by applying this.\n";
        }
    }

    public function down(): void {}
};
