<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * Clear the compiled caches for the restore-basket button. (Lane PLC, round 2)
 *
 * ── WHAT IS STALE IN THIS PACKAGE ─────────────────────────────────────────
 *
 *   A SECOND ROUTE. routes/checkout-return.php now also declares
 *   POST /checkout/restore-basket — the "Put my basket back" button on the
 *   basket page. The file is already required from routes/web.php, so nothing
 *   needs wiring; what is stale is the COMPILED ROUTE CACHE, which holds the
 *   version of that file with one route in it. Without this the button posts to
 *   an address the shop answers with 405, and the only symptom is a shopper
 *   whose basket does not come back.
 *
 *   AN EDITED BLADE. partials/checkout/return-notice draws the button and the
 *   "your basket is back" line. On this host the compiled views outlive the
 *   files they came from, and a cached copy of that partial is a copy with no
 *   button in it.
 *
 *   NO STYLESHEET MOVED. The partial carries its own scoped <style>, inside the
 *   @if, so a basket page with nothing to say still ships not one byte of it.
 *
 * ▲ AND `migrations` IN update.json IS WHAT DECIDES WHETHER THIS RUNS AT ALL.
 *   UpdateRunner::hasMigrations() reads that flag and never looks at the files.
 *   Build the package with `php artisan kbb:package <version> --since=<ref>`.
 *
 * The guard wraps a call that writes no model state, so nothing is left dirty
 * for a later save to re-send — the distinction CLAUDE.md's swallowed-exception
 * landmine turns on.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['view:clear', 'config:clear', 'route:clear'] as $command) {
            try {
                Artisan::call($command);
            } catch (\Throwable) {
                // A cache that cannot be cleared is one the next deploy clears.
            }
        }
    }

    public function down(): void
    {
        // Nothing to undo: this migration has no schema and no data.
    }
};
