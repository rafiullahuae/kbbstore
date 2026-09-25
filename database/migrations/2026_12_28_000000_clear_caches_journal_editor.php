<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the Journal article editor. (Lane J)
 *
 * FIVE NEW ROUTES. `GET /admin-api/post-editor-bootstrap`, `GET
 * /admin-api/post-editor-load/{id}`, `POST /admin-api/post-editor-slug`, `POST
 * /admin-api/post-editor-create` and `POST /admin-api/post-editor-save/{id}`
 * arrive in routes/post-editor-admin.php. The router dispatches against
 * bootstrap/cache/routes-*.php, so until that file is gone every one of them
 * answers 404 — and the failure is the quiet kind this project has paid for
 * before: the editor renders in full, the owner writes an article, sets its
 * cover and types its Arabic, and Save 404s having thrown all of it away.
 *
 * A NEW ADMIN VIEW PARTIAL. resources/views/admin/partials/post-editor-screen.blade.php
 * is @included by admin/app.blade.php, and app.blade.php itself changes. A
 * compiled Blade view is only recompiled when the file it came from is newer
 * than it, and an update package copies files with whatever timestamps the
 * archive carries — so the compiled copy can win and Content → Blog Posts
 * would go on drawing the old read-only table over a console that has the new
 * screen in it. Clearing storage/framework/views is why this migration globs it.
 *
 * NOTHING IS PUBLISHED AND NOTHING MOVES. This package adds no article, no
 * setting and no row. `posts` is left exactly as it was found, every existing
 * article keeps its address, its body and its status, and the only visible
 * change is a "New article" button and an "Edit" button on a screen that
 * already listed them. The one storefront change that ships with it — a
 * CollectionPage node in the <head> of /skincare-guide/ — is markup a crawler
 * reads and a shopper cannot see; the rendered page is otherwise byte-identical
 * and StorefrontEnglishUnchangedTest is the instrument that says so.
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
            echo "Cleared {$cleared} compiled files. Content -> Blog Posts can now write an\n"
                ."article, not just list one: New article, Edit, the Arabic boxes beside every\n"
                ."field, and an address that is checked against the storefront's own routes as\n"
                ."you type. No article is created by applying this.\n";
        }
    }

    public function down(): void {}
};
