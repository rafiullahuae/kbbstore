<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear the compiled caches for the background import — Lane GO.
 *
 * FIVE NEW ROUTES SHIP WITH THIS PACKAGE:
 *
 *     GET  /admin-api/import/background           the bars and the chain state
 *     GET  /admin-api/import/background-page      the live page
 *     POST /admin-api/import/background           carry this run on without a browser
 *     POST /admin-api/import/background-control   pause | resume | stop
 *     POST /import-chain/continue                 the loopback call itself
 *
 * The route table is compiled on the server and the host has no shell, so a
 * route added by an update package does not exist until
 * `bootstrap/cache/routes-*.php` is gone. CLAUDE.md makes the pairing a
 * convention; here the consequence of forgetting it is worse than a 404 on a
 * button.
 *
 * THE LAST ROUTE IN THAT LIST IS WHY THIS FILE MATTERS MORE THAN USUAL. The
 * background run works by making the shop call itself at /import-chain/continue.
 * On a server with a stale compiled route table that path 404s — and a 404 is
 * exactly what ImportChain::kick() is told to read as "this host will not let
 * the shop call itself". The owner would be shown a sentence saying his hosting
 * cannot do this, on hosting that can, and no amount of pressing the button
 * would change it. That is why the sentence kick() produces ends by naming this
 * migration's job in words: if the shop was updated a moment ago, the compiled
 * route table may still be the old one.
 *
 * THE COMPILED VIEWS MATTER TOO. This lane ships a Blade file of its own,
 * resources/views/admin/import-background.blade.php, and compiled Blade is keyed
 * by path with a filemtime comparison that an unzip does not reliably win. A
 * stale compiled view here is a blank page where the progress is supposed to be.
 *
 * `config.php`, `services.php` and `packages.php` go too, for the reason every
 * clear_caches migration here gives: a compiled config left beside a cleared
 * route cache is the file most likely to be stale for an unrelated reason, and
 * the cost is one rebuild on the next request.
 *
 * THE SCHEMA CHANGE IS IN ITS OWN MIGRATION and lands before this one —
 * 2026_11_25_000000_add_import_background_chain.php. Two jobs, two files, in the
 * order the timestamps give: a route that answers before its columns exist is a
 * 500 on the first press.
 *
 * Best-effort, like every clear_caches migration here: a file that cannot be
 * unlinked mid-update must not fail the package and strand the site
 * half-updated. A stale cache is a visible bug; a failed migration is an outage.
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
            echo "Cleared {$cleared} compiled files; the import can now keep going with the tab closed,\n";
            echo "and the shop can reach its own /import-chain/continue to do it.\n";
        }
    }

    public function down(): void {}
};
